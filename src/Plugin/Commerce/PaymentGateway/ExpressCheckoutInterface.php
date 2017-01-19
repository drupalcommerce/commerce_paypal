<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface ExpressCheckoutInterface extends OffsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * Gets the API URL.
   *
   * @return string
   *   The API URL.
   */
  public function getUrl();

  /**
   * Performs a PayPal Express Checkout NVP API request.
   *
   * @param array $nvp_data
   *   The NVP API data array as documented.
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/classic/api/#express-checkout
   */
  public function doRequest(array $nvp_data);

  /**
   * SetExpressCheckout API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param array $extra
   *   Extra data needed for this request, ex.: cancel url, return url, transaction mode, etc....
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/classic/api/merchant/SetExpressCheckout_API_Operation_NVP/
   */
  public function setExpressCheckout(PaymentInterface $payment, array $extra);

  /**
   * GetExpressCheckoutDetails API Operation (NVP) request.
   *
   * Builds the data for the request and make the request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   PayPal response data array.
   *
   * @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
   */
  public function getExpressCheckoutDetails(OrderInterface $order);

  /**
   * GetExpressCheckoutDetails API Operation (NVP) request.
   *
   * Builds the data for the request and make the request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
   */
  public function doExpressCheckoutDetails(OrderInterface $order);

  /**
   * DoCapture API Operation (NVP) request.
   *
   * Builds the data for the request and make the request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param int $amount_number
   *   The amount number to be captured.
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/classic/api/merchant/DoCapture_API_Operation_NVP/
   */
  public function doCapture(PaymentInterface $payment, $amount_number);

  /**
   * DoVoid API Operation (NVP) request.
   *
   * Builds the data for the request and make the request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/classic/api/merchant/DoVoid_API_Operation_NVP/
   */
  public function doVoid(PaymentInterface $payment);

  /**
   * RefundTransaction API Operation (NVP) request.
   *
   * Builds the data for the request and make the request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param array $extra
   *   Extra data needed for this request, ex.: refund amount, refund type, etc....
   *
   * @return array
   *   PayPal response data.
   *
   * @see https://developer.paypal.com/docs/classic/api/merchant/RefundTransaction_API_Operation_NVP/
   */
  public function doRefundTransaction(PaymentInterface $payment, array $extra);

}
