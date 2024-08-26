<?php

namespace Drupal\neo;

/**
 * Provides an interface for value management.
 */
interface ValuesInterface {

  /**
   * Returns the values.
   *
   * @return array
   *   An associative array of values.
   */
  public function &getValues();

  /**
   * Returns the value for a specific key.
   *
   * @param string|array $key
   *   Values are stored as a multi-dimensional associative array. If $key is a
   *   string, it will return $values[$key]. If $key is an array, each element
   *   of the array will be used as a nested key. If $key = array('foo', 'bar')
   *   it will return $values['foo']['bar'].
   * @param mixed $default
   *   (optional) The default value if the specified key does not exist.
   *
   * @return mixed
   *   The value for the given key, or NULL.
   */
  public function &getValue($key, $default = NULL);

  /**
   * Sets the values.
   *
   * @param array $values
   *   The multi-dimensional associative array of values.
   *
   * @return $this
   */
  public function setValues(array $values);

  /**
   * Sets the value for a specific key.
   *
   * @param string|array $key
   *   Values are stored as a multi-dimensional associative array. If $key is a
   *   string, it will use $values[$key] = $value. If $key is an array, each
   *   element of the array will be used as a nested key. If
   *   $key = array('foo', 'bar') it will use $values['foo']['bar'] = $value.
   * @param mixed $value
   *   The value to set.
   *
   * @return $this
   */
  public function setValue($key, $value);

  /**
   * Removes a specific key from the values.
   *
   * @param string|array $key
   *   Values are stored as a multi-dimensional associative array. If $key is a
   *   string, it will use unset($values[$key]). If $key is an array, each
   *   element of the array will be used as a nested key. If
   *   $key = array('foo', 'bar') it will use unset($values['foo']['bar']).
   *
   * @return $this
   */
  public function unsetValue($key);

  /**
   * Determines if a specific key is present in the values.
   *
   * @param string|array $key
   *   Values are stored as a multi-dimensional associative array. If $key is a
   *   string, it will return isset($values[$key]). If $key is an array, each
   *   element of the array will be used as a nested key. If
   *   $key = array('foo', 'bar') it will return isset($values['foo']['bar']).
   *
   * @return bool
   *   TRUE if the $key is set, FALSE otherwise.
   */
  public function hasValue($key);

  /**
   * Determines if a specific key has a value in the values.
   *
   * @param string|array $key
   *   Values are stored as a multi-dimensional associative array. If $key is a
   *   string, it will return empty($values[$key]). If $key is an array, each
   *   element of the array will be used as a nested key. If
   *   $key = array('foo', 'bar') it will return empty($values['foo']['bar']).
   *
   * @return bool
   *   TRUE if the $key has no value, FALSE otherwise.
   */
  public function isValueEmpty($key);

}
