<?php

namespace Drupal\neo;

/**
 * Defines the interface for Neo visibility plugin managers.
 */
interface VisibilityEntityPluginInterface {

  /**
   * Returns the plugin instance.
   *
   * @return mixed
   *   The plugin instance for this entity.
   */
  public function getPlugin();

}
