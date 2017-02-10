<?php

namespace Drupal\commerce_paypal;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class IPNHandler implements IPNHandlerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Constructs a new PaymentGatewayBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \GuzzleHttp\ClientInterface $client
   *   The client.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, ClientInterface $client) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->httpClient = $client;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Request $request) {
    // Get IPN request data.
    $ipn_data = $this->getRequestDataArray($request->getContent());

    // Exit now if the $_POST was empty.
    if (empty($ipn_data)) {
      $this->logger->warning('IPN URL accessed with no POST data submitted.');
      throw new BadRequestHttpException('IPN URL accessed with no POST data submitted.');
    }

    // Make PayPal request for IPN validation.
    $url = $this->getIpnValidationUrl($ipn_data);
    $validate_ipn = 'cmd=_notify-validate&' . $request->getContent();
    $request = $this->httpClient->post($url, [
      'body' => $validate_ipn,
    ])->getBody();
    $paypal_response = $this->getRequestDataArray($request->getContents());

    // If the IPN was invalid, log a message and exit.
    if (isset($paypal_response['INVALID'])) {
      $this->logger->alert('Invalid IPN received and ignored.');
      throw new BadRequestHttpException('Invalid IPN received and ignored.');
    }

    return $ipn_data;
  }

  /**
   * Get data array from a request content.
   *
   * @param string $request_content
   *   The Request content.
   *
   * @return array
   *   The request data array.
   */
  protected function getRequestDataArray($request_content) {
    parse_str(html_entity_decode($request_content), $ipn_data);
    return $ipn_data;
  }

  /**
   * Gets the IPN URL to be used for validation for IPN data.
   *
   * @param array $ipn_data
   *   The IPN request data from PayPal.
   *
   * @return string
   *   The IPN validation URL.
   */
  protected function getIpnValidationUrl(array $ipn_data) {
    if (!empty($ipn_data['test_ipn']) && $ipn_data['test_ipn'] == 1) {
      return 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
    }
    else {
      return 'https://ipnpb.paypal.com/cgi-bin/webscr';
    }
  }

}
