<?php

namespace Drupal\neo;

use Drupal\Component\Utility\NestedArray;

/**
 * Provides methods to manage values.
 */
trait ValuesTrait {

  /**
   * Implements \Drupal\neo\ValuesInterface::getValues()
   */
  abstract public function &getValues();

  /**
   * Implements \Drupal\neo\ValuesInterface::getValue()
   */
  public function &getValue($key, $default = NULL) {
    $exists = NULL;
    $value = &NestedArray::getValue($this->getValues(), (array) $key, $exists);
    if (!$exists) {
      $value = $default;
    }
    return $value;
  }

  /**
   * Implements \Drupal\neo\ValuesInterface::setValues()
   */
  public function setValues(array $values) {
    $existingValues = &$this->getValues();
    $existingValues = $values;
    return $this;
  }

  /**
   * Implements \Drupal\neo\ValuesInterface::setValue()
   */
  public function setValue($key, $value) {
    NestedArray::setValue($this->getValues(), (array) $key, $value, TRUE);
    return $this;
  }

  /**
   * Implements \Drupal\neo\ValuesInterface::unsetValue()
   */
  public function unsetValue($key) {
    NestedArray::unsetValue($this->getValues(), (array) $key);
    return $this;
  }

  /**
   * Implements \Drupal\neo\ValuesInterface::hasValue()
   */
  public function hasValue($key) {
    $exists = NULL;
    $value = NestedArray::getValue($this->getValues(), (array) $key, $exists);
    return $exists && isset($value);
  }

  /**
   * Implements \Drupal\neo\ValuesInterface::isValueEmpty()
   */
  public function isValueEmpty($key) {
    $exists = NULL;
    $value = NestedArray::getValue($this->getValues(), (array) $key, $exists);
    return !$exists || empty($value);
  }

}
