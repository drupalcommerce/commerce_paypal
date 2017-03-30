<?php

namespace Drupal\Tests\commerce_paypal\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the IPN handler.
 *
 * @group commerce_paypal
 * @coversDefaultClass \Drupal\commerce_paypal\IPNHandler
 */
class IPNHandlerTest extends CommerceKernelTestBase implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_paypal',
  ];

  /**
   * The IPN handler.
   *
   * @var \Drupal\commerce_paypal\IPNHandlerInterface
   */
  protected $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->handler = $this->container->get('commerce_paypal.ipn_handler');
  }

  /**
   * Tests when IPN body is empty.
   *
   * @expectedException \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @expectedExceptionMessage IPN URL accessed with no POST data submitted.
   */
  public function testEmptyBody() {
    $this->handler->process(new Request());
  }

  /**
   * Tests when IPN request marked invalid..
   *
   * @expectedException \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @expectedExceptionMessage Invalid IPN received and ignored.
   */
  public function testInvalidIpn() {
    $this->handler->process($this->createSampleIpnRequest());
  }

  /**
   * Creates a request object with testing data.
   *
   * @see https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNIntro/#id08CKFJ00JYK
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function createSampleIpnRequest() {
    $sample_data = 'mc_gross=19.95&protection_eligibility=Eligible&address_status=confirmed&payer_id=LPLWNMTBWMFAY&tax=0.00&address_street=1+Main+St&payment_date=20%3A12%3A59+Jan+13%2C+2009+PST&payment_status=Completed&charset=windows-1252&address_zip=95131&first_name=Test&mc_fee=0.88&address_country_code=US&address_name=Test+User&notify_version=2.6&custom=&payer_status=verified&address_country=United+States&address_city=San+Jose&quantity=1&verify_sign=AtkOfCXbDm2hu0ZELryHFjY-Vb7PAUvS6nMXgysbElEn9v-1XcmSoGtf&payer_email=gpmac_1231902590_per%40paypal.com&txn_id=61E67681CH3238416&payment_type=instant&last_name=User&address_state=CA&receiver_email=gpmac_1231902686_biz%40paypal.com&payment_fee=0.88&receiver_id=S8XGHLYDW9T3S&txn_type=express_checkout&item_name=&mc_currency=USD&item_number=&residence_country=US&test_ipn=1&handling_amount=0.00&transaction_subject=&payment_gross=19.95&shipping=0.00';
    return new Request([], [], [], [], [], [], $sample_data);
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->removeDefinition('test.http_client.middleware');
  }

}
