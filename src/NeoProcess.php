<?php

namespace Drupal\neo;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;

/**
 * Implements trusted prerender callbacks for the Neo.
 *
 * @internal
 */
class NeoProcess {

  /**
   * Process callback for details elements.
   */
  public static function details(array $element, FormStateInterface $form_state, &$complete_form) {
    if (!empty($element['#group'])) {
      $path = explode('][', $element['#group']);
      $group = NestedArray::getValue($complete_form, $path);
      if ($group && $group['#type'] === 'accordion') {
        $element['#theme_wrappers'] = ['accordion_item'];
      }
    }
    return $element;
  }

}
