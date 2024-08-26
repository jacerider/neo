<?php

namespace Drupal\neo;

use Drupal\Core\Session\AccountInterface;

/**
 * Defines the interface for eXo theme plugin managers.
 */
interface VisibilityPluginInterface {

  /**
   * Indicates whether the plugin should be shown.
   *
   * This method allows base implementations to add general access restrictions
   * that should apply to all extending plugin plugins.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user session for which to check access.
   * @param bool $return_as_object
   *   (optional) Defaults to FALSE.
   *
   * @return bool|\Drupal\Core\Access\AccessResultInterface
   *   The access result. Returns a boolean if $return_as_object is FALSE (this
   *   is the default) and otherwise an AccessResultInterface object.
   *   When a boolean is returned, the result of AccessInterface::isAllowed() is
   *   returned, i.e. TRUE means access is explicitly allowed, FALSE means
   *   access is either explicitly forbidden or "no opinion".
   */
  public function access(AccountInterface $account, $return_as_object = FALSE);

}
