<?php

namespace Drupal\commerce_paypal\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Defines the Express Checkout request event.
 *
 * @see \Drupal\commerce_paypal\Event\CommercePaypalEvents
 */
class ExpressCheckoutRequestEvent extends Event {

  /**
   * The NVP API data array.
   *
   * @var array
   */
  protected $nvpData;

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * Constructs a new ExpressCheckoutRequestEvent object.
   *
   * @param array $nvp_data
   *   The NVP API data array as documented.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity, or null.
   */
  public function __construct(array $nvp_data, OrderInterface $order = NULL) {
    $this->nvpData = $nvp_data;
    $this->order = $order;
  }

  /**
   * Gets the order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or NULL if unknown.
   */
  public function getOrder() {
    return $this->order;
  }

  /**
   * Gets the NVP API data array.
   *
   * @return array
   *   The NVP API data array.
   */
  public function getNvpData() {
    return $this->nvpData;
  }

  /**
   * Sets the NVP API data array.
   *
   * @param array $nvp_data
   *   The NVP API data array as documented.
   *
   * @return $this
   */
  public function setNvpData(array $nvp_data) {
    $this->nvpData = $nvp_data;
    return $this;
  }

}
