<?php

namespace Drupal\neo\Plugin\Field\FieldWidget;

use CommerceGuys\Addressing\AddressFormat\AddressFormatHelper;
use Drupal\address\Plugin\Field\FieldWidget\AddressDefaultWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\address\FieldHelper;
use Drupal\address\LabelHelper;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;

/**
 * Plugin implementation of the 'neo_address' widget.
 *
 * @FieldWidget(
 *   id = "neo_address",
 *   label = @Translation("Neo | Address"),
 *   field_types = {
 *     "address"
 *   },
 *   provider = "address",
 * )
 */
class NeoAddressWidget extends AddressDefaultWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'force_address' => FALSE,
      'hidden' => [],
    ] + parent::defaultSettings();
  }

  /**
   * Gets the options for the "Wrapper type" setting.
   *
   * @return string[]
   *   The available options.
   */
  protected function wrapperTypeOptions(): array {
    return parent::wrapperTypeOptions() + [
      'item' => $this->t('Item (standard field)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['force_address'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force display of address fields'),
      '#description' => $this->t('This will hide the country field and show the address fields.'),
      '#default_value' => $this->getSetting('force_address'),
    ];

    $options = LabelHelper::getGenericFieldLabels();
    foreach ($this->fieldDefinition->getSetting('field_overrides') as $field => $data) {
      if ($data['override'] === 'hidden') {
        unset($options[$field]);
      }
    }

    $element['hidden'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Hidden fields'),
      '#options' => $options,
      '#default_value' => $this->getSetting('hidden'),
      '#description' => $this->t('These fields will be hidden from the user.'),
      '#element_validate' => [
        [get_class($this), 'validateHiddenFields'],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function validateHiddenFields(array $element, FormStateInterface $form_state) {
    $form_state->setValue($element['#parents'], array_filter($form_state->getValue($element['#parents'])));
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Force display of address fields: %force_address', ['%force_address' => $this->getSetting('force_address') ? 'Yes' : 'No']);
    if ($hidden = array_filter($this->getSetting('hidden'))) {
      $options = array_intersect_key(LabelHelper::getGenericFieldLabels(), $hidden);
      $markup = Markup::create('<small><br> - ' . implode('<br> - ', $options) . '</small>');
      $summary[] = $this->t('Hidden fields: %fields', ['%fields' => $markup]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['#attributes']['class'][] = 'neo-address-wrapper';
    $element['#attributes']['class'][] = 'neo-address-type-' . $element['#type'];

    if ($this->getSetting('force_address') && empty($element['#required']) && count($element['address']['#available_countries']) === 1) {
      $element['address']['#default_value']['country_code'] = key($element['address']['#available_countries']);
      $element['address']['#hide_country'] = TRUE;
      $element['address']['#after_build'][] = [
        get_class($this),
        'showAddressFieldByDefault',
      ];
      $element['address']['#element_validate'][] = [
        get_class($this),
        'validateAddressFieldByDefault',
      ];
    }

    if ($hidden = array_filter($this->getSetting('hidden'))) {
      $element['address']['#field_overrides'] += array_map(function ($item) {
        return 'hidden';
      }, $hidden);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function getWrapperOptions() {
    return [
      'fieldset' => $this->t('Fieldset'),
      'details' => $this->t('Details'),
      'container' => $this->t('Container'),
      'item' => $this->t('Item'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function validateAddressFieldByDefault(array $element, FormStateInterface $form_state) {
    $value = [];
    foreach (Element::children($element) as $key) {
      if (isset($element[$key]['#value'])) {
        $value[$key] = $element[$key]['#value'];
      }
    }

    $field_overrides = $element['#parsed_field_overrides'];
    /** @var \CommerceGuys\Addressing\AddressFormat\AddressFormat $address_format */
    $address_format = \Drupal::service('address.address_format_repository')->get($value['country_code']);
    $required_fields = AddressFormatHelper::getRequiredFields($address_format, $field_overrides);
    $has_value = FALSE;
    foreach ($required_fields as $field) {
      $property = FieldHelper::getPropertyName($field);
      if (!empty($element[$property]['#value'])) {
        $has_value = TRUE;
      }
    }
    if ($has_value) {
      foreach ($required_fields as $field) {
        $property = FieldHelper::getPropertyName($field);
        if (empty($element[$property]['#value'])) {
          $form_state->setError($element[$property], t('@label is required.', [
            '@label' => $element[$property]['#title'],
          ]));
        }
      }
    }
    else {
      $value['country_code'] = '';
    }

    $form_state->setValue($element['#parents'], $value);
  }

  /**
   * {@inheritdoc}
   */
  public static function showAddressFieldByDefault(array $element, FormStateInterface $form_state) {
    if (!empty($element['#hide_country'])) {
      $value = $element['#value'];
      $element['country_code']['#access'] = FALSE;
      $element['country_code']['#weight'] = 1000;
      $field_overrides = $element['#parsed_field_overrides'];
      /** @var \CommerceGuys\Addressing\AddressFormat\AddressFormat $address_format */
      $address_format = \Drupal::service('address.address_format_repository')->get($value['country_code']);
      $required_fields = AddressFormatHelper::getRequiredFields($address_format, $field_overrides);
      foreach ($required_fields as $field) {
        $property = FieldHelper::getPropertyName($field);
        $element[$property]['#required'] = FALSE;
        $element[$property]['#validate_required'] = TRUE;
      }
    }
    return $element;
  }

}
