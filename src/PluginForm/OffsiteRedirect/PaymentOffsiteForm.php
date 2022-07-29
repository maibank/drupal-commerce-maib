<?php

namespace Drupal\commerce_maib\PluginForm\OffsiteRedirect;

use Drupal\commerce_maib\Exception\MAIBException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_maib\MAIBGateway;

/**
 * Class PaymentOffsiteForm for Maib.
 *
 * @package Drupal\commerce_maib\PluginForm\OffsiteRedirect
 *
 * @return array $form
 *   The base payment offsite form for maib.
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $payment->getPaymentGateway();
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();

    $form['#capture'] = isset($configuration['intent']) && $configuration['intent'] == 'capture';

    $redirect_url = $payment_gateway_plugin->getRedirectUrl();
    $redirect_method = PaymentOffsiteForm::REDIRECT_POST;

    $capture = !empty($form['#capture']);
    $currency = $payment->getAmount()->getCurrencyCode();
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currencyObj */
    $currencyObj = \Drupal::entityTypeManager()->getStorage('commerce_currency')->load($currency);
    $amount = $payment->getAmount()->getNumber();

    $client_ip_addr = $payment->getOrder()->getIpAddress();
    $description = (string) $this->t('Order #@id', ['@id' => $payment->getOrderId()]);
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $transaction_id = NULL;
    try {
      $client = $payment_gateway_plugin->getClient();

      if ($capture) {
        $response = $client->registerSmsTransaction($amount, $currencyObj->getNumericCode(), $client_ip_addr, $description, $language);
      }
      else {
        $response = $client->registerDmsAuthorization($amount, $currencyObj->getNumericCode(), $client_ip_addr, $description, $language);
      }

      if (isset($response['error'])) {
        throw new MAIBException(sprintf('MAIB error: %s', $response['error']));
      }
      elseif (!isset($response[MAIBGateway::MAIB_TRANSACTION_ID])) {
        throw new MAIBException('MAIB error: Missing TRANSACTION_ID');
      }
      else {
        $transaction_id = $response[MAIBGateway::MAIB_TRANSACTION_ID];
      }

      $pending_payment = $payment_gateway_plugin->storePendingPayment($payment->getOrder(), $transaction_id);
    }
    catch (\Exception $e) {
      \Drupal::logger('commerce_maib')->error($e->getMessage());
      throw new MAIBException(sprintf('MAIB error: %s', $e->getMessage()));
    }

    \Drupal::logger('commerce_maib')->notice($this->t(
      'Got transaction id @trans_id for order @order and payment @payment',
      [
        '@trans_id' => $transaction_id,
        '@order' => $payment->getOrderId(),
        '@payment' => $pending_payment->id(),
      ])
    );

    $data = [
      MAIBGateway::MAIB_TRANS_ID => $transaction_id,
    ];

    $form = $this->buildRedirectForm($form, $form_state, $redirect_url, $data, $redirect_method);

    return $form;
  }

}
