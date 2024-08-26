<?php

namespace Drupal\neo;

use Drupal\Core\Condition\ConditionPluginCollection;

/**
 * Provides visibility form helpers.
 */
trait VisibilityEntityTrait {

  /**
   * The visibility settings for this block.
   *
   * @var array
   */
  protected $visibility = [];

  /**
   * The visibility collection.
   *
   * @var \Drupal\Core\Condition\ConditionPluginCollection
   */
  protected $visibilityCollection;

  /**
   * The condition plugin manager.
   *
   * @var \Drupal\Core\Executable\ExecutableManagerInterface
   */
  protected $conditionPluginManager;

  /**
   * {@inheritdoc}
   */
  public function getVisibility() {
    return $this->getVisibilityConditions()->getConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setVisibilityConfig($instance_id, array $configuration) {
    $conditions = $this->getVisibilityConditions();
    if (!$conditions->has($instance_id)) {
      $configuration['id'] = $instance_id;
      $conditions->addInstanceId($instance_id, $configuration);
    }
    else {
      $conditions->setInstanceConfiguration($instance_id, $configuration);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibilityConditions() {
    if (!isset($this->visibilityCollection)) {
      $this->visibilityCollection = new ConditionPluginCollection($this->conditionPluginManager(), $this->get('visibility'));
    }
    return $this->visibilityCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibilityCondition($instance_id) {
    return $this->getVisibilityConditions()->get($instance_id);
  }

  /**
   * Gets the condition plugin manager.
   *
   * @return \Drupal\Core\Executable\ExecutableManagerInterface
   *   The condition plugin manager.
   */
  protected function conditionPluginManager() {
    if (!isset($this->conditionPluginManager)) {
      $this->conditionPluginManager = \Drupal::service('plugin.manager.condition');
    }
    return $this->conditionPluginManager;
  }

}
