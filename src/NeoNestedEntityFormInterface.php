<?php

namespace Drupal\neo;

/**
 * Defines an object which is used to store instance settings.
 */
interface NeoNestedEntityFormInterface {

  /**
   * Gets the inner form key.
   *
   * @param string $key
   *   Inner form key.
   *
   * @return string
   *   Inner form key.
   */
  public function getInnerFormKey($key);

  /**
   * Gets the inner form parents.
   *
   * @param string $key
   *   Inner form key.
   *
   * @return array
   *   Inner form parents key.
   */
  public function getInnerFormParents($key);

  /**
   * Sets the inner form key.
   *
   * @param string $key
   *   Inner form key.
   */
  public function setInnerFormKey($key);

  /**
   * Sets the inner form parents.
   *
   * @param string $key
   *   Inner form key.
   * @param array $parents
   *   The inner form parents key.
   */
  public function setInnerFormParents($key, $parents);

}
