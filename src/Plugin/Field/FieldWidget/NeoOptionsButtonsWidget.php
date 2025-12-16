<?php

declare(strict_types=1);

namespace Drupal\neo\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_icon\IconTrait;

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
  use IconTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'style' => 'inline_buttons',
      'size' => 'md',
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
      '#empty_option' => $this->t('Default'),
      '#options' => $this->getStyles(),
    ];
    $element['size'] = [
      '#type' => 'select',
      '#title' => $this->t('Size'),
      '#default_value' => $this->getSetting('size'),
      '#description' => $this->t('The size of the buttons.'),
      '#required' => TRUE,
      '#options' => $this->getSizes(),
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
    $summary[] = $this->t('Size: @placeholder', ['@placeholder' => $this->getSizes()[$this->getSetting('size')]]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    if ($style = $this->getSetting('style')) {
      $element['#neo_style'] = $style;
      $size = $this->getSetting('size');
      if ($size !== 'md') {
        $element['#neo_size'] = $size;
      }
    }

    return $element;
  }

  /**
   * Returns the array of options for the widget.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity for which to return options.
   *
   * @return array
   *   The array of options for the widget.
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    $options = parent::getOptions($entity);

    if ($options) {
      if ($entityTypeId = $this->fieldDefinition->getSetting('target_type')) {
        $storage = \Drupal::entityTypeManager()->getStorage($entityTypeId);
        $entities = $storage->loadMultiple(array_keys($options));
        foreach ($options as $entityId => &$label) {
          $optionEntity = $entities[$entityId] ?? NULL;
          if ($optionEntity instanceof FieldableEntityInterface) {
            // If entity has icon field, use it.
            if ($optionEntity->hasField('field_icon') && !$optionEntity->get('field_icon')->isEmpty()) {
              $label = $this->icon($label, $optionEntity->get('field_icon')->value);
            }
          }
        }
      }
      else {
        foreach ($options as &$label) {
          $label = $this->icon($label);
        }
      }
    }

    return $options;
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
      'inline_buttons_outline' => $this->t('Inline outline buttons'),
    ];
  }

  /**
   * Get the sizes.
   *
   * @return array
   *   The sizes.
   */
  protected function getSizes() {
    return [
      'xs' => $this->t('Extra small'),
      'sm' => $this->t('Small'),
      'md' => $this->t('Medium (default)'),
      'lg' => $this->t('Large'),
      'xl' => $this->t('Extra large'),
    ];
  }

}
