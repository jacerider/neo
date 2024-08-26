<?php

namespace Drupal\neo\Helpers;

use Drupal\Component\Utility\NestedArray as CoreNestedArray;

/**
 * Various array helpers.
 */
class NestedArray extends CoreNestedArray {

  /**
   * Merges multiple arrays, recursively, and returns the merged array.
   *
   * This function is similar to PHP's array_merge_recursive() function, but it
   * handles non-array values differently. When merging values that are not both
   * arrays, the latter value replaces the former rather than merging with it.
   *
   * Example:
   * @code
   * $link_options_1 = ['fragment' => 'x', 'attributes' => ['title' => t('X'), 'class' => ['a', 'b']]];
   * $link_options_2 = ['fragment' => 'y', 'attributes' => ['title' => t('Y'), 'class' => ['c', 'd']]];
   *
   * // This results in ['fragment' => ['x', 'y'], 'attributes' => ['title' => [t('X'), t('Y')], 'class' => ['a', 'b', 'c', 'd']]].
   * $incorrect = array_merge_recursive($link_options_1, $link_options_2);
   *
   * // This results in ['fragment' => 'y', 'attributes' => ['title' => t('Y'), 'class' => ['a', 'b', 'c', 'd']]].
   * $correct = NestedArray::mergeDeep($link_options_1, $link_options_2);
   * @endcode
   *
   * @param array ...
   *   Arrays to merge.
   *
   * @return array
   *   The merged array.
   *
   * @see NestedArray::mergeDeepArray()
   */
  public static function mergeDeepStrict() {
    return self::mergeDeepArrayStrict(func_get_args());
  }

  /**
   * Merges multiple arrays, recursively, and returns the merged array.
   *
   * This function is equivalent to NestedArray::mergeDeepStrict(), except the
   * input arrays are passed as a single array parameter rather than a variable
   * parameter list.
   *
   * This function is different than mergeDeepArray in that nested keyed arrays
   * will not be merged and will be preserved.
   *
   * The following are equivalent:
   * - NestedArray::mergeDeep($a, $b);
   * - NestedArray::mergeDeepArray(array($a, $b));
   *
   * The following are also equivalent:
   * - call_user_func_array('NestedArray::mergeDeep', $arrays_to_merge);
   * - NestedArray::mergeDeepArray($arrays_to_merge);
   *
   * @param array $arrays
   *   An arrays of arrays to merge.
   * @param bool $remove_null
   *   (optional) Will remove null values.
   *
   * @return array
   *   The merged array.
   *
   * @see NestedArray::mergeDeep()
   */
  public static function mergeDeepArrayStrict(array $arrays, $remove_null = TRUE) {
    $result = [];
    foreach ($arrays as $array) {
      foreach ($array as $key => $value) {
        // Unique handling of arrays with numeric keys. They should not be
        // merged.
        if (is_array($value) && is_int(key($value))) {
          $result[$key] = $value;
        }
        // Recurse when both values are arrays.
        elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
          $result[$key] = self::mergeDeepArrayStrict([$result[$key], $value], $remove_null);
        }
        // Otherwise, use the latter value, overriding any previous value.
        else {
          $result[$key] = $value;
        }
      }
    }
    if ($remove_null) {
      $result = array_filter($result, function ($value) {
        return !is_null($value);
      });
    }
    return $result;
  }

  /**
   * Computes recursively the intersection of arrays using keys for comparison.
   *
   * @param array $array1
   *   The the array with the main keys to check.
   * @param array $array2
   *   An array to compare keys against.
   *
   * @return array
   *   An associative array containing all the entries of array1 which have keys
   *   that are present in all arguments.
   */
  public static function insersectKeyDeepArray(array $array1, array $array2) {
    self::forceArrayValue($array1, $array2);
    foreach ($array1 as $key => $value) {
      if (substr($key, 0, 2) == '__') {
        $actual = substr($key, 2);
        $array1[$actual] = $value;
        $array2[$actual] = $value;
      }
    }
    $result = array_intersect_key($array1, $array2);
    foreach ($result as $key => $value) {
      if (is_array($value) && is_array($array2[$key])) {
        $result[$key] = self::insersectKeyDeepArray($value, $array2[$key]);
      }
      else {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  /**
   * Force array values from first array1 onto array2 if key starts with '__'.
   *
   * @param array $array1
   *   The the array with the main keys to check.
   * @param array $array2
   *   An array to compare keys against.
   */
  protected static function forceArrayValue(array &$array1, array &$array2) {
    foreach ($array1 as $key => $value) {
      if (substr($key, 0, 2) == '__') {
        $actual = substr($key, 2);
        $array1[$actual] = $value;
        $array2[$actual] = $value;
      }
    }
  }

  /**
   * Compare a settings array to another and return that which differs.
   *
   * @param array $array1
   *   First options array.
   * @param array $array2
   *   Second options array.
   *
   * @return array
   *   The settings containing only the results that differ.
   */
  public static function diffDeep(array $array1, array $array2) {
    $result = [];
    foreach ($array1 as $key => $value) {
      if (array_key_exists($key, $array2)) {
        if (is_array($value) && is_array($array2[$key])) {
          $aRecursiveDiff = self::diffDeep($value, $array2[$key]);
          if (count($aRecursiveDiff)) {
            $result[$key] = $aRecursiveDiff;
          }
        }
        else {
          if ($value != $array2[$key] || is_null($value) != is_null($array2[$key]) || gettype($value) !== gettype($array2[$key])) {
            $result[$key] = $value;
          }
        }
      }
      else {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  /**
   * Convert array keys to camel case.
   *
   * @param array $array
   *   The array to convert.
   *
   * @return array
   *   The converted array.
   */
  public static function keysToCamel(array $array) {
    $result = [];
    foreach ($array as $key => $value) {
      $key = Str::camel($key);
      if (is_array($value)) {
        $result[$key] = self::keysToCamel($value);
      }
      else {
        $result[$key] = $value;
      }
    }
    return $result;
  }

}
