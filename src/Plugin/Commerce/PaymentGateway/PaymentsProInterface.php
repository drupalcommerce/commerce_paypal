<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the PaymentsPro payment gateway.
 */
interface PaymentsProInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * Gets the API URL.
   *
   * @return string
   *   The API URL.
   */
  public function getApiUrl();

  /**
   * Shows details for a payment, by ID, that is yet completed.
   *
   * For example, a payment that was created, approved, or failed.
   *
   * @param string $payment_id
   *   The payment identifier.
   *
   * @return array
   *   PayPal response data.
   */
  public function getPaymentDetails($payment_id);

  /**
   * Performs a request to PayPal to the specified endpoint.
   *
   * @param string $endpoint
   *   The API endpoint (e.g /payments/payment).
   * @param array $parameters.
   *   The array of parameters to send.
   * @param string $method
   *   The HTTP method, defaults to POST.
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/api.
   */
  public function doRequest($endpoint, array $parameters = [], $method = 'POST');

  /**
   * Gets an access token from PayPal.
   *
   * @return string
   *   The access token returned by PayPal.
   */
  public function getAccessToken();

}
