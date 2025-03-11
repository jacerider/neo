<?php

namespace Drupal\neo_menu_link\Settings;

use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_settings\Plugin\SettingsBase;

/**
 * Module settings.
 *
 * @Settings(
 *   id = "neo_menu_link",
 *   label = @Translation("Menu Links"),
 *   config_name = "neo_menu_link.settings",
 *   menu_title = @Translation("Menu Links"),
 *   route = "/admin/config/neo/neo-tooltip",
 *   admin_permission = "administer neo_menu_link",
 *   variation_allow = false,
 *   variation_conditions = false,
 *   variation_ordering = false,
 * )
 */
class MenuLinkSettings extends SettingsBase {

  /**
   * {@inheritdoc}
   *
   * Instance settings are settings that are set both in the base form and the
   * variation form. They are editable in both forms and the values are merged
   * together.
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['icon_libraries'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Icon Packages'),
      '#default_value' => $this->getValue('icon_libraries'),
      '#description' => $this->t('The icon libraries that should be made available in this field. If no libraries are selected, all will be made available.'),
      '#options' => $this->getIconLibrariesAsOptions(),
    ];

    $form['target'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow target selection'),
      '#description' => $this->t('If selected, an "open in new window" checkbox will be made available.'),
      '#default_value' => $this->getValue('target'),
    ];

    $form['class'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow adding custom CSS classes'),
      '#description' => $this->t('If selected, a textfield will be provided that will allow adding in custom CSS classes.'),
      '#default_value' => $this->getValue('class'),
    ];

    $parents = array_merge($form['#parents'], ['class']);
    $selector = array_shift($parents) . '[' . implode("][", $parents) . "]";
    $form['class_list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('List of allowed CSS classes'),
      '#description' => Markup::create($this->allowedValuesDescription()),
      '#default_value' => $this->getValue('class_list'),
      '#states' => [
        'visible' => [
          ':input[name="' . $selector . '"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['spacers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow spacer links'),
      '#description' => $this->t('If enabled, menu links can be created as spacers.'),
      '#default_value' => $this->getValue('spacers'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function allowedValuesDescription() {
    $description = '<p>' . $this->t('The possible values this field can contain. Enter one value per line, in the format key|label.');
    $description .= '<br/>' . $this->t('The key is the stored value. The label will be used in displayed values and edit forms.');
    $description .= '<br/>' . $this->t('The label is optional: if a line contains a single string, it will be used as key and label.');
    $description .= '</p>';
    $description .= '<p>' . $this->t('Allowed HTML tags in labels: @tags', ['@tags' => FieldFilteredMarkup::displayAllowedTags()]) . '</p>';
    return $description;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm($form, FormStateInterface $form_state) {
    $form_state->setValue('icon_libraries', array_values(array_filter($form_state->getValue('icon_libraries'))));
  }

  /**
   * Extracts the allowed values array from the allowed_values element.
   *
   * @return array|null
   *   The array of extracted key/value pairs, or NULL if the string is invalid.
   *
   * @see \Drupal\options\Plugin\Field\FieldType\ListItemBase::allowedValuesString()
   */
  public function getClassList() {
    $string = $this->getValue('class_list');
    if (empty($string)) {
      return [];
    }
    $values = [];

    $list = explode("\n", $string);
    $list = array_map('trim', $list);
    $list = array_filter($list, 'strlen');

    $generated_keys = $explicit_keys = FALSE;
    foreach ($list as $position => $text) {
      // Check for an explicit key.
      $matches = [];
      if (preg_match('/(.*)\|(.*)/', $text, $matches)) {
        // Trim key and value to avoid unwanted spaces issues.
        $key = trim($matches[1]);
        $value = trim($matches[2]);
        $explicit_keys = TRUE;
      }
      // Otherwise see if we can use the value as the key.
      elseif (!$this->validateClassListValue($text)) {
        $key = $value = $text;
        $explicit_keys = TRUE;
      }
      else {
        return;
      }

      $values[$key] = $value;
    }

    // We generate keys only if the list contains no explicit key at all.
    if ($explicit_keys && $generated_keys) {
      return;
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateClassListValue($option) {
    if (mb_strlen($option) > 255) {
      return new TranslatableMarkup('Allowed values list: each key must be a string at most 255 characters long.');
    }
  }

  /**
   * Get icon_libraries as options.
   *
   * @return array
   *   An array of id => label options.
   */
  protected function getIconLibrariesAsOptions() {
    /** @var \Drupal\neo_icon\IconLibraryStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('neo_icon_library');
    return $storage->loadAsOptions();
  }

}
