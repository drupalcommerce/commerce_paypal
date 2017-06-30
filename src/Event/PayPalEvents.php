<?php

namespace Drupal\commerce_paypal\Event;

/**
 * Defines events for the Commerce PayPal module.
 */
final class PayPalEvents {

  /**
   * Name of the event fired when performing the Express Checkout requests.
   *
   * @Event
   *
   * @see \Drupal\commerce\Event\ExpressCheckoutRequestEvent.php
   */
  const EXPRESS_CHECKOUT_REQUEST = 'commerce_paypal.express_checkout_request';

}
