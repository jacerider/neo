<?php

namespace Drupal\neo\Helpers;

/**
 * Various helpers.
 */
class Utilities {

  /**
   * Is admin flag.
   *
   * @var bool
   */
  public static $isAdmin;

  /**
   * Check if we are on admin theme.
   *
   * @return bool
   *   Returns TRUE if we are using admin theme.
   */
  public static function isAdmin() {
    if (!isset(static::$isAdmin)) {
      /** @var \Drupal\Core\Routing\AdminContext $admin_context */
      static::$isAdmin = \Drupal::service('router.admin_context')->isAdminRoute() && \Drupal::currentUser()->hasPermission('view the administration theme');
    }
    return static::$isAdmin;
  }

}
