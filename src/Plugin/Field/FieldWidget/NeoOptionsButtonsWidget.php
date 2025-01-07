<?php

declare(strict_types = 1);

namespace Drupal\neo\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'options_buttons' widget.
 *
 * @FieldWidget(
 *   id = "neo_options_buttons",
 *   label = @Translation("Neo | Check boxes/radio buttons"),
 *   field_types = {
 *     "boolean",
 *     "entity_reference",
 *     "list_integer",
 *     "list_float",
 *     "list_string",
 *   },
 *   multiple_values = TRUE
 * )
 */
class NeoOptionsButtonsWidget extends OptionsButtonsWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'style' => 'inline_buttons',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Style'),
      '#default_value' => $this->getSetting('style'),
      '#description' => $this->t('The style of the widget.'),
      '#required' => TRUE,
      '#options' => $this->getStyles(),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    if ($style = $this->getSetting('style')) {
      $summary[] = $this->t('Style: @placeholder', ['@placeholder' => $this->getStyles()[$style]]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    if ($style = $this->getSetting('style')) {
      $element['#neo_style'] = $style;
    }

    return $element;
  }

  /**
   * Get the styles.
   *
   * @return array
   *   The styles.
   */
  protected function getStyles() {
    return [
      'inline' => $this->t('Inline'),
      'inline_buttons' => $this->t('Inline buttons'),
    ];
  }

}
