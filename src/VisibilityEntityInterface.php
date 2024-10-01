<?php

namespace Drupal\neo;

use Drupal\Core\Entity\EntityWithPluginCollectionInterface;

/**
 * Defines the interface for Neo theme plugin managers.
 */
interface VisibilityEntityInterface extends EntityWithPluginCollectionInterface {

  /**
   * Returns an array of visibility condition configurations.
   *
   * @return array
   *   An array of visibility condition configuration keyed by the condition ID.
   */
  public function getVisibility();

  /**
   * Gets conditions for this toolbar.
   *
   * @return \Drupal\Core\Condition\ConditionInterface[]|\Drupal\Core\Condition\ConditionPluginCollection
   *   An array or collection of configured condition plugins.
   */
  public function getVisibilityConditions();

  /**
   * Gets a visibility condition plugin instance.
   *
   * @param string $instance_id
   *   The condition plugin instance ID.
   *
   * @return \Drupal\Core\Condition\ConditionInterface
   *   A condition plugin.
   */
  public function getVisibilityCondition($instance_id);

  /**
   * Sets the visibility condition configuration.
   *
   * @param string $instance_id
   *   The condition instance ID.
   * @param array $configuration
   *   The condition configuration.
   *
   * @return $this
   */
  public function setVisibilityConfig($instance_id, array $configuration);

}
