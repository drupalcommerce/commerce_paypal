<?php

/**
 * @file
 * Checkout pane callback functions for the PayPal Express Checkout module.
 */


/**
 * Checkout pane callback: returns a pane allowing the customer to review the
 * final details of the order and provide any final information required.
 */
function commerce_paypal_ec_review_pane_checkout_form($form, &$form_state, $checkout_pane, $order) {
  $pane_form = array();

  // Otherwise add any enabled checkout panes that are visible on review.
  $pane_form['review'] = array(
    '#theme' => 'commerce_checkout_review',
    '#data' => array(),
  );

  // Loop through all the pages before the review page...
  foreach (commerce_checkout_pages() as $page_id => $checkout_page) {
    // Exit the loop once the review page is reached.
    if ($page_id == 'review') {
      break;
    }

    // Loop through all the panes on the current page specifying review...
    foreach (commerce_checkout_panes(array('page' => $page_id, 'enabled' => TRUE, 'review' => TRUE)) as $pane_id => $checkout_pane_local) {
      // If the pane has a valid review callback...
      if ($callback = commerce_checkout_pane_callback($checkout_pane_local, 'review')) {
        // Get the review data for this pane.
        $pane_data = $callback($form, $form_state, $checkout_pane_local, $order);

        // Only display the pane if there is data in the pane.
        if (!empty($pane_data)) {
          // Add a row for it in the review data.
          $pane_form['review']['#data'][$pane_id] = array(
            'title' => $checkout_pane_local['title'],
            'data' => $pane_data,
          );
        }
      }
    }
  }

  $pane_form['pay_now'] = array(
    '#type' => 'submit',
    '#value' => t('Pay Now'),
    '#validate' => array('commerce_paypal_ec_review_validate'),
    '#submit' => array('commerce_paypal_ec_review_submit'),
  );

  return $pane_form;
}

/**
 * Form callback: validates information entered on the EC review page.
 */
function commerce_paypal_ec_review_validate($form, &$form_state) {

}

/**
 * Form callback: submits information entered on the EC review page.
 */
function commerce_paypal_ec_review_submit($form, &$form_state) {
  $order = $form_state['order'];
  $order_wrapper = entity_metadata_wrapper('commerce_order', $order);

  $payment_method = commerce_payment_method_instance_load($order->data['payment_method']);
  $charge = $order_wrapper->commerce_order_total->value();

  // Attempt to process the payment.
  if (commerce_paypal_ec_do_payment($payment_method, $order, $charge)) {
    // Proceed to the next page if it succeeded.
    $order_status = commerce_order_status_load($order->status);
    $checkout_page = commerce_checkout_page_load($order_status['checkout_page']);
    $next_page = $checkout_page['next_page'];

    // Update the order status to the next checkout page.
    $order = commerce_order_status_update($order, 'checkout_' . $next_page, FALSE, FALSE);

    // Inform modules of checkout completion if the next page is Completed.
    if ($next_page == 'complete') {
      commerce_checkout_complete($order);
    }

    // Redirect to the URL for the new checkout page.
    $form_state['redirect'] = commerce_checkout_order_uri($order);
  }
}
