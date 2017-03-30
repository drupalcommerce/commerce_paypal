<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides common methods to be used by PayPal payment gateways.
 */
abstract class PayPalIPNGatewayBase extends OffsitePaymentGatewayBase implements PayPalIPNGatewayBaseInterface {

  /**
   * {@inheritdoc}
   */
  public function loadPaymentByRemoteId($remote_id) {
    /** @var \Drupal\commerce_payment\PaymentStorage $storage */
    $storage = \Drupal::service('entity_type.manager')->getStorage('commerce_payment');
    $payment_by_remote_id = $storage->loadByProperties(['remote_id' => $remote_id]);
    return reset($payment_by_remote_id);
  }

  /**
   * {@inheritdoc}
   */
  public function processIpnRequest(Request $request) {
    // Get IPN request data.
    $ipn_data = $this->getRequestDataArray($request->getContent());

    // Exit now if the $_POST was empty.
    if (empty($ipn_data)) {
      \Drupal::logger('commerce_paypal')->warning('IPN URL accessed with no POST data submitted.');
      return FALSE;
    }

    // Make PayPal request for IPN validation.
    $url = $this->getIpnValidationUrl($ipn_data);
    $validate_ipn = 'cmd=_notify-validate&' . $request->getContent();
    $request = \Drupal::httpClient()->post($url, [
      'body' => $validate_ipn,
    ])->getBody();
    $paypal_response = $this->getRequestDataArray($request->getContents());

    // If the IPN was invalid, log a message and exit.
    if (isset($paypal_response['INVALID'])) {
      \Drupal::logger('commerce_paypal')->alert('Invalid IPN received and ignored.');
      return FALSE;
    }

    // ToDo other general validations for IPN data.
    return $ipn_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestDataArray($request_content) {
    parse_str(html_entity_decode($request_content), $ipn_data);
    return $ipn_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getIpnValidationUrl(array $ipn_data) {
    if (!empty($ipn_data['test_ipn']) && $ipn_data['test_ipn'] == 1) {
      return 'https://www.sandbox.paypal.com/cgi-bin/webscr';
    }
    else {
      return 'https://www.paypal.com/cgi-bin/webscr';
    }
  }

}
