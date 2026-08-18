<?php

namespace Drupal\neo\Element;

use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\Number;

/**
 * Provides a form element for numeric input, with special numeric validation.
 *
 * Properties:
 * - #default_value: A valid floating point number.
 * - #min: Minimum value.
 * - #max: Maximum value.
 * - #step: Ensures that the number is an even multiple of step, offset by #min
 *   if specified. A #min of 1 and a #step of 2 would allow values of 1, 3, 5,
 *   etc.
 *
 * Usage example:
 * @code
 * $form['quantity'] = [
 *   '#type' => 'number_plus_minus',
 *   '#title' => $this->t('Quantity'),
 * ];
 * @endcode
 *
 * @see \Drupal\Core\Render\Element\Range
 * @see \Drupal\Core\Render\Element\Textfield
 */
#[FormElement('number_plus_minus')]
class NumberPlusMinus extends Number {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#theme' => 'input__number_plus_minus',
    ] + parent::getInfo();
  }

  /**
   * Prepares a #type 'number' render element for input.html.twig.
   *
   * @param array $element
   *   An associative array containing the properties of the element.
   *   Properties used: #title, #value, #description, #min, #max, #placeholder,
   *   #required, #attributes, #step, #size.
   *
   * @return array
   *   The $element with prepared variables ready for input.html.twig.
   */
  public static function preRenderNumber($element) {
    $element = parent::preRenderNumber($element);

    $element['#attached']['library'][] = 'neo/library.alpine';
    $element['#wrapper_attributes']['x-data'] = '{count: ' . ($element['#default_value'] ?? 0) . '}';
    $element['#wrapper_attributes']['x-data'] = '{
      count: ' . ($element['#default_value'] ?? 0) . ',
      min: ' . ($element['#min'] ?? 0) . ',
      max: ' . ($element['#max'] ?? 0) . ',
      step: ' . ($element['#step'] ?? 1) . ',
      increment() {
          if (!this.max || this.count + this.step <= this.max) {
              this.count += this.step;
          }
      },
      decrement() {
          if (this.count - this.step >= this.min) {
              this.count -= this.step;
          }
      }
    }';
    $element['#minus_attributes'] = [
      'type' => 'button',
      'x-on:click' => 'decrement()',
      ':disabled' => 'count <= min',
    ];
    $element['#plus_attributes'] = [
      'type' => 'button',
      'x-on:click' => 'increment()',
    ];
    if ($element['#max'] ?? 0) {
      $element['#plus_attributes'][':disabled'] = 'count >= max';
    }
    $element['#attributes']['x-model.number'] = 'count';
    $element['#attributes']['class'][] = 'number--plus-minus';
    return $element;
  }

}
