<?php

namespace Drupal\commerce_maib\Controller;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\commerce_maib\MAIBGateway;
use Drupal\commerce_maib\Exception\MAIBException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Provides checkout endpoints for off-site payments.
 *
 * @package Drupal\commerce_maib\Controller
 */
class PaymentCheckoutController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The checkout order manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   */
  protected CheckoutOrderManagerInterface $checkoutOrderManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The commerce payment logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $loggerPayment;

  /**
   * The commerce maib logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $loggerMaib;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type manager.
   *
   * @var Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The cart session.
   *
   * @var \Drupal\commerce_cart\CartSessionInterface
   */
  protected CartSessionInterface $cartSession;

  /**
   * Constructs a new PaymentCheckoutController object.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManagerInterface $checkout_order_manager
   *   The checkout order manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory object.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager object.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Request stack object.
   * @param \Drupal\commerce_cart\CartSessionInterface $cart_session
   *   Commerce session object.
   */
  public function __construct(
    CheckoutOrderManagerInterface $checkout_order_manager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $requestStack,
    CartSessionInterface $cart_session
  ) {
    $this->checkoutOrderManager = $checkout_order_manager;
    $this->messenger = $messenger;
    $this->loggerPayment = $logger_factory->get('commerce_payment');
    $this->loggerMaib = $logger_factory->get('commerce_maib');
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $requestStack;
    $this->cartSession = $cart_session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('messenger'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('commerce_cart.cart_session')
    );
  }

  /**
   * Provides the "return" checkout payment page.
   *
   * Redirects to the next checkout page, completing checkout.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function returnPage(Request $request, RouteMatchInterface $route_match): void {
    $transaction_id = $this->getTransactionId($request);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->getPayment($transaction_id);

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    $this->redirectToCheckoutFinishedUrl($order, 'return', $transaction_id);

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->get('payment_gateway')->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
      throw new AccessException(
        'The payment gateway for the order does not implement ' . OffsitePaymentGatewayInterface::class
      );
    }
    try {
      $payment_gateway_plugin->onReturn($order, $request);
    }
    catch (PaymentGatewayException $e) {
      $this->loggerPayment->error($e->getMessage());
      $this->messenger->addError($this->t(
        'Payment failed at the payment server. Please review your information and try again.'
      ));
    }

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $this->checkoutOrderManager->getCheckoutFlow($order);
    $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $redirect_step_id = $checkout_flow_plugin->getNextStepId($step_id);

    $checkout_flow_plugin->redirectToStep($redirect_step_id);
  }

  /**
   * Provides the "cancel" checkout payment page.
   *
   * Redirects to the previous checkout page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function cancelPage(Request $request, RouteMatchInterface $route_match): void {
    $transaction_id = $this->getTransactionId($request);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->getPayment($transaction_id);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    $this->redirectToCheckoutFinishedUrl($order, 'cancel', $transaction_id);

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->get('payment_gateway')->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
      throw new AccessException(
        'The payment gateway for the order does not implement ' . OffsitePaymentGatewayInterface::class
      );
    }

    $this->loggerMaib->notice('Voided payment @payment with transaction id @trans_id for order @order',
        [
          '@trans_id' => $transaction_id,
          '@order' => $order->id(),
          '@payment' => $payment->id(),
        ]
    );

    $payment->delete();
    $payment_gateway_plugin->onCancel($order, $request);

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
    $previous_step_id = $checkout_flow_plugin->getPreviousStepId($step_id);
    $checkout_flow_plugin->redirectToStep($previous_step_id);
  }

  /**
   * Get transaction ID from requrest.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string
   *   Transaction ID.
   */
  public function getTransactionId(Request $request): string {
    $transaction_id = $request->request->get(MAIBGateway::MAIB_TRANS_ID);
    if (empty($transaction_id)) {
      throw new MAIBException('MAIB return redirect error: Missing TRANSACTION_ID');
    }
    return $transaction_id;
  }

  /**
   * Get commerce payment based on MAIB transaction ID.
   *
   * @param string $transaction_id
   *   Transaction ID.
   *
   * @return \Drupal\commerce_payment\Entity\Payment
   *   The payment.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getPayment($transaction_id): Payment {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties([
      'remote_id' => $transaction_id,
      'payment_gateway' => commerce_maib_get_all_gateway_ids(),
    ]);
    if (empty($payments)) {
      throw new MAIBException(sprintf('MAIB error: failed to locate payment for TRANSACTION_ID %s', $transaction_id));
    }
    return reset($payments);
  }

  /**
   * Checks access for the form page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function checkAccess(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $transaction_id = $this->requestStack->getCurrentRequest()->get(MAIBGateway::MAIB_TRANS_ID);
    if (empty($transaction_id)) {
      $this->loggerMaib->notice('Return URL access without providing transaction ID. Data: @data.',
        ['@data' => Json::encode($this->requestStack->getCurrentRequest()->request->all())]);
      return AccessResult::forbidden();
    }

    /** @var \Drupal\commerce_payment\Entity\Payment $payment */
    $payment = $this->getPayment($transaction_id);

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    if ($order->getState()->getId() == 'canceled') {
      $this->loggerMaib->notice('Return URL access with transaction ID @trans_id for an cancelled order @order.',
        ['@trans_id' => $transaction_id, '@order' => $order->id()]
      );
      return AccessResult::forbidden()->addCacheableDependency($order);
    }

    $access = AccessResult::allowedIf($order->hasItems())
      ->andIf(AccessResult::allowedIfHasPermission($account, 'access checkout'))
      ->addCacheableDependency($order);

    return $access;
  }

  /**
   * Checkout flow plugin.
   *
   * This plugin does not work when commerce order
   * is not available as route param.
   *
   * A redirect to commerce return/cancel url will be performed.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $type
   *   Request type, cancel or return.
   * @param string $remote_id
   *   Remote id.
   *
   * @see https://www.drupal.org/project/commerce/issues/2931044
   *
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   */
  public function redirectToCheckoutFinishedUrl(OrderInterface $order, $type, $remote_id): void {
    throw new NeedsRedirectException(Url::fromRoute('commerce_payment.checkout.' . $type,
      [
        'commerce_order' => $order->id(),
        'step' => $order->get('checkout_step')->value,
      ],
      [
        'query' => [MAIBGateway::MAIB_TRANS_ID => $remote_id],
      ]
    )->toString());
  }

}
