<?php

namespace Drupal\neo\Plugin\Field\FieldFormatter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;

/**
 * Provides a trait for selecting which entities to view.
 */
trait NeoEntityReferenceSelectionTrait {

  /**
   * {@inheritdoc}
   */
  public static function selectionDefaultSettings() {
    return [
      'selection_mode' => 'all',
      'amount' => 1,
      'offset' => 0,
      'reverse' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function selectionSettingsSummary() {
    $summary = [];
    $summary[] = $this->t(
      'Selection mode: @mode',
      ['@mode' => $this->getSelectionModes()[$this->getSetting('selection_mode')]]
    );
    if ($this->getSetting('selection_mode') == 'advanced') {
      $amount = $this->getSetting('amount') ? $this->getSetting('amount') : 1;
      $summary[] = \Drupal::translation()->formatPlural(
        $amount,
        $this->getSetting('reverse') ? 'Showing @amount entity starting at @offset in reverse order' : 'Showing @amount entity starting at @offset',
        $this->getSetting('reverse') ? 'Showing @amount entities starting at @offset in reverse order' : 'Showing @amount entities starting at @offset',
        [
          '@amount' => $amount,
          '@offset' => $this->getSetting('offset') ? $this->getSetting('offset') : 0,
        ]
      );
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function selectionSettingsForm(array $form, FormStateInterface $form_state) {
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $id = Html::getUniqueId('exo-entity-reference-selection-mode');

    if ($cardinality == 1) {
      return [];
    }

    $elements['selection_mode'] = [
      '#type' => 'select',
      '#options' => $this->getSelectionModes(),
      '#title' => t('Selection mode'),
      '#default_value' => $this->getSetting('selection_mode'),
      '#required' => TRUE,
      '#id' => $id,
    ];

    $show_advanced = [
      'visible' => [
        '#' . $id => [
          'value' => 'advanced',
        ],
      ],
    ];

    $elements['amount'] = [
      '#type' => 'number',
      '#step' => 1,
      '#min' => 1,
      '#title' => t('Amount of displayed entities'),
      '#default_value' => $this->getSetting('amount'),
      '#states' => $show_advanced,
    ];
    if ($cardinality > 0) {
      $elements['amount']['#max'] = $cardinality;
    }

    $elements['offset'] = [
      '#type' => 'number',
      '#step' => 1,
      '#min' => 0,
      '#title' => t('Offset'),
      '#default_value' => $this->getSetting('offset'),
      '#states' => $show_advanced,
      '#cardinality' => $cardinality,
      '#element_validate' => [[get_class($this), 'selectionValidateOffset']],
    ];

    $elements['reverse'] = [
      '#type' => 'checkbox',
      '#title' => t('Reverse order'),
      '#desctiption' => t('Check this if you want to show the last added entities of the field. For example use amount 2 and "Reverse order" in order to display the last two entities in the field.'),
      '#default_value' => $this->getSetting('reverse'),
      '#states' => $show_advanced,
    ];
    return $elements;
  }

  /**
   * Validation callback for the offset element.
   *
   * @param array $element
   *   The form element to process.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function selectionValidateOffset(array &$element, FormStateInterface $form_state) {
    $cardinality = $element['#cardinality'];
    $parents = $element['#parents'];
    array_pop($parents);
    $field_settings = $form_state->getValue($parents);
    $offset_maximum = $cardinality - $field_settings['amount'];
    // If cardinality of the field is limited, the offset has to be lower than
    // the field's cardinality minus the submitted amount value.
    if ($cardinality > 0 && $field_settings['offset'] > $offset_maximum) {
      $form_state->setError(
        $element,
        t('The maximal offset for the submitted amount is @offset',
          ['@offset' => $offset_maximum]
        )
      );
    }
  }

  /**
   * Get the formatter's selection mode options.
   *
   * @return array
   *   Array of available selection modes.
   */
  protected function getSelectionModes() {
    return [
      'all' => t('All'),
      'first' => t('First'),
      'last' => t('Last'),
      'advanced' => t('Advanced'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function filterSelectionEntities(array $entities) {
    switch ($this->getSetting('selection_mode')) {
      case 'advanced':
        $entities = $this->getEntitiesToViewSubset($entities, $this->getSetting('amount'), $this->getSetting('offset'));
        break;

      case 'first':
        $entities = $this->getEntitiesToViewSubset($entities, 1, 0);
        break;

      case 'last':
        $entities = $this->getEntitiesToViewSubset($entities, 1, count($entities) - 1);
        break;
    }
    return $entities;
  }

  /**
   * Gets the render array of entities considering formatter's advanced options.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The entities to filter.
   * @param int $amount
   *   The amount of field items to show.
   * @param int $offset
   *   The offset to apply for displayed items.
   *
   * @return array
   *   A renderable array for $items, as an array of child elements keyed by
   *   consecutive numeric indexes starting from 0.
   */
  protected function getEntitiesToViewSubset(array $entities, $amount, $offset) {
    $filtered_entities = [];
    $count = 0;
    if ($this->getSetting('reverse')) {
      $entities = array_reverse($entities);
    }
    foreach ($entities as $delta => $entity) {
      // Show entities if offset was reached and amount limit isn't reached yet.
      if ($delta >= $offset && $count < $amount) {
        $filtered_entities[] = $entity;
        $count++;
      }
    }
    return $filtered_entities;
  }

}
