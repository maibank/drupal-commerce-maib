<?php

namespace Drupal\commerce_maib\Exception;

use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * Exception for MAIB gateway errors.
 */
class MAIBException extends PaymentGatewayException {}
