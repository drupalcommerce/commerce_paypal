<?php

namespace Drupal\commerce_paypal\Event;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Defines the 'Post-create PayFlow payment method' event.
 *
 * @see \Drupal\commerce_paypal\Event\PaypalEvents
 */
class PostCreatePayFlowPaymentMethodEvent extends Event {

  /**
   * The payment details.
   *
   * @var array
   */
  protected $paymentDetails;

  /**
   * The payment method.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentMethodInterface
   */
  protected $paymentMethod;

  /**
   * Constructs a new PostCreatePayFlowPaymentMethodEvent object.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The payment details.
   */
  public function __construct(PaymentMethodInterface $payment_method, array $payment_details) {
    $this->paymentDetails = $payment_details;
    $this->paymentMethod = $payment_method;
  }

  /**
   * Gets the payment details
   *
   * @return array
   *   The payment details
   */
  public function getPaymentDetails() {
    return $this->paymentDetails;
  }

  /**
   * Gets the payment method.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   */
  public function getPaymentMethod() {
    return $this->paymentMethod;
  }

  /**
   * Sets the payment method.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   *
   * @return $this
   */
  public function setPaymentMethod(PaymentMethodInterface $payment_method) {
    $this->paymentMethod = $payment_method;
    return $this;
  }

}
