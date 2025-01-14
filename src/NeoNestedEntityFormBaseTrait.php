<?php

namespace Drupal\neo;

/**
 * Provides a helper to for nesting entity forms.
 *
 * @internal
 */
trait NeoNestedEntityFormBaseTrait {

  /**
   * The inner form key.
   *
   * Used by NeoNestedEntityFormTrait. Should be applied to base form.
   *
   * @var string
   *   The inner form key.
   */
  public $innerFormKey = '';

  /**
   * The inner form parents.
   *
   * Used by NeoNestedEntityFormTrait. Should be applied to base form.
   *
   * @var array
   *   The inner form parents.
   */
  public $innerFormParents = [];

  /**
   * {@inheritdoc}
   */
  public function getInnerFormKey($key) {
    return $this->innerFormKey;
  }

  /**
   * {@inheritdoc}
   */
  public function getInnerFormParents($key) {
    return $this->innerFormParents;
  }

  /**
   * {@inheritdoc}
   */
  public function setInnerFormKey($key) {
    $this->innerFormKey = $key;
  }

  /**
   * {@inheritdoc}
   */
  public function setInnerFormParents($key, $parents) {
    $this->innerFormParents = $parents;
  }

}
