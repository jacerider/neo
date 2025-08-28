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

  /**
   * Process callback for entity_autocomplete elements.
   */
  public static function entityAutocomplete(array $element, FormStateInterface $form_state, &$complete_form) {
    if (!empty($element['#ajax']) && empty($element['#ajax']['event'])) {
      $element['#ajax']['event'] = 'autocompleteclose';
    }
    $element['#process'] = array_filter($element['#process'], function ($process) {
      if (is_array($process) && in_array($process[1], ['processEntityAutocomplete', 'processAutocomplete'])) {
        return FALSE;
      }
      return TRUE;
    });
    return $element;
  }

}
