<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;

/**
 * Provides the Onsite payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_payments_standard",
 *   label = "PayPal Standard",
 *   display_label = "PayPal",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_paypal\PluginForm\PaymentsStandardPaymentForm",
 *   },
 *   payment_method_types = {"paypal"},
 * )
 */
class PaymentsStandard extends OffsitePaymentGatewayBase implements PaymentsStandardInterface {

  /**
   * {@inheritdoc}
   */
  public function getRedirectUrl() {
    return 'https://www.sandbox.paypal.com/cgi-bin/webscr';
  }

  /**
   * {@inheritdoc}
   */
  public function onRedirectReturn(OrderInterface $order) {
    $current_request = \Drupal::getContainer()->get('request_stack')->getCurrentRequest();

    // mc_gross = 89.50
    // invoice = 3-TIMESTAMP
    // protection_eligibility
    // item_number1
    // payer_id
    // tax
    // payment_date
    // payment_status (Pending)
    // notify_version
    // payer_status
    // payer_email
    // verify_sign
    // tnx_id
    // pending_reason
    // payment_gross
    // auth

    // Create the payment.
    $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      // Gateway plugins cannot reach their matching config entity directly.
      'payment_gateway' => $order->payment_gateway->entity->id(),
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $current_request->request->get('txn_id'),
      'remote_state' => $current_request->request->get('payment_status'),
      'authorized' => REQUEST_TIME,
    ]);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onRedirectCancel(OrderInterface $order) {
    // Nothing to do.
  }

}
