<?php

namespace Drupal\neo\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'number' widget.
 */
#[FieldWidget(
  id: 'number_plus_minus',
  label: new TranslatableMarkup('Number | Plus Minus'),
  field_types: [
    'integer',
    'decimal',
    'float',
  ],
)]
class NumberPlusMinusWidget extends NumberWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['value']['#type'] = 'number_plus_minus';
    return $element;
  }

}
