<?php

declare(strict_types=1);

namespace Drupal\neo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the language manager service.
 */
class NeoServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('entity.autocomplete_matcher')) {
      // Replace the default entity autocomplete matcher with Neo's custom one.
      // This allows the autocomplete matches to return as an array instead of
      // a string. When returning as an array, the 'value' key is required and
      // should be set to the label of the entity.
      // The 'option' key can be used to provide additional HTML for the
      // autocomplete dropdown.
      $definition = $container->getDefinition('entity.autocomplete_matcher');
      $definition->setClass('Drupal\neo\NeoEntityAutocompleteMatcher');
    }
  }

}
