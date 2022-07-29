<?php

namespace Drupal\commerce_maib;

/**
 * Provides MAIB related constants.
 *
 * @package Drupal\commerce_maib
 */
class MAIBGateway {

  const MAIB_PLUGIN_ID = 'maib_redirect';

  const MAIB_TRANS_ID = 'trans_id';

  const MAIB_TRANSACTION_ID = 'TRANSACTION_ID';

  const MAIB_RESULT = 'RESULT';

  /**
   * Successfully completed transaction.
   */
  const MAIB_RESULT_OK = 'OK';

  /**
   * Transaction has failed.
   */
  const MAIB_RESULT_FAILED = 'FAILED';

  /**
   * Transaction just registered in the system.
   */
  const MAIB_RESULT_CREATED = 'CREATED';

  /**
   * Transaction is not accomplished yet.
   */
  const MAIB_RESULT_PENDING = 'PENDING';

  /**
   * Transaction declined by ECOMM.
   *
   * Because ECI is in blocked ECI list (ECOMM server side configuration).
   */
  const MAIB_RESULT_DECLINED = 'DECLINED';

  /**
   * Transaction is reversed.
   */
  const MAIB_RESULT_REVERSED = 'REVERSED';

  /**
   * Transaction is reversed by auto reversal.
   */
  const MAIB_RESULT_AUTOREVERSED = 'AUTOREVERSED';

  /**
   * Transaction was timed out.
   */
  const MAIB_RESULT_TIMEOUT = 'TIMEOUT';

  const MAIB_RESULT_CODE = 'RESULT_CODE';

}
