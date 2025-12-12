<?php

namespace Drupal\neo\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'machine_name' widget.
 */
#[FieldWidget(
  id: 'slug',
  label: new TranslatableMarkup('Slug'),
  field_types: [
    'string',
  ],
)]
class SlugWidget extends StringTextfieldWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    $settings = parent::defaultSettings();

    foreach (self::getSettingsKeys() as $key) {
      $settings[$key] = '';
    }

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['value']['#type'] = 'slug';

    foreach ($this->getSettingsKeys() as $key) {
      if ($setting = $this->getSetting($key)) {
        $element['value']['#slug'][$key] = $setting;
      }
    }

    return $element;
  }

  /**
   * Gets the machine name settings.
   *
   * @return array
   *   Array of keys.
   */
  protected static function getSettingsKeys() {
    return [
      'source',
    ];
  }

}
