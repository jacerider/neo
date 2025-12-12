<?php

namespace Drupal\neo\Element;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\Textfield;

/**
 * Provides a form element for choosing a slug.
 *
 * Properties:
 * - #default_value: Default value, in a format like #ffffff.
 *
 * Example usage:
 * @code
 * $form['slug'] = [
 *   '#type' => 'slug',
 *   '#title' => $this->t('Slug'),
 * ];
 * @endcode
 */
#[FormElement('slug')]
class Slug extends Textfield {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#input' => TRUE,
      '#default_value' => NULL,
      '#required' => FALSE,
      '#maxlength' => 64,
      '#size' => 60,
      '#autocomplete_route_name' => FALSE,
      '#process' => [
        [static::class, 'processSlug'],
        [static::class, 'processAutocomplete'],
        [static::class, 'processAjaxForm'],
      ],
      '#element_validate' => [
        [static::class, 'validateSlug'],
      ],
      '#pre_render' => [
        [static::class, 'preRenderSlug'],
      ],
      '#theme' => 'input__slug',
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * Processes a machine-readable slug form element.
   *
   * @param array $element
   *   The form element to process. See main class documentation for properties.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The processed element.
   */
  public static function processSlug(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#attached']['library'][] = 'neo/slug';
    $element['#attributes']['class'][] = 'neo-slug';
    if (!empty($element['#slug']['source'])) {
      $key_exists = NULL;
      $source = NestedArray::getValue($form_state->getCompleteForm(), $element['#slug']['source'], $key_exists);
      if (!empty($source['#id'])) {
        $element['#attributes']['data-neo-slug-source'] = $source['#id'];
      }
    }
    return $element;
  }

  /**
   * Form element validation handler for #type 'slug'.
   */
  public static function validateSlug(&$element, FormStateInterface $form_state, &$complete_form) {
    $value = trim($element['#value']);
  }

  /**
   * Prepares a #type 'slug' render element for input.html.twig.
   *
   * @param array $element
   *   An associative array containing the properties of the element.
   *   Properties used: #title, #value, #description, #attributes.
   *
   * @return array
   *   The $element with prepared variables ready for input.html.twig.
   */
  public static function preRenderSlug($element) {
    return static::preRenderTextfield($element);
  }

}
