<?php

namespace Drupal\neo\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\StringFormatter;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'string' formatter.
 *
 * @FieldFormatter(
 *   id = "neo_string",
 *   label = @Translation("Neo | Plain text"),
 *   field_types = {
 *     "string",
 *     "uri",
 *     "list_integer",
 *     "list_float",
 *     "list_string",
 *   },
 *   quickedit = {
 *     "editor" = "plain_text"
 *   }
 * )
 */
class NeoStringFormatter extends StringFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'style' => '',
      'delimiter' => '',
      'prefix' => '',
      'suffix' => '',
    ] + parent::defaultSettings();
  }

  /**
   * Returns available styles.
   *
   * @return array
   *   An associative array of style options, each containing a 'label' and
   *   'class' key.
   */
  protected function getStyles() {
    return [
      'inline' => [
        'label' => $this->t('Inline'),
        'class' => 'flex gap-2',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);
    $form['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Style'),
      '#options' => array_map(function ($style) {
        return $style['label'];
      }, $this->getStyles()),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $this->getSetting('style'),
    ];
    $form['delimiter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delimiter.'),
      '#description' => $this->t('A character which should be used to separate the items.'),
      '#default_value' => $this->getSetting('delimiter'),
      '#size' => 1,
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
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($style = $this->getSetting('style')) {
      $summary[] = $this->t('Style: @style', ['@style' => $this->getStyles()[$style]['label'] ?? 'None']);
    }
    if ($this->getSetting('delimiter')) {
      $summary[] = $this->t('Delimiter: @delimiter', ['@delimiter' => $this->getSetting('delimiter')]);
    }
    if ($this->getSetting('prefix')) {
      $summary[] = $this->t('Prefix: @prefix', ['@prefix' => $this->getSetting('prefix')]);
    }
    if ($this->getSetting('suffix')) {
      $summary[] = $this->t('Suffix: @suffix', ['@suffix' => $this->getSetting('suffix')]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {
    $elements = parent::view($items, $langcode);
    if ($style = $this->getSetting('style')) {
      $elements['#items_attributes']['class'][] = $this->getStyles()[$style]['class'];
    }
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($elements as $delta => &$element) {
      $element['#prefix'] = $this->getSetting('prefix');
      $element['#suffix'] = '';
      if (!empty($this->getSetting('delimiter')) && count($elements) !== $delta + 1) {
        $element['#suffix'] = $this->getSetting('delimiter') . $element['#suffix'];
      }
      $element['#suffix'] = $this->getSetting('suffix') . $element['#suffix'];
    }

    return $elements;
  }

}
