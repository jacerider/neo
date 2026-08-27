<?php

namespace Drupal\neo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo\Helpers\TableProps;
use Drupal\views\Plugin\views\style\Table;

/**
 * Holds `neo`'s form alters.
 *
 * One today: the Views UI edit display form gains a **table prop** select per
 * column — style, size, sticky — whose values the table preprocessor turns into
 * classes on the rendered table. The props themselves come from
 * \Drupal\neo\Helpers\TableProps, which is a static rather than a global so
 * that this class does not depend on `neo.module` having been loaded.
 */
class FormAlterHook {

  use StringTranslationTrait;

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, $form_id) {
    switch ($form_id) {
      case 'views_ui_edit_display_form':
        $this->formAlterViewsUiEditDisplayForm($form, $form_state, $form_id);
        break;
    }
  }

  /**
   * Custom alterations for the Views UI edit display form.
   */
  protected function formAlterViewsUiEditDisplayForm(array &$form, FormStateInterface $form_state, $form_id) {
    $view = $form_state->get('view');
    /** @var \Drupal\views\ViewExecutable $executable */
    $executable = $view->getExecutable();
    $style = $executable->style_plugin;
    if (!$style instanceof Table) {
      return;
    }
    if (!empty($form['options']['style_options']['info'])) {
      $props = array_filter(TableProps::get(), fn ($prop) => !empty($prop['options']));
      foreach ($props as $key => $prop) {
        $form['options']['style_options']['#neo_header'][$key] = $prop['label'];
      }
      foreach ($form['options']['style_options']['info'] as $rowId => $row) {
        foreach ($props as $key => $prop) {
          $form['options']['style_options']['neo'][$rowId][$key] = [
            '#type' => 'select',
            '#options' => ['' => t('- Auto -')] + $prop['options'],
            '#parents' => [
              'style_options',
              'info',
              $rowId,
              $key,
            ],
            '#default_value' => $style->options['info'][$rowId][$key] ?? '',
          ];
        }
      }
    }
  }

}
