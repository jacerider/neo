<?php

namespace Drupal\neo;

use Drupal\Component\Render\MarkupInterface;
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
   * Prerender callback for disabling-on-click elements.
   */
  public static function disable($element) {
    if (!empty($element['#disabled_on_click'])) {
      $element['#attached']['library'][] = 'neo/disable';
      $element['#attributes']['class'][] = 'neo-disable';
      if (is_string($element['#disabled_on_click']) || $element['#disabled_on_click'] instanceof MarkupInterface) {
        $element['#attributes']['data-neo-disable-message'] = $element['#disabled_on_click'];
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'view',
      'disable',
    ];
  }

}
