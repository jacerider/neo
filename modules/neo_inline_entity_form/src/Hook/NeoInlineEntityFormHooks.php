<?php

declare(strict_types=1);

namespace Drupal\neo_inline_entity_form\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Theme hook implementations for the Neo inline entity form widget.
 *
 * This is not an API. The methods are public because core's hook collector
 * only reads public methods, and nothing but the hook system calls them.
 */
class NeoInlineEntityFormHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_preprocess_HOOK() for inline_entity_form_entity_table.
   *
   * Marks the rows the widget flagged as unsaved, and the rows that hold a
   * shelf, whose form is fixed over the page and leaves the row empty. Only
   * tables built by the Neo widget are touched.
   *
   * This runs before neo_back's preprocess of the same table, which still
   * styles the cells afterwards.
   */
  #[Hook('preprocess_inline_entity_form_entity_table')]
  public function preprocessEntityTable(array &$variables): void {
    $form = $variables['form'] ?? [];
    if (empty($form['#neo_ief']) || empty($variables['table'])) {
      return;
    }

    $label_key = NULL;
    foreach ($form['#table_fields'] ?? [] as $name => $field) {
      if (($field['type'] ?? NULL) === 'label') {
        $label_key = $name;
        break;
      }
    }

    // IEF adds one entity row per child, in child order, each followed by a
    // form row when that child has a form open.
    $keys = Element::children($form);
    $index = 0;
    $current = NULL;
    $table = &$variables['table'];
    foreach (Element::children($table) as $row_key) {
      $row = &$table[$row_key];
      $classes = $row['#attributes']['class'] ?? [];
      if (in_array('ief-row-entity', $classes, TRUE)) {
        $current = $keys[$index++] ?? NULL;
        if ($current === NULL || empty($form[$current]['#neo_unsaved'])) {
          continue;
        }
        $row['#attributes']['class'][] = 'neo-ief-row--unsaved';
        if ($label_key !== NULL && isset($row[$label_key])) {
          $row[$label_key]['neo_ief_badge'] = [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Unsaved'),
            '#attributes' => [
              'class' => ['neo-ief-badge', 'neo-ief-badge--unsaved'],
              'title' => $this->t('Saved when you save this form.'),
            ],
          ];
        }
      }
      elseif (in_array('ief-row-form', $classes, TRUE)
        && $current !== NULL
        && !empty($form[$current]['form']['#neo_ief_shelf'])) {
        $row['#attributes']['class'][] = 'neo-ief-row-form--shelf';
      }
    }
  }

}
