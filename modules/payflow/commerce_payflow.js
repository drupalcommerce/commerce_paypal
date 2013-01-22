(function($) {

/**
 * Escapes from an iframe if the completion page is displayed within an iframe.
 */
Drupal.behaviors.commercePayflowEscapeIframe = {
  attach: function (context, settings) {
    if (top !== self) {
      window.parent.location.href = window.location.href;
    }
  }
}

})(jQuery);
