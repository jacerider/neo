<?php

namespace Drupal\neo;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Implements trusted prerender callbacks for the Claro theme.
 *
 * @internal
 */
class NeoPreRender implements TrustedCallbackInterface {

  /**
   * Prerender callback for table.
   */
  public static function view($element) {
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $element['#view'] ?? NULL;
    if ($view && $view->getStyle()->getPluginId() === 'neo_clean') {
      // Remove container wrappers when using clean.
      foreach ($element['view_build']['#theme'] as &$theme) {
        $theme = str_replace('views_view', 'views_neo_clean', $theme);
      }
      $element['#theme_wrappers'] = [];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'view',
    ];
  }

}
