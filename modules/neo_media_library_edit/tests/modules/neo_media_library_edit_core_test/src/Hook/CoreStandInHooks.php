<?php

declare(strict_types=1);

namespace Drupal\neo_media_library_edit_core_test\Hook;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Render\Element;

/**
 * Stands in for core's own edit button, as merge request !4517 adds it.
 *
 * Only the shape neo_media_library_edit detects is reproduced: an
 * `edit_button` on each selected item, and an AJAX callback on the media
 * form's Save.
 */
class CoreStandInHooks {

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   */
  #[Hook('field_widget_single_element_media_library_widget_form_alter', order: Order::First)]
  public function widgetAlter(array &$element, FormStateInterface $form_state, array $context): void {
    if (empty($element['selection'])) {
      return;
    }
    foreach (Element::children($element['selection']) as $delta) {
      $element['selection'][$delta]['edit_button'] = [
        '#type' => 'submit',
        '#value' => 'Edit',
        '#attributes' => [
          'class' => ['edit-media', 'use-ajax'],
          'data-core-stand-in' => '',
        ],
      ];
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for media_form.
   */
  #[Hook('form_media_form_alter', order: Order::First)]
  public function formMediaFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form['actions']['submit']['#ajax']['callback'] = [static::class, 'callback'];
  }

  /**
   * The stand-in AJAX callback.
   */
  public static function callback(array &$form, FormStateInterface $form_state): AjaxResponse {
    return new AjaxResponse();
  }

}
