<?php

namespace Drupal\neo\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'neo_entity_attribute' formatter.
 *
 * @FieldFormatter(
 *   id = "neo_entity_attribute",
 *   label = @Translation("Entity Attribute"),
 *   field_types = {
 *     "neo_attribute",
 *     "list_integer",
 *     "list_float",
 *     "list_string",
 *     "entity_reference",
 *     "entity_reference_revision",
 *     "boolean",
 *   }
 * )
 */
class NeoEntityAttributeFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'type' => 'class',
      'prefix' => '',
      'suffix' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => $this->getTypeOptions(),
      '#default_value' => $this->getSetting('type'),
    ];

    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefix'),
      '#default_value' => $this->getSetting('prefix'),
    ];

    $form['suffix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suffix'),
      '#default_value' => $this->getSetting('suffix'),
    ];

    return $form;
  }

  /**
   * Get the type options.
   *
   * @return array
   *   The type options.
   */
  protected function getTypeOptions() {
    return [
      'class' => $this->t('Class'),
      'data' => $this->t('Data'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Type: %value', [
      '%value' => $this->getTypeOptions()[$this->getSetting('type')],
    ]);
    if ($prefix = $this->getSetting('prefix')) {
      $summary[] = $this->t('Prefix: %value', [
        '%value' => $prefix,
      ]);
    }
    if ($suffix = $this->getSetting('suffix')) {
      $summary[] = $this->t('Suffix: %value', [
        '%value' => $suffix,
      ]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // The magic happens in neo_entity_view_alter().
    return [];
  }

}
