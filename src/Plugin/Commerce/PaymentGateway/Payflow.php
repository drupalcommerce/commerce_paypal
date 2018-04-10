<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the PayPal Payflow payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_payflow",
 *   label = "PayPal (Payflow)",
 *   display_label = "Credit Card",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class Payflow extends OnsitePaymentGatewayBase implements PayflowInterface {

  /**
   * Payflow test API URL.
   */
  const PAYPAL_API_TEST_URL = 'https://pilot-payflowpro.paypal.com';

  /**
   * Payflow production API URL.
   */
  const PAYPAL_API_URL = 'https://payflowpro.paypal.com';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ClientInterface $client, RounderInterface $rounder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->httpClient = $client;
    $this->rounder = $rounder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client'),
      $container->get('commerce_price.rounder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'partner' => '',
      'vendor' => '',
      'user' => '',
      'password' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['partner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Partner'),
      '#default_value' => $this->configuration['partner'],
      '#required' => TRUE,
    ];
    $form['vendor'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vendor'),
      '#default_value' => $this->configuration['vendor'],
      '#required' => TRUE,
    ];
    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#default_value' => $this->configuration['user'],
      '#required' => TRUE,
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Only needed if you wish to change the stored value.'),
      '#default_value' => $this->configuration['password'],
      '#required' => empty($this->configuration['password']),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['partner'] = $values['partner'];
      $this->configuration['vendor'] = $values['vendor'];
      $this->configuration['user'] = $values['user'];
      if (!empty($values['password'])) {
        $this->configuration['password'] = $values['password'];
      }
    }
  }

  /**
   * Returns the Api URL.
   */
  protected function getApiUrl() {
    return $this->getMode() == 'test' ? self::PAYPAL_API_TEST_URL : self::PAYPAL_API_URL;
  }

  /**
   * Returns the partner.
   */
  protected function getPartner() {
    return $this->configuration['partner'] ?: '';
  }

  /**
   * Returns the vendor.
   */
  protected function getVendor() {
    return $this->configuration['vendor'] ?: '';
  }

  /**
   * Returns the user.
   */
  protected function getUser() {
    return $this->configuration['user'] ?: '';
  }

  /**
   * Returns the password.
   */
  protected function getPassword() {
    return $this->configuration['password'] ?: '';
  }

  /**
   * Formats the expiration date from the provided payment details.
   *
   * PayPal requires the expiration date to be in MMYY format.
   * Using a four-digit year (MMYYYY) will cause some banks to decline
   * transactions because the expiration date is considered invalid.
   * For example, 072018 will be interpreted as 0720 instead of 0718.
   *
   * @param array $payment_details
   *   The payment details.
   *
   * @return string
   *   The expiration date, in the MMYY format.
   */
  protected function getExpirationDate(array $payment_details) {
    $date = \DateTime::createFromFormat('Y', $payment_details['expiration']['year']);
    return $payment_details['expiration']['month']  . $date->format('y');
  }

  /**
   * Merge default Payflow parameters in with the provided ones.
   *
   * @param array $parameters
   *   The parameters for the transaction.
   *
   * @return array
   *   The new parameters.
   */
  protected function getParameters(array $parameters = []) {
    $defaultParameters = [
      'tender' => 'C',
      'partner' => $this->getPartner(),
      'vendor' => $this->getVendor(),
      'user' => $this->getUser(),
      'pwd' => $this->getPassword(),
    ];

    return $parameters + $defaultParameters;
  }

  /**
   * Get the remote transaction number ('pnref') of a payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return string
   *   The Payflow transaction number.
   */
  protected function getTransactionNumber(PaymentInterface $payment) {
    return explode('|', $payment->getRemoteId())[0];
  }

  /**
   * Get the remote authorization code ('authcode') of a payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return string
   *   The Payflow authorization code.
   */
  protected function getAuthorizationCode(PaymentInterface $payment) {
    $remote_id = $payment->getRemoteId();

    return (strpos($remote_id, '|') !== FALSE) ? explode('|', $remote_id)[1] : $remote_id;
  }

  /**
   * Gets a composite Remote ID from two Payflow payment transaction fields.
   *
   * @param array $data
   *   A data array including 'pnref' and 'authcode' keys.
   *
   * @return bool|string
   *   FALSE if required keys are missing, or a string "<pnref>|<authcode>".
   */
  protected function prepareRemoteId(array $data) {
    if (!array_key_exists('pnref', $data) || !array_key_exists('authcode', $data)) {
      return FALSE;
    }

    return $data['pnref'] . '|' . $data['authcode'];
  }

  /**
   * Prepares the request body to name/value pairs.
   *
   * @param array $parameters
   *   The request parameters.
   *
   * @return string
   *   The request body.
   */
  protected function prepareBody(array $parameters = []) {
    $parameters = $this->getParameters($parameters);

    $values = [];
    foreach ($parameters as $key => $value) {
      $values[] = strtoupper($key) . '=' . $value;
    }

    return implode('&', $values);
  }

  /**
   * Prepares the result of a request.
   *
   * @param string $body
   *   The result.
   *
   * @return array
   *   An array of the result values.
   */
  protected function prepareResult($body) {
    $responseParts = explode('&', $body);

    $result = [];
    foreach ($responseParts as $bodyPart) {
      list($key, $value) = explode('=', $bodyPart, 2);
      $result[strtolower($key)] = $value;
    }

    return $result;
  }

  /**
   * Post a transaction to the Payflow server and return the response.
   *
   * @param array $parameters
   *   The parameters to send (will have base parameters added).
   *
   * @return array
   *   The response body data in array format.
   */
  protected function executeTransaction(array $parameters) {
    $body = $this->prepareBody($parameters);

    $response = $this->httpClient->post($this->getApiUrl(), [
      'headers' => [
        'Content-Type' => 'text/namevalue',
        'Content-Length' => strlen($body),
      ],
      'body' => $body,
      'timeout' => 0,
    ]);
    return $this->prepareResult($response->getBody()->getContents());
  }

  /**
   * Attempt to validate payment information according to a payment state.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to validate.
   * @param string|null $payment_state
   *   The payment state to validate the payment for.
   */
  protected function validatePayment(PaymentInterface $payment, $payment_state = 'new') {
    $this->assertPaymentState($payment, [$payment_state]);

    $payment_method = $payment->getPaymentMethod();
    if (empty($payment_method)) {
      throw new InvalidArgumentException('The provided payment has no payment method referenced.');
    }

    switch ($payment_state) {
      case 'new':
        if ($payment_method->isExpired()) {
          throw new HardDeclineException('The provided payment method has expired.');
        }

        break;

      case 'authorization':
        if ($payment->isExpired()) {
          throw new \InvalidArgumentException('Authorizations are guaranteed for up to 29 days.');
        }
        if (empty($payment->getRemoteId())) {
          throw new \InvalidArgumentException('Could not retrieve the transaction ID.');
        }
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->validatePayment($payment, 'new');

    try {
      $data = $this->executeTransaction([
        'trxtype' => $capture ? 'S' : 'A',
        'amt' => $this->rounder->round($payment->getAmount())->getNumber(),
        'currencycode' => $payment->getAmount()->getCurrencyCode(),
        'origid' => $payment->getPaymentMethod()->getRemoteId(),
        'verbosity' => 'HIGH',
        // 'orderid' => $payment->getOrderId(),
      ]);
      if ($data['result'] !== '0') {
        throw new HardDeclineException('Could not charge the payment method. Response: ' . $data['respmsg'], $data['result']);
      }

      $next_state = $capture ? 'completed' : 'authorization';
      $payment->setState($next_state);
      if (!$capture) {
        $payment->setExpiresTime($this->time->getRequestTime() + (86400 * 29));
      }

      $payment
        ->setRemoteId($this->prepareRemoteId($data))
        ->setRemoteState('3')
        ->save();
    }
    catch (RequestException $e) {
      throw new HardDeclineException('Could not charge the payment method.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->validatePayment($payment, 'authorization');
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount = $this->rounder->round($amount);

    try {
      $data = $this->executeTransaction([
        'trxtype' => 'D',
        'amt' => $this->rounder->round($amount)->getNumber(),
        'currency' => $amount->getCurrencyCode(),
        'origid' => $this->getTransactionNumber($payment),
      ]);

      if ($data['result'] !== '0') {
        throw new PaymentGatewayException('Count not capture payment. Message: ' . $data['respmsg'], $data['result']);
      }

      $payment->setState('completed');
      $payment->setAmount($amount);
      $payment->save();
    }
    catch (RequestException $e) {
      throw new PaymentGatewayException('Count not capture payment. Message: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->validatePayment($payment, 'authorization');

    $remoteId = $this->getTransactionNumber($payment);

    if (empty($remoteId)) {
      throw new PaymentGatewayException('Remote authorization ID could not be determined.');
    }

    try {
      $data = $this->executeTransaction([
        'trxtype' => 'V',
        'origid' => $this->getTransactionNumber($payment),
        'verbosity' => 'HIGH',
      ]);

      if ($data['result'] !== '0') {
        throw new PaymentGatewayException('Payment could not be voided. Message: ' . $data['respmsg'], $data['result']);
      }

      $payment->setState('authorization_voided');
      $payment->save();
    }
    catch (RequestException $e) {
      throw new InvalidArgumentException('Only payments in the "authorization" state can be voided.');
    }
  }

  /**
   * {@inheritdoc}
   *
   * TODO: Find a way to store the capture ID.
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    if ($payment->getCompletedTime() < strtotime('-180 days')) {
      throw new InvalidRequestException("Unable to refund a payment captured more than 180 days ago.");
    }
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount = $this->rounder->round($amount);
    $this->assertRefundAmount($payment, $amount);
    $transaction_number = $this->getTransactionNumber($payment);
    if (empty($transaction_number)) {
      throw new InvalidRequestException('Could not determine the remote payment details.');
    }

    try {
      $new_refunded_amount = $payment->getRefundedAmount()->add($amount);

      $data = $this->executeTransaction([
        'trxtype' => 'C',
        'origid' => $transaction_number,
        'amt'=> $amount->getNumber(),
      ]);
      if ($data['result'] !== '0') {
        throw new PaymentGatewayException('Credit could not be completed. Message: ' . $data['respmsg'], $data['result']);
      }

      if ($new_refunded_amount->lessThan($payment->getAmount())) {
        $payment->setState('partially_refunded');
      }
      else {
        $payment->setState('refunded');
      }

      $payment->setRefundedAmount($new_refunded_amount);
      $payment->save();
    }
    catch (RequestException $e) {
      throw new InvalidRequestException("Could not refund the payment.", $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    try {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $payment_method->getBillingProfile()->get('address')->first();

      $data = $this->executeTransaction([
        'trxtype' => 'A',
        'amt' => 0,
        'verbosity' => 'HIGH',
        'acct' => $payment_details['number'],
        'expdate' => $this->getExpirationDate($payment_details),
        'cvv2' => $payment_details['security_code'],
        'billtoemail' => $payment_method->getOwner()->getEmail(),
        'billtofirstname' => $address->getGivenName(),
        'billtolastname' => $address->getFamilyName(),
        'billtostreet' => $address->getAddressLine1(),
        'billtocity' => $address->getLocality(),
        'billtostate' => $address->getAdministrativeArea(),
        'billtozip' => $address->getPostalCode(),
        'billtocountry' => $address->getCountryCode(),
      ]);

      if ($data['result'] !== '0') {
        throw new HardDeclineException("Unable to verify the credit card: " . $data['respmsg'], $data['result']);
      }

      $payment_method->card_type = $payment_details['type'];
      // Only the last 4 numbers are safe to store.
      $payment_method->card_number = substr($payment_details['number'], -4);
      $payment_method->card_exp_month = $payment_details['expiration']['month'];
      $payment_method->card_exp_year = $payment_details['expiration']['year'];
      $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);

      // Store the remote ID returned by the request.
      $payment_method
        ->setRemoteId($data['pnref'])
        ->setExpiresTime($expires)
        ->save();
    }
    catch (RequestException $e) {
      throw new HardDeclineException("Unable to store the credit card");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $payment_method->delete();
  }

}
