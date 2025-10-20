<?php

namespace Drupal\neo\Plugin\field_group\FieldGroupFormatter;

use Drupal\field_group\Plugin\field_group\FieldGroupFormatter\HtmlElement;

/**
 * Plugin implementation of the 'html_element' formatter.
 *
 * @FieldGroupFormatter(
 *   id = "neo_grid",
 *   label = @Translation("Neo | Grid"),
 *   description = @Translation("This fieldgroup renders the inner content in a grid layout."),
 *   supported_contexts = {
 *     "form",
 *     "view",
 *   }
 * )
 */
class GridElement extends HtmlElement {

  /**
   * {@inheritdoc}
   */
  public function process(&$element, $processed_object) {
    // Keep using preRender parent for BC.
    parent::process($element, $processed_object);
    $element['#wrapper_element'] = 'div';
    $element['#attributes']['class'][] = 'grid grid-cols-1 m-form-items-0 m-fields-0';
    $col_options = $this->getColOptions();
    $selected_col = $this->getSetting('cols');
    if (isset($col_options[$selected_col])) {
      $element['#attributes']['class'][] = $col_options[$selected_col];
    }
    $gap_options = $this->getGapOptions();
    $selected_gap = $this->getSetting('gap');
    if (isset($gap_options[$selected_gap]) && !empty($gap_options[$selected_gap]['value'])) {
      $element['#attributes']['class'][] = $gap_options[$selected_gap]['value'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Columns: @cols', ['@cols' => $this->getSetting('cols')]);
    $gap_options = $this->getGapOptions();
    $summary[] = $this->t('Gap: @gap', ['@gap' => $gap_options[$this->getSetting('gap')]['label']]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {
    $form = parent::settingsForm();
    $form['element']['#access'] = FALSE;
    $form['cols'] = [
      '#type' => 'select',
      '#title' => $this->t('Number of columns'),
      '#default_value' => $this->getSetting('cols'),
      '#options' => array_combine(array_keys($this->getColOptions()), array_keys($this->getColOptions())),
      '#description' => $this->t('The number of columns to display the field group content in.'),
      '#required' => TRUE,
    ];
    $form['gap'] = [
      '#type' => 'select',
      '#title' => $this->t('Gap between items'),
      '#default_value' => $this->getSetting('gap'),
      '#options' => array_map(function ($option) {
        return $option['label'];
      }, $this->getGapOptions()),
      '#description' => $this->t('The gap between the grid items.'),
      '#required' => FALSE,
    ];
    return $form;
  }

  /**
   * Get column options.
   *
   * @return array
   *   An associative array of column options.
   */
  protected function getColOptions() {
    $options = [
      '2' => 'grid grid-cols-1 md:grid-cols-2',
      '3' => 'grid grid-cols-1 md:grid-cols-3',
      '4' => 'grid grid-cols-1 md:grid-cols-4',
      '5' => 'grid grid-cols-1 md:grid-cols-5',
      '6' => 'grid grid-cols-1 md:grid-cols-6',
    ];
    return $options;
  }

  /**
   * Get gap options.
   *
   * @return array
   *   An associative array of gap options.
   */
  protected function getGapOptions() {
    $options = [
      '' => [
        'label' => $this->t('None'),
        'value' => '',
      ],
      'form-item' => [
        'label' => $this->t('Form item'),
        'value' => 'gap-form-item',
      ],
      '2' => [
        'label' => '2',
        'value' => 'gap-2',
      ],
      '4' => [
        'label' => '4',
        'value' => 'gap-4',
      ],
      '6' => [
        'label' => '6',
        'value' => 'gap-6',
      ],
    ];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultContextSettings($context) {
    return [
      'cols' => '2',
    ] + parent::defaultSettings($context);

  }

}
