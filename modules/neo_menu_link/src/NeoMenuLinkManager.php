<?php

namespace Drupal\neo_menu_link;

use Drupal\Core\Menu\MenuLinkManager;

/**
 * Modifies the language manager service.
 */
class NeoMenuLinkManager extends MenuLinkManager {

  /**
   * Performs extra processing on plugin definitions.
   *
   * By default we add defaults for the type to the definition. If a type has
   * additional processing logic, the logic can be added by replacing or
   * extending this method.
   *
   * @param array $definition
   *   The definition to be processed and modified by reference.
   * @param string $plugin_id
   *   The ID of the plugin this definition is being used for.
   */
  protected function processDefinition(array &$definition, $plugin_id) {
    // Use the eXo icon link class override.
    $this->defaults['class'] = 'Drupal\neo_menu_link\NeoMenuLinkDefault';
    $this->defaults['form_class'] = 'Drupal\neo_menu_link\Form\NeoMenuLinkDefaultForm';
    parent::processDefinition($definition, $plugin_id);
  }

}
