<?php

namespace Drupal\commerce_maib\Plugin\QueueWorker;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_maib\MAIBGateway;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Check and process stalled payment transactions.
 *
 * @QueueWorker(
 *   id = "commerce_maib_queue",
 *   title = @Translation("Check stalled payment transactions"),
 *   cron = {"time" = 10}
 * )
 */
class PaymentWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The payment storage.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Creates a new PaymentWorker object.
   *
   * @param array $configuration
   *   Array with configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param string $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    string $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $loggerChannelFactory->get('commerce_maib');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($payment_id): void {
    /** @var \Drupal\commerce_payment\Entity\Payment $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($payment_id);

    $payment_state_id = ($payment && $payment->getState()) ? $payment->getState()->getId() : '-';
    if ($payment && $payment_state_id == 'new') {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $maib_payment_gateway */
      $maib_payment_gateway = $payment->getPaymentGateway();
      /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $maib_payment_gateway_plugin */
      $maib_payment_gateway_plugin = $maib_payment_gateway->getPlugin();
      $payment_has_order = $payment->getOrder() instanceof OrderInterface;

      try {
        if (!$maib_payment_gateway_plugin) {
          throw new \Exception('Failed to load payment gateway for stalled payment id ', $payment_id);
        }

        $configuration = $maib_payment_gateway_plugin->getConfiguration();
        $intent = $configuration['intent'] ?? '';
        $ip_address = $payment_has_order ? $payment->getOrder()->getIpAddress() : '';

        $payment_info = $maib_payment_gateway_plugin->getClient()->getTransactionResult($payment->getRemoteId(), $ip_address);
        $remote_status = $payment_info[MAIBGateway::MAIB_RESULT] ?? NULL;

        if ($remote_status == MAIBGateway::MAIB_RESULT_OK) {
          $proper_state = ($intent == 'authorize') ? 'authorization' : 'completed';
          $payment->setState($proper_state)->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
          $this->logger
            ->warning('Completed stalled payment @payment with transaction id @trans_id for order @order',
              [
                '@trans_id' => $payment->getRemoteId(),
                '@order' => $payment_has_order ? $payment->getOrder()->id() : '-',
                '@payment' => $payment->id(),
              ]);
        }
        elseif (in_array($remote_status, [
          MAIBGateway::MAIB_RESULT_FAILED,
          MAIBGateway::MAIB_RESULT_DECLINED,
          MAIBGateway::MAIB_RESULT_REVERSED,
          MAIBGateway::MAIB_RESULT_AUTOREVERSED,
          MAIBGateway::MAIB_RESULT_TIMEOUT,
        ])) {
          $this->logger
            ->warning('Voided stalled payment @payment with transaction id @trans_id for order @order remote status @remote',
              [
                '@trans_id' => $payment->getRemoteId(),
                '@order' => $payment_has_order ? $payment->getOrder()->id() : '-',
                '@payment' => $payment->id(),
                '@remote' => $remote_status,
              ]);
          $payment->delete();
          // @todo cancel order.
        }
        elseif (!in_array(
            $remote_status,
            [
              MAIBGateway::MAIB_RESULT_CREATED,
              MAIBGateway::MAIB_RESULT_PENDING,
            ]
          )
        ) {
          $this->logger
            ->error('Failed to fetch payment info for @payment with transaction id @trans_id for order @order. Reomte data: @data.',
              [
                '@trans_id' => $payment->getRemoteId(),
                '@order' => $payment_has_order ? $payment->getOrder()->id() : '-',
                '@payment' => $payment->id(),
                '@data' => Json::encode($payment_info),
              ]);
        }
      }
      catch (\Exception $e) {
        $this->logger->error($e->getMessage());
      }
    }
  }

}
