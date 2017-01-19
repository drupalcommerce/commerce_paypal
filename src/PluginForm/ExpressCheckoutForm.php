<?php

namespace Drupal\commerce_paypal\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class ExpressCheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\ExpressCheckoutInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $extra = [
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url'],
      'capture' => $form['#capture'],
    ];

    $paypal_response = $payment_gateway_plugin->setExpressCheckout($payment, $extra);

    if (!empty($paypal_response['TOKEN'])) {
      $order = $payment->getOrder();
      $order->setData('paypal_express_checkout', [
        'flow' => 'ec',
        'token' => $paypal_response['TOKEN'],
        'payerid' => FALSE,
        'capture' => $extra['capture'],
      ]);
      $order->save();
    }
    else {
      return [
        '#type' => 'inline_template',
        '#template' => "<span>{{ 'There was an error bringing you to PayPal.'|t }}</span>",
      ];
    }

    $redirect_url = $payment_gateway_plugin->getUrl();
    $data = [
      'token' => $paypal_response['TOKEN'],
      'return' => $form['#return_url'],
      'cancel' => $form['#cancel_url'],
      'total' => $payment->getAmount()->getNumber(),
    ];

    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, 'get');
  }

}
