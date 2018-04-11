<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Paypal PaymentsPro payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_payments_pro",
 *   label = "PayPal (PaymentsPro)",
 *   display_label = "PayPal",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class PaymentsPro extends OnsitePaymentGatewayBase implements PaymentsProInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * State service for retrieving database info.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ClientInterface $client, StateInterface $state, RounderInterface $rounder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->httpClient = $client;
    $this->state = $state;
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
      $container->get('state'),
      $container->get('commerce_price.rounder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'client_id' => '',
      'client_secret' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $this->configuration['client_id'],
      '#required' => TRUE,
    ];
    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client secret'),
      '#default_value' => $this->configuration['client_secret'],
      '#required' => TRUE,
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
      $this->configuration['client_id'] = $values['client_id'];
      $this->configuration['client_secret'] = $values['client_secret'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);
    $owner = $payment_method->getOwner();
    $amount = $this->rounder->round($payment->getAmount());

    // Prepare the payments parameters.
    $parameters = [
      'intent' => $capture ? 'sale' : 'authorize',
      'payer' => [
        'payment_method' => 'credit_card',
        'funding_instruments' => [
          [
            'credit_card_token' => [
              'credit_card_id' => $payment_method->getRemoteId(),
            ],
          ],
        ],
      ],
      'transactions' => [
        [
          'amount' => [
            'total' => $amount->getNumber(),
            'currency' => $amount->getCurrencyCode(),
          ],
        ],
      ],
    ];

    // Passing the external_customer_id seems to create issues.
    if ($owner->isAuthenticated()) {
      $parameters['payer']['funding_instruments'][0]['credit_card_token']['payer_id'] = $owner->id();
    }
    $data = $this->doRequest('/payments/payment', ['json' => $parameters]);

    if (!isset($data['state']) || $data['state'] == 'failed') {
      throw new PaymentGatewayException('Could not charge the payment method.');
    }

    $next_state = $capture ? 'completed' : 'authorization';
    $payment->setState($next_state);
    $payment->setRemoteId($data['id']);
    $payment->setRemoteState($data['state']);
    if (!$capture) {
      $payment->setExpiresTime($this->time->getRequestTime() + (86400 * 29));
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    if ($payment->isExpired()) {
      throw new \InvalidArgumentException('Authorizations are guaranteed for up to 29 days.');
    }
    // Retrieve the remote payment details, instead of doing this, we should
    // store the initial response containing authorization ID.
    $data = $this->getPaymentDetails($payment->getRemoteId());

    // Retrieve the authorization ID.
    $relatedResources = $data['transactions'][0]['related_resources'];
    $authorization = $relatedResources[0]['authorization'];

    if (!isset($authorization['id'])) {
      throw new PaymentGatewayException('Could not retrieve the authorization ID.');
    }

    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount = $this->rounder->round($amount);

    // Instead of the remoteId, we need to pass the authorization ID, figure
    // out how to store it...
    $data = $this->doRequest('/payments/authorization/' . $authorization['id'] . '/capture', [
      'json' => [
        'amount' => [
          'currency' => $amount->getCurrencyCode(),
          'total' => $amount->getNumber(),
        ],
      ],
    ]);

    if ($data['state'] !== 'completed') {
      throw new PaymentGatewayException(sprintf('Reason: %s', $data['reason_code']));
    }

    // TODO: Support partial captures?.
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);

    // Get payment details first.
    $data = $this->getPaymentDetails($payment->getRemoteId());

    if (!isset($data['intent']) || $data['intent'] != 'authorize') {
      throw new PaymentGatewayException('Only payments in the "authorization" state can be voided.');
    }
    $transaction = $data['transactions'][0];
    $authorization = FALSE;

    foreach ($transaction['related_resources'] as $related_resource) {
      if (key($related_resource) == 'authorization') {
        $authorization = $related_resource['authorization'];
        break;
      }
    }
    $data = $this->doRequest('/payments/authorization/' . $authorization['id'] . '/void');

    // Check the returned state to ensure the authorization has been
    // voided.
    if (!isset($data['state']) || $data['state'] !== 'voided') {
      throw new PaymentGatewayException('Could not void the payment');
    }
    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   **/
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // @todo check if more than 180 days.
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);
    $amount = $this->rounder->round($amount);
    $data = $this->getPaymentDetails($payment->getRemoteId());

    // We need to retrieve the payment transaction ID.
    if (!isset($data['id'])) {
      throw new PaymentGatewayException('Could not retrieve the remote payment details.');
    }

    // If it was a sale payment transaction.
    if ($data['intent'] == 'sale') {
      $transaction = $data['transactions'][0]['related_resources'][0]['sale'];
      $endpoint = '/payments/sale/' . $transaction['id'] . '/refund';
    }
    else {
      foreach ($data['transactions'][0]['related_resources'] as $key => $related_resource) {
        if (key($related_resource) == 'capture') {
          $endpoint = '/payments/capture/' . $related_resource['capture']['id'] . '/refund/';
          break;
        }
      }
    }

    if (!isset($endpoint)) {
      throw new PaymentGatewayException('Could not determine the endpoint for refunding payment.');
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);

    $data = $this->doRequest($endpoint, [
      'json' => [
        'amount' => [
          'total' => $amount->getNumber(),
          'currency' => $amount->getCurrencyCode(),
        ],
      ],
    ]);

    if (!isset($data['state']) || $data['state'] !== 'completed') {
      throw new PaymentGatewayException('Could not refund the payment.');
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

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $address = $payment_method->getBillingProfile()->address->first();
    $owner = $payment_method->getOwner();

    // Prepare an array of parameters to sent to the vault endpoint.
    $parameters = [
      'number' => $payment_details['number'],
      'type' => $payment_details['type'],
      'expire_month' => $payment_details['expiration']['month'],
      'expire_year' => $payment_details['expiration']['year'],
      'cvv2' => $payment_details['security_code'],
      'first_name' => $address->getGivenName(),
      'last_name' => $address->getFamilyName(),
      'billing_address' => [
        'line1' => $address->getAddressLine1(),
        'city' => $address->getLocality(),
        'country_code' => $address->getCountryCode(),
        'postal_code' => $address->getPostalCode(),
        'state' => $address->getAdministrativeArea(),
      ],
    ];

    // Should we send the UUID instead?
    // payer_id is marked as deprecated on some doc pages.
    if ($owner->isAuthenticated()) {
      $parameters['payer_id'] = $owner->id();
    }
    $data = $this->doRequest('/vault/credit-cards', ['json' => $parameters]);

    if (!isset($data['id']) || $data['state'] !== 'ok') {
      throw new PaymentGatewayException('Unable to store the credit card');
    }

    $payment_method->card_type = $payment_details['type'];
    // Only the last 4 numbers are safe to store.
    $payment_method->card_number = substr($payment_details['number'], -4);
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);

    // Store the remote ID returned by the request.
    $payment_method->setRemoteId($data['id']);
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    try {
      $response = $this->httpClient->delete($this->getApiUrl() . '/vault/credit-cards/' . $payment_method->getRemoteId(), [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ],
      ]);
    }
    catch (RequestException $e) {
      \Drupal::logger('commerce_paypal')->error($e->getMessage());
      throw new PaymentGatewayException('The payment method could not be deleted.');
    }

    if ($response->getStatusCode() !== 204) {
      throw new PaymentGatewayException('The payment method could not be deleted.');
    }

    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessToken() {
    $access_token = $this->state->get('commerce_paypal.access_token');

    if (!empty($access_token)) {
      $token_expiration = $this->state->get('commerce_paypal.access_token_expiration');

      // Check if the access token is still valid.
      if (!empty($token_expiration) && $token_expiration > $this->time->getRequestTime()) {
        return $access_token;
      }
    }
    $data = $this->doRequest('/oauth2/token', [
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
      'auth' => [
        $this->configuration['client_id'],
        $this->configuration['client_secret'],
      ],
      'form_params' => [
        'grant_type' => 'client_credentials',
      ],
    ]);
    // Store the access token.
    if (isset($data['access_token'])) {
      $this->state->set('commerce_paypal.access_token', $data['access_token']);
      $this->state->set('commerce_paypal.access_token_expiration', $this->time->getRequestTime() + $data['expires_in']);
      return $data['access_token'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApiUrl() {
    if ($this->getMode() == 'test') {
      return 'https://api.sandbox.paypal.com/v1';
    }
    else {
      return 'https://api.paypal.com/v1';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function doRequest($endpoint, array $parameters = [], $method = 'POST') {
    try {
      $parameters += [
        'headers' => [
          'Content-type' => 'application/json',
        ],
        'timeout' => 30,
      ];
      // Add the Authorization header only if the auth header is not present,
      // otherwise calling doRequest() from getAccessToken() will result in
      // an infinite loop.
      if (!isset($parameters['auth'])) {
        $parameters['headers'] += [
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
      }
      $response = $this->httpClient->request($method, $this->getApiUrl() . $endpoint, $parameters);
      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('commerce_paypal')->error($e->getMessage());
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentDetails($payment_id) {
    return $this->doRequest($this->getApiUrl() . '/payments/payment/' . $payment_id, [], 'GET');
  }

}
