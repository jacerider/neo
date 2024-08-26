<?php

namespace Drupal\neo\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Textfield;

/**
 * Renders coloris widget.
 *
 * @FormElement("coloris")
 */
class Coloris extends Textfield {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    $info = parent::getInfo();
    $class = static::class;
    $info['#process'][] = [$class, 'processFormElement'];
    $info['#element_validate'][] = [$class, 'validateFormElement'];
    return $info;
  }

  /**
   * Process render array.
   *
   * @param array $element
   *   Render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $complete_form
   *   Unused variable.
   *
   * @return array
   *   Render array.
   */
  public static function processFormElement(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $parent = $element['#parent'] ?? FALSE;
    $wrap = isset($element['#wrap']) && $element['#wrap'] == FALSE ? 'false' : 'true';
    $theme = $element['#theme_style'] ?? 'default';
    $theme_mode = $element['#theme_mode'] ?? 'light';
    $margin = $element['#margin'] ?? 2;
    $format = $element['#format'] ?? 'hex';
    $format_toggle = isset($element['#format_toggle']) && $element['#format_toggle'] == TRUE ? 'true' : 'false';
    $alpha = isset($element['#alpha']) && $element['#alpha'] == FALSE ? 'false' : 'true';
    $swatches_only = isset($element['#swatches_only']) && $element['#swatches_only'] == TRUE ? 'true' : 'false';
    $focus_input = isset($element['#focus_input']) && $element['#focus_input'] == FALSE ? 'false' : 'true';
    $clear_button_show = isset($element['#clear_button_show']) && $element['#clear_button_show'] == TRUE ? 'true' : 'false';
    $clear_button_label = $element['#clear_button_label'] ?? t('Clear');
    $on_change = $element['#on_change'] ?? NULL;
    $swatches = $element['#swatches'] ?? [];
    $inline = isset($element['#inline']) && $element['#inline'] == TRUE ? 'true' : 'false';
    $element = [
      '#prefix' => '<div class="coloris-wrapper [&_.clr-field]:w-full">',
      '#suffix' => '</div>',
      '#type' => 'textfield',
      '#attributes' => [
        'id' => Html::getUniqueId('coloris'),
        'data-wrap' => $wrap,
        'data-theme' => $theme,
        'data-theme-mode' => $theme_mode,
        'data-margin' => $margin,
        'data-format' => $format,
        'data-format-toggle' => $format_toggle,
        'data-alpha' => $alpha,
        'data-swatches-only' => $swatches_only,
        'data-focus-input' => $focus_input,
        'data-clear-button-show' => $clear_button_show,
        'data-clear-button-label' => $clear_button_label,
        'data-swatches' => json_encode($swatches),
        'data-inline' => $inline,
      ] + $element['#attributes'],
      '#required' => $element['#required'] ?? FALSE,
      '#default_value' => $element['#default_value'] ?? '',
      '#title' => $element['#title'] ?? '',
      '#description' => $element['#description'] ?? '',
    ] + $element;

    $element['#attributes']['class'][] = 'coloris';
    if ($parent !== FALSE) {
      $element['#attributes']['data-parent'] = $parent;
    }
    if ($on_change !== NULL) {
      $element['#attributes']['data-on-change'] = $on_change;
    }

    if (isset($element['#default_color'])) {
      $element['#attributes']['data-default-color'] = $element['#default_color'];
    }

    $element['#attached']['library'][] = 'neo/coloris';
    return $element;
  }

  /**
   * Validation.
   *
   * @param array $element
   *   Element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $complete_form
   *   Unused variable.
   */
  public static function validateFormElement(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $value = trim($element['#value']);
    if ($value !== '') {
      preg_match('/^(\#[\da-f]{3}|\#[\da-f]{6}|\#[\da-f]{8}|rgba\(((\d{1,2}|1\d\d|2([0-4]\d|5[0-5]))\s*,\s*){2}((\d{1,2}|1\d\d|2([0-4]\d|5[0-5]))\s*)(,\s*(0\.\d+|1))\)|hsla\(\s*((\d{1,2}|[1-2]\d{2}|3([0-5]\d|60)))\s*,\s*((\d{1,2}|100)\s*%)\s*,\s*((\d{1,2}|100)\s*%)(,\s*(0\.\d+|1))\)|rgb\(((\d{1,2}|1\d\d|2([0-4]\d|5[0-5]))\s*,\s*){2}((\d{1,2}|1\d\d|2([0-4]\d|5[0-5]))\s*)|hsl\(\s*((\d{1,2}|[1-2]\d{2}|3([0-5]\d|60)))\s*,\s*((\d{1,2}|100)\s*%)\s*,\s*((\d{1,2}|100)\s*%)\))$/', $value, $matches);
      if (count($matches) === 0) {
        $form_state->setError($element, t('The color code %color is not valid.', ['%color' => $value]));
      }
    }
  }

}
