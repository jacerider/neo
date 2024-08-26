<?php

namespace Drupal\neo\Helpers;

/**
 * Various string helpers.
 *
 * @see https://github.com/laravel/framework/blob/78eb4dabcc03e189620c16f436358d41d31ae11f/src/Illuminate/Support/Str.php#L525
 */
class Str {

  /**
   * The cache of snake-cased words.
   *
   * @var array
   */
  protected static $snakeCache = [];

  /**
   * The cache of camel-cased words.
   *
   * @var array
   */
  protected static $camelCache = [];

  /**
   * The cache of studly-cased words.
   *
   * @var array
   */
  protected static $studlyCache = [];

  /**
   * Convert the given string to lower-case.
   *
   * @param string $value
   *   The string to convert.
   *
   * @return string
   *   The lower-case string.
   */
  public static function lower($value) {
    return mb_strtolower($value, 'UTF-8');
  }

  /**
   * Convert a string to snake case.
   *
   * @param string $value
   *   The string to convert.
   * @param string $delimiter
   *   The delimiter.
   *
   * @return string
   *   The snake-cased string.
   */
  public static function snake($value, $delimiter = '_') {
    $key = $value;

    if (isset(static::$snakeCache[$key][$delimiter])) {
      return static::$snakeCache[$key][$delimiter];
    }

    if (!ctype_lower($value)) {
      $value = preg_replace('/\s+/u', '', ucwords($value));

      $value = static::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
    }

    return static::$snakeCache[$key][$delimiter] = $value;
  }

  /**
   * Convert a value to studly caps case.
   *
   * @param string $value
   *   The string to convert.
   *
   * @return string
   *   The studly-cased string.
   */
  public static function studly($value) {
    $key = $value;

    if (isset(static::$studlyCache[$key])) {
      return static::$studlyCache[$key];
    }

    $value = ucwords(str_replace(['-', '_'], ' ', $value));

    return static::$studlyCache[$key] = str_replace(' ', '', $value);
  }

  /**
   * Convert a value to camel case.
   *
   * @param string $value
   *   The string to convert.
   *
   * @return string
   *   The camel-cased string.
   */
  public static function camel($value) {
    if (isset(static::$camelCache[$value])) {
      return static::$camelCache[$value];
    }

    return static::$camelCache[$value] = lcfirst(static::studly($value));
  }

}
