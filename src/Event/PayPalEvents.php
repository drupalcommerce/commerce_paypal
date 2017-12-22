<?php

namespace Drupal\commerce_paypal\Event;

/**
 * Defines events for the Commerce PayPal module.
 */
final class PayPalEvents {

  /**
   * Name of the event fired after creating a new PayFlow payment method.
   *
   * This event is fired before the payment method is saved.
   *
   * @Event
   *
   * @see \Drupal\commerce_paypal\Event\PostCreatePayFlowPaymentMethodEvent.php
   */
  const POST_CREATE_PAYMENT_METHOD_PAYFLOW = 'commerce_paypal.post_create_payment_method_payflow';

  /**
   * Name of the event fired when performing the Express Checkout requests.
   *
   * @Event
   *
   * @see \Drupal\commerce\Event\ExpressCheckoutRequestEvent.php
   */
  const EXPRESS_CHECKOUT_REQUEST = 'commerce_paypal.express_checkout_request';

}
