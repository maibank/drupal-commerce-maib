<?php

namespace Drupal\commerce_maib\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_maib\MAIBGateway;
use Drupal\commerce_maib\Exception\MAIBException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Maib\MaibApi\MaibClient;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "maib_redirect",
 *   label = "MAIB (Off-site redirect)",
 *   display_label = "MAIB",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_maib\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase implements SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'private_key_path' => '',
      'private_key_password' => '',
      'public_key_path' => '',
      'intent' => 'capture',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $urls = [
      Url::fromRoute('commerce_maib.checkout_return', [], ['absolute' => TRUE])->toString(),
      Url::fromRoute('commerce_maib.checkout_cancel', [], ['absolute' => TRUE])->toString(),
    ];

    $form['urls_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Return and cancel URLs to be provided to bank'),
      '#markup' => implode('<br>' . PHP_EOL, $urls),
    ];

    $form['keys'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructions to extract keys from PFX file'),
      '#open' => FALSE,
    ];

    $commands = [
      '<i>&diams;Public key chain:</i>',
      '<code>openssl pkcs12 -in certname.pfx -nokeys -out cert.pem</code>',
      '<i>&diams;Private key with password:</i>',
      '<code>openssl pkcs12 -in certname.pfx -nocerts -out key.pem</code>',
      '<i>&diams;Or optionally without password:</i>',
      '<code>openssl pkcs12 -in certname.pfx -nocerts -out key.pem -nodes</code>',
      '<i>*Centos note, curl+nss requires rsa + des3 for private key:</i>',
      '<code>openssl rsa -des3 -in key.pem -out key-des3.pem</code>',
    ];
    $form['keys']['keys_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Use openssl to extract keys from PFX file and password provided by bank'),
      '#markup' => implode('<br>' . PHP_EOL, $commands),
    ];

    // @todo validate key/certificate pairing and expiration.
    $form['private_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to the private key PEM file'),
      '#default_value' => $this->configuration['private_key_path'] ?? MaibClient::MAIB_TEST_CERT_KEY_URL,
      '#description' => $this->t('Test Private Key: @key', ['@key' => MaibClient::MAIB_TEST_CERT_KEY_URL]),
    ];

    $form['private_key_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password for private key'),
      '#description' => $this->t('Leave empty if no change intended or private key has no password. Test Certificate Pass: @pass', ["@pass" => MaibClient::MAIB_TEST_CERT_PASS]),
      '#default_value' => '',
    ];

    $form['public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to the certificate PEM file containing public key'),
      '#default_value' => $this->configuration['public_key_path'] ?? MaibClient::MAIB_TEST_CERT_URL,
      '#description' => $this->t('Test Certificate: @key', ['@key' => MaibClient::MAIB_TEST_CERT_URL]),
    ];

    $form['intent'] = [
      '#type' => 'radios',
      '#title' => $this->t('Transaction type'),
      '#options' => [
        'capture' => $this->t("Capture (capture payment immediately after customer's approval)"),
        'authorize' => $this->t('Authorize (requires manual or automated capture after checkout)'),
      ],
      '#description' => $this->t('For more information on capturing a prior authorization, please refer to <a href=":url" target="_blank">Capture an authorization</a>.',
        [':url' => 'https://docs.drupalcommerce.org/commerce2/user-guide/payments/capture']),
      '#default_value' => $this->configuration['intent'],
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log debug info'),
      '#default_value' => $this->configuration['debug'],
    ];

    $form['debug_file'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Log file'),
      '#default_value' => $this->configuration['debug_file'],
      '#description' => $this->t('Ex: /tmp/maib-requests.log'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[maib_redirect][debug]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    // Validate public key.
    if (!file_exists($values['public_key_path'])) {
      $form_state->setErrorByName('public_key_path', $this->t("Incorrect path to public key"));
    }
    else {
      $rsaKey = file_get_contents($values['public_key_path']);
      openssl_get_publickey($rsaKey) ?: $form_state->setErrorByName(
        'public_key_path', $this->t("Can't get public key from file")
      );
    }
    // Validate private key.
    if (!file_exists($values['private_key_path'])) {
      $form_state->setErrorByName('private_key_path', $this->t("Incorrect path to private key"));
    }
    else {
      $rsaKey = file_get_contents($values['private_key_path']);
      $old_configuration = $this->getConfiguration();
      $old_pass = $old_configuration['private_key_password'] ?? '';
      $key_pass = empty($values['private_key_password']) ? $old_pass : $values['private_key_password'];
      openssl_get_privatekey($rsaKey, $key_pass) ?: $form_state->setErrorByName(
        'private_key_path', $this->t("Can't get private key from file")
      );
    }

    if ($values['debug'] && empty(trim($values['debug_file']))) {
      $form_state->setErrorByName('debug_file', $this->t("Path to log file missing"));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $old_configuration = $this->getConfiguration();
    $old_pass = $old_configuration['private_key_password'] ?? '';

    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['private_key_path'] = $values['private_key_path'];
      $this->configuration['public_key_path'] = $values['public_key_path'];
      $this->configuration['private_key_password'] = empty($values['private_key_password']) ? $old_pass : $values['private_key_password'];
      $this->configuration['intent'] = $values['intent'];
      $this->configuration['debug'] = $values['debug'];
      $this->configuration['debug_file'] = $values['debug_file'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $transactionId = $request->get(MAIBGateway::MAIB_TRANS_ID);
    if (empty($transactionId)) {
      throw new MAIBException('MAIB return redirect error: Missing TRANSACTION_ID');
    }
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties([
      'remote_id' => $transactionId,
      'payment_gateway' => commerce_maib_get_all_gateway_ids(),
    ]);
    if (empty($payments)) {
      throw new MAIBException(sprintf('MAIB error: failed to locate payment for TRANSACTION_ID %s', $transactionId));
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = reset($payments);

    try {
      // Get transaction information.
      $payment_info = $this->getClient()->getTransactionResult($transactionId, $order->getIpAddress());
    }
    catch (\Exception $e) {
      throw new MAIBException(sprintf('MAIB error: %s', $e->getMessage()));
    }

    if (!empty($payment_info['error'])) {
      throw new MAIBException(sprintf('MAIB error: %s', $payment_info['error']));
    }

    if ($payment_info[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {
      $intent = $this->configuration['intent'] ?? NULL;
      if ($intent == 'authorize') {
        $payment->setState('authorization')->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
        $this->messenger()->addMessage($this->t('Your transaction was successful.'));
        \Drupal::logger('commerce_maib')
          ->notice('Completed authorization payment @payment with transaction id @trans_id for order @order. @data',
            [
              '@trans_id' => $transactionId,
              '@order' => $order->id(),
              '@payment' => $payment->id(),
              '@data' => Json::encode($payment_info),
            ]);
      }
      else {
        $payment->setState('completed')->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
        $this->messenger()->addMessage($this->t('Your transaction was successful.'));
        \Drupal::logger('commerce_maib')
          ->notice('Completed payment @payment with transaction id @trans_id for order @order. @data',
            [
              '@trans_id' => $transactionId,
              '@order' => $order->id(),
              '@payment' => $payment->id(),
              '@data' => Json::encode($payment_info),
            ]);
      }
    }
    elseif ($payment_info[MAIBGateway::MAIB_RESULT] != MAIBGateway::MAIB_RESULT_PENDING) {
      $this->messenger()->addError($this->t('Your transaction was cancelled. Remote status: @status', [
        '@status' => $payment_info[MAIBGateway::MAIB_RESULT],
      ]));
      \Drupal::logger('commerce_maib')->error(
        'Voided payment @payment with transaction id @trans_id for order @order. Remote status was @remote. @data',
        [
          '@trans_id' => $transactionId,
          '@order' => $order->id(),
          '@payment' => $payment->id(),
          '@remote' => $payment_info[MAIBGateway::MAIB_RESULT],
          '@data' => Json::encode($payment_info),
        ]);
      $payment->delete();

      throw new MAIBException(sprintf('Payment failed. Remote status: %s. Remote reason: %s.',
        $payment_info[MAIBGateway::MAIB_RESULT],
        $payment_info[MAIBGateway::MAIB_RESULT_CODE],
      ));
    }
    else {
      $payment->setState('pending')->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
      $this->messenger()->addMessage($this->t('Your transaction is still in pending process. Please check its status later.'));
    }
  }

  /**
   * Gets the redirect URL.
   *
   * @return string
   *   The redirect URL.
   */
  public function getRedirectUrl() {
    if ($this->getMode() == 'test') {
      return MaibClient::MAIB_TEST_REDIRECT_URL;
    }
    else {
      return MaibClient::MAIB_LIVE_REDIRECT_URL;
    }
  }

  /**
   * Gets endpoint URI.
   *
   * @return string
   *   The endpoint URI.
   */
  public function getBaseUri() {
    if ($this->getMode() == 'test') {
      return MaibClient::MAIB_TEST_BASE_URI;
    }
    else {
      return MaibClient::MAIB_LIVE_BASE_URI;
    }
  }

  /**
   * Gets MAIB Client object.
   *
   * @return \Maib\MaibApi\MaibClient
   *   Return MAIB client object with required values.
   *
   * @throws \Exception
   */
  public function getClient(): MaibClient {
    $configuration = $this->getConfiguration();
    $options = [
      'base_uri' => $this->getBaseUri(),
      'debug' => FALSE,
      'verify' => TRUE,
      'cert' => $configuration['public_key_path'],
      'ssl_key' => [
        $configuration['private_key_path'],
        $configuration['private_key_password'],
      ],
      'config' => [
        'curl' => [
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_SSL_VERIFYPEER => TRUE,
        ],
      ],
    ];

    if (!empty($configuration['debug']) && !empty($configuration['debug_file'])) {
      $log = new Logger('maib_guzzle_request');
      $log->pushHandler(new StreamHandler($configuration['debug_file'], Logger::DEBUG));
      $stack = HandlerStack::create();
      $stack->push(
        Middleware::log($log, new MessageFormatter(MessageFormatter::DEBUG))
      );
      $options['handler'] = $stack;
    }

    $guzzleClient = new Client($options);
    $client = new MaibClient($guzzleClient);

    return $client;
  }

  /**
   * Store payment with transaction id from remote before redirect.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order object.
   * @param string $transactionId
   *   Transition id.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   Commerce payment object.
   */
  public function storePendingPayment(OrderInterface $order, string $transactionId): PaymentInterface {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'new',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $transactionId,
      'remote_state' => MAIBGateway::MAIB_RESULT_CREATED,
    ]);
    $payment->save();

    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // Perform the capture request here, throw an exception if it fails.
    try {
      $transaction_id = $payment->getRemoteId();
      $decimal_amount = $amount->getNumber();
      $currency = $payment->getAmount()->getCurrencyCode();

      /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currencyObj*/
      $currencyObj = \Drupal::entityTypeManager()->getStorage('commerce_currency')->load($currency);
      $clientIpAddr = $payment->getOrder()->getIpAddress();
      $description = (string) $this->t('Order #@id', ['@id' => $payment->getOrderId()]);
      $language = \Drupal::languageManager()->getCurrentLanguage()->getId();

      $result = $this->getClient()->makeDMSTrans($transaction_id, $decimal_amount, $currencyObj->getNumericCode(), $clientIpAddr, $description, $language);
    }
    catch (\Exception $e) {
      throw new MAIBException(sprintf('MAIB error: %s', $e->getMessage()));
    }

    if ($result[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {
      $payment->setState('completed');
      $payment->setRemoteState($result[MAIBGateway::MAIB_RESULT]);
      $payment->setAmount($amount);
      $payment->save();
      \Drupal::logger('commerce_maib')
        ->notice('Completed authorized payment @payment with transaction id @trans_id for order @order and amount @amount @curr',
          [
            '@trans_id' => $payment->getRemoteId(),
            '@order' => $payment->getOrder()->id(),
            '@payment' => $payment->id(),
            '@amount' => $decimal_amount,
            '@curr' => $currencyObj->id(),
          ]);
    }

    else {
      throw new MAIBException(sprintf('MAIB result not OK: %s', Json::encode($result)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization']);
    $transactionId = $payment->getRemoteId();
    try {
      $result = $this->getClient()->revertTransaction($transactionId, $payment->getAmount()->getNumber());
    }
    catch (\Exception $e) {
      throw new MAIBException(sprintf('MAIB error: %s', $e->getMessage()));
    }

    if (!empty($result['error'])) {
      throw new MAIBException(sprintf('MAIB error: %s', $result['error']));
    }
    if ($result[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {
      \Drupal::logger('commerce_maib')
        ->notice('Voided payment @payment with transaction id @trans_id for order @order',
          [
            '@trans_id' => $transactionId,
            '@order' => $payment->getOrder()->id(),
            '@payment' => $payment->id(),
          ]);
      $payment->delete();
    }
    else {
      throw new MAIBException(sprintf('MAIB result not OK: %s', Json::encode($result)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL): void {
    try {
      $this->assertPaymentState($payment, ['completed']);
      $amount = $amount ?: $payment->getAmount();
      $this->assertRefundAmount($payment, $amount);
    }
    catch (\Exception $e) {
      throw new MAIBException(sprintf('Refund error: %s', $e->getMessage()));
    }

    try {
      // MAIB only support full refund for the payment of the authorization.
      $result = $this->getClient()->revertTransaction($payment->getRemoteId(), $amount->getNumber());
    }
    catch (\Exception $e) {
      throw new MAIBException(sprintf('MAIB error%s', $e->getMessage()));
    }

    if (!empty($result['error'])) {
      throw new MAIBException(sprintf('MAIB error: %s', $result['error']));
    }
    // If MAIB response was OK do refund.
    if ($result[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {

      $payment->setState('refunded');
      $payment->setRefundedAmount($amount);
      $payment->save();

      \Drupal::logger('commerce_maib')
        ->notice('Refunded payment @payment with transaction id @trans_id for order @order. Data: @data.',
          [
            '@trans_id' => $payment->getRemoteId(),
            '@order' => $payment->getOrder()->id(),
            '@payment' => $payment->id(),
            '@data' => Json::encode($result),
          ]);
    }
    else {
      throw new MAIBException(sprintf('MAIB result not OK: %s', Json::encode($result)));
    }
  }

}
