<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface PayPalIPNGatewayBaseInterface extends OffsitePaymentGatewayInterface {

  /**
   * Loads the payment for a given remote id.
   *
   * @param string $remote_id
   *   The remote id property for a payment.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   Payment object.
   *
   * @todo: to be replaced by Commerce core payment storage method
   * @see https://www.drupal.org/node/2856209
   */
  public function loadPaymentByRemoteId($remote_id);

  /**
   * Processes an incoming IPN request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return mixed
   *   The request data array or FALSE.
   */
  public function processIpnRequest(Request $request);

  /**
   * Get data array from a request content.
   *
   * @param string $request_content
   *   The Request content.
   *
   * @return array
   *   The request data array.
   */
  public function getRequestDataArray($request_content);

  /**
   * Gets the IPN URL to be used for validation for IPN data.
   *
   * @param array $ipn_data
   *   The IPN request data from PayPal.
   *
   * @return string
   *   The IPN validation URL.
   */
  public function getIpnValidationUrl(array $ipn_data);

}
