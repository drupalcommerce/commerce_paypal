<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
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
   * Paypal test API URL.
   */
  const PAYPAL_API_TEST_URL = 'https://api.sandbox.paypal.com/v1';

  /**
   * Paypal production API URL.
   */
  const PAYPAL_API_URL = 'https://api.paypal.com/v1';

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
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, ClientInterface $client, StateInterface $state) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->httpClient = $client;
    $this->state = $state;
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
      $container->get('http_client'),
      $container->get('state')
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
   *
   * @todo Needs kernel test
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    if ($payment->getState()->value != 'new') {
      throw new \InvalidArgumentException('The provided payment is in an invalid state.');
    }
    $payment_method = $payment->getPaymentMethod();
    if (empty($payment_method)) {
      throw new \InvalidArgumentException('The provided payment has no payment method referenced.');
    }
    if (REQUEST_TIME >= $payment_method->getExpiresTime()) {
      throw new HardDeclineException('The provided payment method has expired');
    }
    $owner = $payment_method->getOwner();

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
            'total' => $payment->getAmount()->getNumber(),
            'currency' => $payment->getAmount()->getCurrencyCode(),
          ],
        ],
      ],
    ];

    // Passing the external_customer_id seems to create issues.
    if ($owner->isAuthenticated()) {
      $parameters['payer']['funding_instruments'][0]['credit_card_token']['payer_id'] = $owner->id();
    }

    try {
      $response = $this->httpClient->post($this->apiUrl() . '/payments/payment', [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ],
        'json' => $parameters,
        'timeout' => 0,
      ]);
      $data = json_decode($response->getBody(), TRUE);

      // TODO: not sure if we need to throw a HardDeclineException here.
      if ($data['state'] == 'failed') {
        throw new HardDeclineException('Could not charge the payment method.');
      }

      $payment->state = $capture ? 'capture_completed' : 'authorization';
      if ($this->getMode() == 'test') {
        $payment->setTest(TRUE);
      }
      $payment->setRemoteId($data['id']);
      $payment->setRemoteState($data['state']);
      $payment->setAuthorizedTime(REQUEST_TIME);
      if ($capture) {
        $payment->setCapturedTime(REQUEST_TIME);
      }
      else {
        $payment->setAuthorizationExpiresTime(REQUEST_TIME + (86400 * 29));
      }
      $payment->save();
    }
    catch (RequestException $e) {
      throw new HardDeclineException('Could not charge the payment method.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only payments in the "authorization" state can be captured.');
    }
    if ($payment->getAuthorizationExpiresTime() < REQUEST_TIME) {
      throw new \InvalidArgumentException('Authorizations are guaranteed for up to 29 days.');
    }
    try {
      // Retrieve the remote payment details, instead of doing this, we should
      // store the initial response containing authorization ID.
      $response = $this->getPaymentDetails($payment->getRemoteId());

      if ($response->getStatusCode() !== 200) {
        throw new \InvalidArgumentException('Could not retrieve the remote payment details.');
      }

      // Retrieve the authorization ID.
      $data = json_decode($response->getBody(), TRUE);
      $relatedResources = $data['transactions'][0]['related_resources'];
      $authorization = $relatedResources[0]['authorization'];

      if (!isset($authorization['id'])) {
        throw new \InvalidArgumentException('Could not retrieve the transaction ID.');
      }

      // If not specified, capture the entire amount.
      $amount = $amount ?: $payment->getAmount();

      // Instead of the remoteId, we need to pass the authorization ID, figure
      // out how to store it...
      $endpoint = $this->apiUrl() . '/payments/authorization/' . $authorization['id'] . '/capture';

      $response = $this->httpClient->post($endpoint, [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ],
        'json' => [
          'amount' => [
            'currency' => $amount->getCurrencyCode(),
            'total' => $amount->getNumber(),
          ],
        ],
      ]);
      $data = json_decode($response->getBody(), TRUE);

      // TODO: Investigate which Exception is expected.
      if ($data['state'] != 'completed') {
        throw new \Exception($data['reason_code'], $data['reason_code']);
      }

      // TODO: Support partial refunds?
      $payment->state = 'capture_completed';
      $payment->setAmount($amount);
      $payment->setCapturedTime(REQUEST_TIME);
      $payment->save();
    }
    catch (RequestException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only payments in the "authorization" state can be voided.');
    }

    // Get payment details first.
    $response = $this->getPaymentDetails($payment->getRemoteId());

    if ($response->getStatusCode() === 200) {
      $data = json_decode($response->getBody(), TRUE);

      if (!isset($data['intent']) || $data['intent'] != 'authorize') {
        throw new \InvalidArgumentException('Only payments in the "authorization" state can be voided.');
      }

      $transaction = $data['transactions'][0];
      $authorization = FALSE;

      foreach ($transaction['related_resources'] as $related_resource) {
        if (key($related_resource) == 'authorization') {
          $authorization = $related_resource['authorization'];
          break;
        }
      }

      // If we were able to find the authorization in the related resource array.
      if (isset($authorization['id'])) {
        try {
           $response = $this->httpClient->post($this->apiUrl() . '/payments/authorization/' . $authorization['id'] . '/void', [
            'headers' => [
              'Content-type' => 'application/json',
              'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ],
          ]);

          if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody(), TRUE);

            // Check the returned state to ensure the authorization has been
            // voided.
            if ($data['state'] == 'voided') {
              $payment->state = 'authorization_voided';
              $payment->save();
            }
          }
        }
        catch (RequestException $e) {
          throw new \InvalidArgumentException('Only payments in the "authorization" state can be voided.');
        }
      }
    }

  }

  /**
   * {@inheritdoc}
   *
   * TODO: Find a way to store the capture ID.
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.');
    }
    // TODO: check if more than 180 days.

    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    // Validate the requested amount.
    $balance = $payment->getBalance();

    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $balance->__toString()));
    }

    try {
      $response = $this->getPaymentDetails($payment->getRemoteId());
      $data = json_decode($response->getBody(), TRUE);

      // We need to retrieve the payment transaction ID.
      if (!isset($data['id'])) {
        throw new InvalidRequestException('Could not retrieve the remote payment details.');
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

      $old_refunded_amount = $payment->getRefundedAmount();
      $new_refunded_amount = $old_refunded_amount->add($amount);

      $response = $this->httpClient->post($this->apiUrl() . $endpoint, [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ],
        'json' => [
          'amount' => [
            'total' => $amount->getNumber(),
            'currency' => $amount->getCurrencyCode(),
          ],
        ],
      ]);
      $data = json_decode($response->getBody(), TRUE);

      if (isset($data['state']) && $data['state'] == 'completed') {
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
          $payment->state = 'capture_partially_refunded';
        }
        else {
          $payment->state = 'capture_refunded';
        }

        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
      }
    }
    catch (RequestException $e) {
      throw new InvalidRequestException("Could not refund the payment.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    try {
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

      // TODO: Include the merchant_id parameter.
      $response = $this->httpClient->post($this->apiUrl() . '/vault/credit-cards', [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ],
        'json' => $parameters,
      ]);

      // Check the response code, checking 201 should be enough.
      if (in_array($response->getStatusCode(), [200, 201])) {
        $data = json_decode($response->getBody(), TRUE);

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
      else {
        throw new HardDeclineException("Unable to store the credit card");
      }
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

  /**
   * Gets an access token from PayPal.
   *
   * @return string
   *   The access token returned by PayPal.
   */
  protected function getAccessToken() {
    $access_token = $this->state->get('commerce_paypal.access_token');

    if (!empty($access_token)) {
      $token_expiration = $this->state->get('commerce_paypal.access_token_expiration');

      // Check if the access token is still valid.
      if (!empty($token_expiration) && $token_expiration > REQUEST_TIME) {
        return $access_token;
      }
    }

    try {
      $response = $this->httpClient->post($this->apiUrl() . '/oauth2/token', [
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
      $data = json_decode($response->getBody(), TRUE);

      // Store the access token.
      if ($response->getStatusCode() === 200 && isset($data['access_token'])) {
        $this->state->set('commerce_paypal.access_token', $data['access_token']);
        $this->state->set('commerce_paypal.access_token_expiration', REQUEST_TIME + $data['expires_in']);
        return $data['access_token'];
      }
    }
    catch (RequestException $e) {
    }
  }

  /**
   * Returns the Api URL.
   */
  protected function apiUrl() {
    return $this->getMode() == 'test' ? self::PAYPAL_API_TEST_URL : self::PAYPAL_API_URL;
  }

  /**
   * Shows details for a payment, by ID, that is yet completed.
   * For example, a payment that was created, approved, or failed.
   *
   * @param $payment_id
   *   The identifier
   * @return \Psr\Http\Message\ResponseInterface|bool
   *   The HTTP response, or FALSE in case of failure.
   */
  protected function getPaymentDetails($payment_id) {
    try {
      return $this->httpClient->get($this->apiUrl() . '/payments/payment/' . $payment_id, [
        'headers' => [
          'Content-type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ],
      ]);
    }
    catch (RequestException $e) {
      return FALSE;
    }
  }

}
