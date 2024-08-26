<?php

namespace Drupal\neo\Helpers;

use Drupal\Core\Language\LanguageInterface;

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

  /**
   * Convert string to machine name.
   *
   * @param string $value
   *   The string to convert.
   * @param string $delimiter
   *   The delimiter to use.
   *
   * @return string
   *   The converted string.
   */
  public static function toMachineName($value, $delimiter = '_') {
    $new_value = \Drupal::service('transliteration')->transliterate($value, LanguageInterface::LANGCODE_DEFAULT, '_');
    $new_value = strtolower($new_value);
    $new_value = preg_replace('/[^a-z0-9_]+/', '_', $new_value);
    return rtrim(preg_replace('/_+/', $delimiter, $new_value), $delimiter);
  }

}
