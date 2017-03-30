<?php

namespace Drupal\commerce_paypal;

use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a handler for IPN requests from PayPal.
 */
interface IPNHandlerInterface {

  /**
   * Processes an incoming IPN request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return mixed
   *   The request data array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function process(Request $request);

}
