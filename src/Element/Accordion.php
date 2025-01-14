<?php

namespace Drupal\neo\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\FormElementBase;

/**
 * Provides a render element for accordions in a form.
 *
 * Formats all child and non-child details elements whose #group is assigned
 * this element's name as accordion.
 *
 * Usage example:
 * @code
 * $form['information'] = [
 *   '#type' => 'accordion',
 *   '#default_tab' => 'edit-publication',
 * ]
 *
 * $form['author'] = [
 *   '#type' => 'details',
 *   '#title' => $this->t('Author'),
 *   '#group' => 'information',
 * ]
 *
 * $form['author']['name'] = [
 *   '#type' => 'textfield',
 *   '#title' => $this->t('Name'),
 * ]
 *
 * $form['publication'] = [
 *   '#type' => 'details',
 *   '#title' => $this->t('Publication'),
 *   '#group' => 'information',
 * ]
 *
 * $form['publication']['publisher'] = [
 *   '#type' => 'textfield',
 *   '#title' => $this->t('Publisher'),
 * ]
 * @endcode
 *
 * @FormElement("accordion")
 */
class Accordion extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#default_tab' => '',
      '#process' => [
          [$class, 'processAccordion'],
      ],
      '#pre_render' => [
          [$class, 'preRenderAccordion'],
      ],
      '#theme_wrappers' => ['accordion', 'form_element'],
    ];
  }

  /**
   * Prepares a accordion element for rendering.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   accordion element.
   *
   * @return array
   *   The modified element.
   */
  public static function preRenderAccordion($element) {
    $element['#attached']['library'][] = 'neo/library.alpine';
    $alterElements = [];

    // Do not render the accordion element if it is empty.
    $group = implode('][', $element['#parents']);

    $children_keys = Element::children($element['group']['#groups'][$group], TRUE);
    foreach ($children_keys as $key) {
      $alterElements[] = &$element['group']['#groups'][$group][$key];
    }

    foreach (Element::children($element) as $key) {
      if ($key === 'group') {
        continue;
      }
      $alterElements[] = &$element[$key];
    }

    $open = [];
    foreach ($alterElements as &$alterElement) {
      if ($alterElement['#type'] === 'details') {
        $alterElement['#theme_wrappers'] = ['accordion_item'];
        $open[] = 'acc' . implode($alterElement['#parents']) . ':' . (!empty($alterElement['#open']) ? 'true' : 'false');
      }
      $alterElement['#attributes']['class'][] = 'accordion-item';
    }
    $element['#attributes']['x-data'] = '{' . implode(',', $open) . '}';

    // Do not render the accordion element if it is empty.
    if (!Element::getVisibleChildren($element) && !Element::getVisibleChildren($element['group']['#groups'][$group])) {
      $element['#printed'] = TRUE;
    }

    return $element;
  }

  /**
   * Creates a group formatted as accordion.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   details element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The processed element.
   */
  public static function processAccordion(&$element, FormStateInterface $form_state, &$complete_form) {
    if (isset($element['#access']) && !$element['#access']) {
      return $element;
    }

    // Inject a new details as child, so that form_process_details() processes
    // this details element like any other details.
    $element['group'] = [
      '#type' => 'details',
      '#theme_wrappers' => [],
      '#parents' => $element['#parents'],
    ];

    // Add an invisible label for accessibility.
    if (!isset($element['#title'])) {
      $element['#title'] = t('Accordion');
      $element['#title_display'] = 'invisible';
    }

    return $element;
  }

}
