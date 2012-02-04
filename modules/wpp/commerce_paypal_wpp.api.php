<?php

/**
 * @file
 * Hook documentation for the PayPal WPP module.
 */


/**
 * Allows modules to alter the name-value pair array for a PayPal WPP API
 * request before it is submitted.
 *
 * @param &$nvp
 *   The name-value pair array for the API request.
 * @param $order
 *   If available, the full order object the payment request is being submitted
 *   for; otherwise NULL.
 */
function hook_commerce_paypal_wpp_request_alter(&$nvp, $order) {
  // No example.
}
