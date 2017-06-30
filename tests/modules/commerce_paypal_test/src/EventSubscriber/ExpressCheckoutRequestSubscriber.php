<?php

namespace Drupal\commerce_paypal_test\EventSubscriber;

use Drupal\commerce_paypal\Event\ExpressCheckoutRequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alters the Express checkout request before its sent to PayPal.
 */
class ExpressCheckoutRequestSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      'commerce_paypal.express_checkout_request' => ['setNvpData', -100],
    ];
    return $events;
  }

  /**
   * Alter the NVP data array before its sent to PayPal.
   *
   * @param \Drupal\commerce_paypal\Event\ExpressCheckoutRequestEvent $event
   *   The Express checkout request event.
   */
  public function setNvpData(ExpressCheckoutRequestEvent $event) {
    $nvp_data = $event->getNvpData();
    // Send the billing address information.
    if ($nvp_data['METHOD'] === 'SetExpressCheckout') {
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $event->getOrder();
      // Check if the billing profile is empty.
      if (!$order->getBillingProfile()) {
        return;
      }
      $address = $order->getBillingProfile()->address->first();
      $name = $address->getGivenName() . ' ' . $address->getFamilyName();
      $billing_info = [
        'PAYMENTREQUEST_0_SHIPTONAME' => substr($name, 0, 32),
        'PAYMENTREQUEST_0_SHIPTOSTREET' => substr($address->getAddressLine1(), 0, 100),
        'PAYMENTREQUEST_0_SHIPTOSTREET2' => substr($address->getAddressLine2(), 0, 100),
        'PAYMENTREQUEST_0_SHIPTOCITY' => substr($address->getLocality(), 0, 40),
        'PAYMENTREQUEST_0_SHIPTOSTATE' => substr($address->getAdministrativeArea(), 0, 40),
        'PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE' => $address->getCountryCode(),
        'PAYMENTREQUEST_0_SHIPTOZIP' => substr($address->getPostalCode(), 0, 20),
      ];
      // Filter out empty values.
      $nvp_data = array_merge($nvp_data, array_filter($billing_info));
      $event->setNvpData($nvp_data);
    }
  }

}
