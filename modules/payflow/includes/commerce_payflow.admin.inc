<?php

/**
 * @file
 * Administrative forms for the Payflow Link module.
 */


/**
 * Form callback: allows the user to capture a prior authorization.
 */
function commerce_payflow_link_capture_form($form, &$form_state, $order, $transaction) {
  $form_state['order'] = $order;
  $form_state['transaction'] = $transaction;

  // Load and store the payment method instance for this transaction.
  $payment_method = commerce_payment_method_instance_load($transaction->instance_id);
  $form_state['payment_method'] = $payment_method;

  $balance = commerce_payment_order_balance($order);

  // Convert the balance to the transaction currency.
  if ($balance['currency_code'] != $transaction->currency_code) {
    $balance['amount'] = commerce_currency_convert($balance['amount'], $balance['currency_code'], $transaction->currency_code);
    $balance['currency_code'] = $transaction->currency_code;
  }

  if ($balance['amount'] > 0 && $balance['amount'] < $transaction->amount) {
    $default_amount = $balance['amount'];
  }
  else {
    $default_amount = $transaction->amount;
  }

  // Convert the price amount to a user friendly decimal value.
  $default_amount = commerce_currency_amount_to_decimal($default_amount, $transaction->currency_code);

  $description = implode('<br />', array(
    t('Authorization: @amount', array('@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code))),
    t('Order balance: @balance', array('@balance' => commerce_currency_format($balance['amount'], $balance['currency_code']))),
  ));

  $form['amount'] = array(
    '#type' => 'textfield',
    '#title' => t('Capture amount'),
    '#description' => $description,
    '#default_value' => $default_amount,
    '#field_suffix' => check_plain($transaction->currency_code),
    '#size' => 16,
  );

  $form = confirm_form($form,
    t('What amount do you want to capture?'),
    'admin/commerce/orders/' . $order->order_id . '/payment',
    '',
    t('Capture'),
    t('Cancel'),
    'confirm'
  );

  return $form;
}

/**
 * Validate handler: ensure a valid amount is given.
 */
function commerce_payflow_link_capture_form_validate($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $amount = $form_state['values']['amount'];

  // Ensure a positive numeric amount has been entered for capture.
  if (!is_numeric($amount) || $amount <= 0) {
    form_set_error('amount', t('You must specify a positive numeric amount to capture.'));
  }

  // Ensure the amount is within the allowed limit for PayPal authorizations.
  $authorization_amount = commerce_currency_amount_to_decimal($transaction->amount, $transaction->currency_code);
  $authorization_amount_upper = commerce_currency_amount_to_decimal($transaction->amount + 75, $transaction->currency_code);

  if ($amount > $authorization_amount * 1.15 || $amount > $authorization_amount_upper) {
    form_set_error('amount', t('You cannot capture an amount $75 or 115% greater than the authorization amount in PayPal WPP.'));
  }

  // If the authorization has expired, display an error message and redirect.
  if (REQUEST_TIME - $transaction->created > 86400 * 29) {
    drupal_set_message(t('This authorization has passed its 29 day limit cannot be captured.'), 'error');
    drupal_goto('admin/commerce/orders/' . $form_state['order']->order_id . '/payment');
  }
}

/**
 * Submit handler: process a prior authorization capture via Payflow Pro.
 */
function commerce_payflow_link_capture_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $amount = $form_state['values']['amount'];

  $order = $form_state['order'];
  $payment_method = $form_state['payment_method'];

  // Prepare a name-value pair array to capture the requested amount.
  $nvp = array(
    'TRXTYPE' => 'D',
    'ORIGID' => $transaction->remote_id,
    'AMT' => $amount,
    'CAPTURECOMPLETE' => 'Y',
  );

  // Submit the capture request request to Payflow Pro.
  $response = commerce_payflow_api_request($payment_method, 'pro', $nvp, $order);
  $transaction->payload[REQUEST_TIME . '-capture'] = $response;

  switch (intval($response['RESULT'])) {
    case 0:
      drupal_set_message(t('Prior authorization captured successfully.'));

      // Update the original transaction amount to the actual capture amount,
      // its remote ID to the capture's transaction ID, and its statuses to
      // indicate successful payment.
      $transaction->amount = commerce_currency_decimal_to_amount($amount, $transaction->currency_code);
      $transaction->remote_id = $response['PNREF'];
      $transaction->status = COMMERCE_PAYMENT_STATUS_SUCCESS;
      $transaction->remote_status = 'D';

      // Note the capture in the transaction message.
      $transaction->message .= '<br />' . t('Captured: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      break;

    default:
      // Display an error message but leave the transaction pending.
      drupal_set_message(t('Prior authorization capture failed, so the transaction will remain in a pending status.'), 'error');
      drupal_set_message(check_plain($response['RESPMESG']), 'error');
      break;
  }

  // Save the updated original transaction.
  commerce_payment_transaction_save($transaction);

  // Redirect back to the current order payment page.
  $form_state['redirect'] = 'admin/commerce/orders/' . $form_state['order']->order_id . '/payment';
}

/**
 * Form callback: allows the user to void a transaction.
 */
function commerce_payflow_link_void_form($form, &$form_state, $order, $transaction) {
  $form_state['order'] = $order;
  $form_state['transaction'] = $transaction;

  // Load and store the payment method instance for this transaction.
  $payment_method = commerce_payment_method_instance_load($transaction->instance_id);
  $form_state['payment_method'] = $payment_method;

  $form['markup'] = array(
    '#markup' => t('Are you sure that you want to void this transaction?'),
  );

  $form = confirm_form($form,
    t('Are you sure that you want to void this transaction?'),
    'admin/commerce/orders/' . $order->order_id . '/payment',
    '',
    t('Void'),
    t('Cancel'),
    'confirm'
  );

  return $form;
}

/**
 * Submit handler: process the void request.
 */
function commerce_payflow_link_void_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];

  // Build a name-value pair array for this transaction.
  $nvp = array(
    'TRXTYPE' => 'V',
    'ORIGID' => $transaction->remote_id,
  );

  // Submit the request to Payflow Pro.
  $response = commerce_payflow_api_request($form_state['payment_method'], 'pro', $nvp, $form_state['order']);

  // Update and save the transaction based on the response.
  $transaction->payload[REQUEST_TIME . '-void'] = $response;

  // If we got an approval response code...
  if (intval($response['RESULT']) === 0) {
    drupal_set_message(t('Transaction successfully voided.'));

    // Set the remote and local status accordingly.
    $transaction->remote_id = $response['PNREF'];
    $transaction->status = COMMERCE_PAYMENT_STATUS_FAILURE;
    $transaction->remote_status = 'V';

    // Update the transaction message to show that it has been voided.
    $transaction->message .= '<br />' . t('Voided: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
  }
  else {
    drupal_set_message(t('Prior authorization capture failed, so the transaction will remain in a pending status.'), 'error');
    drupal_set_message(check_plain($response['RESPMESG']), 'error');
  }

  commerce_payment_transaction_save($transaction);

  $form_state['redirect'] = 'admin/commerce/orders/' . $form_state['order']->order_id . '/payment';
}
