<?php

namespace Drupal\neo\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;
use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo\Helpers\ClassList;
use Drupal\neo\NeoLinkitTrait;

/**
 * Plugin implementation of the 'link' widget.
 *
 * @FieldWidget(
 *   id = "neo_link",
 *   label = @Translation("Neo | Link"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
class NeoLinkWidget extends LinkWidget {

  use NeoLinkitTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'linkit_profile' => 'default',
      'linkit_auto_link_text' => FALSE,
      'placeholder_url' => '',
      'placeholder_title' => '',
      'icon' => TRUE,
      'icon_required' => FALSE,
      'icon_libraries' => [],
      'target' => FALSE,
      'class' => FALSE,
      'class_list' => [],
      'link_text_label' => NULL,
      'wrapper_type' => 'fieldset',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($this->getSetting('link_text_label')) {
      $summary[] = $this->t('Link text label: %label', ['%label' => $this->getSetting('link_text_label')]);
    }
    if ($this->supportsInternalLinks()) {
      $profile = $this->getLinkitProfile($this->getSetting('linkit_profile'));
      if ($profile) {
        $summary[] = $this->t('Use Linkit: %profile', ['%profile' => $profile->label()]);
      }
      $auto_link_text = $this->getSetting('linkit_auto_link_text') ? $this->t('Yes') : $this->t('No');
      $summary[] = $this->t(
        'Automatically populate link text from entity label: @auto_link_text',
        ['@auto_link_text' => $auto_link_text]
      );
    }
    if ($this->getSetting('icon')) {
      $summary[] = $this->t('Allow icon selection');
      $summary[] = $this->t('Icon is %required', ['%required' => $this->getSetting('icon_required') ? $this->t('required') : $this->t('optional')]);
      $enabled_icon_libraries = array_filter($this->getSetting('icon_libraries'));
      if ($enabled_icon_libraries) {
        $enabled_icon_libraries = array_intersect_key($this->getIconLibrariesAsOptions(), $enabled_icon_libraries);
        $summary[] = $this->t('With icon libraries: %icon_libraries', [
          '%icon_libraries' => implode(', ', $enabled_icon_libraries),
        ]);
      }
      else {
        $summary[] = $this->t('With icon icon libraries: %icon_libraries', ['%icon_libraries' => 'All']);
      }
    }
    if ($this->getSetting('target')) {
      $summary[] = $this->t('Allow target selection');
    }
    if ($this->getSetting('class')) {
      $summary[] = $this->t('Allow custom CSS classes');
    }
    $summary[] = $this->t('Wrapper type: @wrapper_type', [
      '@wrapper_type' => $this->wrapperTypeOptions()[$this->getSetting('wrapper_type')],
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['link_text_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link Field Title'),
      '#default_value' => $this->getSetting('link_text_label'),
      '#description' => $this->t('The text that will be used as the label for the link text field. If left empty, the default label will be used.'),
    ];

    if ($this->supportsInternalLinks()) {
      $element['linkit_profile'] = [
        '#type' => 'select',
        '#title' => $this->t('Linkit profile'),
        '#options' => $this->getLinkitProfilesAsOptions(),
        '#empty_option' => $this->t('- Do not use Linkit -'),
        '#default_value' => $this->getSetting('linkit_profile'),
      ];
      $element['linkit_auto_link_text'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Automatically populate link text from entity label'),
        '#default_value' => $this->getSetting('linkit_auto_link_text'),
      ];
    }

    $element['icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow icon selection'),
      '#description' => $this->t('If selected, icon selection will be enabled.'),
      '#default_value' => $this->getSetting('icon'),
    ];

    $element['icon_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require icon selection'),
      '#description' => $this->t('If selected, an icon will be required.'),
      '#default_value' => $this->getSetting('icon_required'),
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $this->fieldDefinition->getName() . '][settings_edit_form][settings][icon]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $element['icon_libraries'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Icon Packages'),
      '#default_value' => $this->getSetting('icon_libraries'),
      '#description' => $this->t('The icon libraries that should be made available in this field. If no libraries are selected, all will be made available.'),
      '#options' => $this->getIconLibrariesAsOptions(),
      '#element_validate' => [
        [static::class, 'validateIconLibraries'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $this->fieldDefinition->getName() . '][settings_edit_form][settings][icon]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $element['target'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow target selection'),
      '#description' => $this->t('If selected, an "open in new window" checkbox will be made available.'),
      '#default_value' => $this->getSetting('target'),
    ];

    $element['class'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow adding custom CSS classes'),
      '#description' => $this->t('If selected, a textfield will be provided that will allow adding in custom CSS classes.'),
      '#default_value' => $this->getSetting('class'),
    ];

    $element['class_list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('List of allowed CSS classes'),
      '#description' => Markup::create($this->allowedValuesDescription()),
      '#default_value' => $this->getSetting('class_list'),
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $this->fieldDefinition->getName() . '][settings_edit_form][settings][class]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $element['wrapper_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Wrapper type'),
      '#options' => $this->wrapperTypeOptions(),
      '#default_value' => $this->getSetting('wrapper_type'),
    ];

    return $element;
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
   * Gets the options for the "Wrapper type" setting.
   *
   * @return string[]
   *   The available options.
   */
  protected function wrapperTypeOptions(): array {
    return [
      'container' => $this->t('Container (invisible)'),
      'details' => $this->t('Details (collapsible)'),
      'fieldset' => $this->t('Fieldset (non-collapsible)'),
    ];
  }

  /**
   * Recursively clean up options array if no data-icon is set.
   */
  public static function validateIconLibraries($element, FormStateInterface $form_state, $form) {
    $values = $form_state->getValue($element['#parents']);
    $values = array_filter($values);
    $form_state->setValueForElement($element, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['#type'] = $this->getSetting('wrapper_type');

    $item = $items[$delta];
    $options = $item->get('options')->getValue();
    $attributes = $options['attributes'] ?? [];

    if ($this->supportsInternalLinks()) {
      $element = $this->formElementLinkit($items, $delta, $element, $form, $form_state);
    }

    if (isset($element['title'])) {
      $label = $this->getSetting('link_text_label');
      if ($label) {
        $element['title']['#title'] = $label;
      }
    }

    if (!empty($element['title'])) {
      $element['title']['#weight'] = -1;
      $element = [
        'title' => $element['title'],
      ] + $element;
    }

    if ($this->getSetting('icon')) {
      $class_name = Html::getId('neo-link-widget-' . implode('-', array_merge($element['#field_parents'], [
        $this->fieldDefinition->getName(),
        $delta,
        'uri',
      ])));
      $element['options']['attributes']['data-icon'] = [
        '#type' => 'neo_icon_select',
        '#title' => $this->t('Icon'),
        '#default_value' => $attributes['data-icon'] ?? NULL,
        '#required' => $this->getSetting('icon_required'),
        '#libraries' => $this->getIconLibraries(),
        '#attributes' => [
          'class' => [$class_name],
        ],
      ];

      $element['options']['attributes']['data-icon-position'] = [
        '#type' => 'select',
        '#title' => $this->t('Icon position'),
        '#options' => [
          'before' => $this->t('Before'),
          'after' => $this->t('After'),
        ],
        '#default_value' => $attributes['data-icon-position'] ?? 'before',
        '#required' => TRUE,
        '#states' => [
          'visible' => [
            '.' . $class_name => ['filled' => TRUE],
          ],
        ],
      ];
    }

    if ($this->getSetting('class')) {
      $element['options']['attributes']['class'] = [
        '#type' => 'textfield',
        '#title' => $this->t('CSS classes'),
        '#description' => $this->t('Enter space-separated CSS class names that will be added to the link.'),
        '#default_value' => !empty($attributes['class']) ? implode(' ', (array) $attributes['class']) : NULL,
      ];
      if (!empty($this->getSetting('class_list'))) {
        $element['options']['attributes']['class']['#type'] = 'select';
        $element['options']['attributes']['class']['#description'] = $this->t('A style may apply special styling the the link and/or its children.');
        $element['options']['attributes']['class']['#title'] = $this->t('Style');
        $list = $this->getSetting('class_list');
        if (is_string($list)) {
          $list = $this->getClassList();
        }
        $element['options']['attributes']['class']['#options'] = ['' => $this->t('- Select -')] + $list;
      }
    }

    if ($this->getSetting('target')) {
      $element['options']['attributes']['target'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Open link in new window'),
        '#description' => $this->t('See WCAG guidance on <a href="https://www.w3.org/WAI/WCAG21/Techniques/general/G200" target="_blank">opening links in new windows/tabs</a>.'),
        '#default_value' => !empty($attributes['target']),
        '#neo_size' => 'xs',
      ];
    }

    if (!empty($element['options'])) {
      $nameParts = array_merge($element['#field_parents'], [
        $this->fieldDefinition->getName(),
        $delta,
        'uri',
      ]);
      $name = array_shift($nameParts) . '[' . implode('][', $nameParts) . ']';
      $element['options'] += [
        '#type' => 'container',
        '#title' => $this->t('Options'),
        '#weight' => 100,
        '#states' => [
          'visible' => [
            'input[name="' . $name . '"]' => ['filled' => TRUE],
          ],
        ],
      ];

      // If cardinality is 1, ensure a proper label is output for the field.
      if (!empty($element['options']) && $this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
        $element += [
          '#type' => 'fieldset',
        ];
        $element['uri']['#title'] = $this->t('URL');
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function formElementLinkit(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\link\LinkItemInterface $item */
    $item = $items[$delta];
    // Read the stored uri through the item's own value accessor. `$item->uri`
    // is a magic typed-data property that analysis cannot see; this is the
    // same read, because FieldItemBase::__get() answers get($name)->getValue().
    $uri = $item->get('uri')->getValue();
    $uri_scheme = $uri ? parse_url($uri, PHP_URL_SCHEME) : NULL;
    // Special "route:" URIs (<nolink>, <none>, <button>) are displayed as their
    // token form. Running them through Url::fromUri()->toString() would either
    // URL-encode the angle brackets or resolve to an empty string, losing the
    // value on edit.
    $is_special_route = $uri && $uri_scheme === 'route' && in_array(substr($uri, 6), ['<nolink>', '<none>', '<button>'], TRUE);
    if ($is_special_route) {
      $uri_as_url = substr($uri, 6);
    }
    else {
      try {
        $uri_as_url = !empty($uri) ? Url::fromUri($uri)->toString() : '';
      }
      catch (\Exception $e) {
        $uri_as_url = '';
      }
    }
    // $uri_as_url = self::getLinkitPathByAlias($uri_as_url);
    $linkit_profile_id = $this->getSetting('linkit_profile');

    // The current field value could have been entered by a different user.
    // However, if it is inaccessible to the current user, do not display it
    // to them.
    $default_allowed = !$item->isEmpty() && (\Drupal::currentUser()->hasPermission('link to any page') || $item->getUrl()->access());

    // If ($default_allowed && $uri_scheme == 'entity') {
    // $entity = self::getLinkitEntityFromUri($uri);
    // }
    if (!empty($item->options['data-entity-type']) && !empty($item->options['data-entity-uuid'])) {
      $entity = \Drupal::service('entity.repository')->loadEntityByUuid($item->options['data-entity-type'], $item->options['data-entity-uuid']);
    }
    else {
      $entity = $default_allowed && $uri ? self::getLinkitEntityFromUri($uri) : NULL;
    }

    $element['uri'] = [
      '#type' => 'linkit',
      '#title' => $this->t('URL'),
      '#placeholder' => $this->getSetting('placeholder_url'),
      '#default_value' => $default_allowed ? $uri_as_url : NULL,
      '#maxlength' => 2048,
      '#required' => $element['#required'],
      '#description' => $this->t('Start typing to find content or paste a URL and click on the suggestion below.'),
      '#wrapper_attributes' => [
        'class' => ['form-item--linkit-widget-uri'],
      ],
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#autocomplete_route_parameters' => [
        'linkit_profile' => $linkit_profile_id,
        // Support old linkit module.
        'linkit_profile_id' => $linkit_profile_id,
      ],
    ];

    $element['attributes']['href'] = [
      '#type' => 'hidden',
      '#default_value' => $default_allowed ? $uri : '',
    ];

    $element['attributes']['data-entity-type'] = [
      '#type' => 'hidden',
      '#default_value' => $default_allowed && isset($entity) ? $entity->getEntityTypeId() : '',
    ];

    $element['attributes']['data-entity-uuid'] = [
      '#type' => 'hidden',
      '#default_value' => $default_allowed && isset($entity) ? $entity->uuid() : '',
    ];

    $element['attributes']['data-entity-substitution'] = [
      '#type' => 'hidden',
      '#default_value' => $default_allowed && isset($entity) ? ($entity->getEntityTypeId() == 'file' ? 'file' : 'canonical') : '',
    ];

    // Title visibility values: 0 = Disabled, 1 = Optional, 2 = Required.
    // Use integers for compatibility with Drupal 10 (LinkTitleVisibility enum
    // was added in 11.1).
    $title_visibility_setting = (int) $this->getFieldSetting('title');

    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#placeholder' => $this->getSetting('placeholder_title'),
      '#default_value' => $items[$delta]->title ?? NULL,
      '#maxlength' => 255,
      '#access' => $title_visibility_setting !== 0,
      '#required' => $title_visibility_setting === 2 && $element['#required'],
      '#attributes' => [
        'class' => ['linkit-widget-title'],
      ],
    ];
    if ($this->getSetting('linkit_auto_link_text')) {
      $element['title']['#attributes']['data-linkit-widget-title-autofill-enabled'] = TRUE;
    }
    // Post-process the title field to make it conditionally required if URL is
    // non-empty. Omit the validation on the field edit form, since the field
    // settings cannot be saved otherwise.
    if (!$this->isDefaultValueWidget($form_state) && $title_visibility_setting === 2) {
      $element['#element_validate'][] = [
        get_called_class(),
        'validateTitleElement',
      ];
    }

    // If cardinality is 1, ensure a proper label is output for the field.
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
      // If the link title is disabled, use the field definition label as the
      // title of the 'uri' element.
      if ($title_visibility_setting === 0) {
        $element['uri']['#title'] = $element['#title'];
      }
      // Otherwise wrap everything in a details element.
      else {
        $element += [
          '#type' => 'fieldset',
        ];
      }
    }

    return $element;
  }

  /**
   * Get icon_libraries available to this field.
   */
  protected function getIconLibraries() {
    return $this->getSetting('icon_libraries');
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

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      if ($this->supportsInternalLinks()) {
        $value['uri'] = self::getLinkitUriFromUserInput($value['uri']);
      }
      $value['options'] = $value['options'] ?? [];
      $value['options'] += $value['attributes'] ?? [];
      if (!empty($value['options']['attributes']['target'])) {
        $value['options']['attributes']['target'] = '_blank';
      }
      if (empty($value['options']['attributes']['data-icon'])) {
        $value['options']['attributes']['data-icon-position'] = '';
      }
      unset($value['attributes']);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected static function getLinkitUriAsDisplayableString($uri) {
    $scheme = parse_url($uri, PHP_URL_SCHEME);

    // By default, the displayable string is the URI.
    $displayable_string = $uri;

    // A different displayable string may be chosen in case of the 'internal:'
    // or 'entity:' built-in schemes.
    if ($scheme === 'internal') {
      $uri_reference = explode(':', $uri, 2)[1];

      // @todo '<front>' is valid input for BC reasons, may be removed by
      //   https://www.drupal.org/node/2421941
      $path = parse_url($uri, PHP_URL_PATH);
      if ($path === '/') {
        $uri_reference = '<front>' . substr($uri_reference, 1);
      }

      $displayable_string = $uri_reference;
    }
    elseif ($scheme === 'entity' && $entity = self::getLinkitEntityFromUri($uri)) {
      // If there is no fragment on the original URI, show the entity label.
      $fragment = parse_url($uri, PHP_URL_FRAGMENT);
      if (empty($fragment)) {
        $displayable_string = $entity->label();
      }
    }
    elseif ($scheme === 'mailto') {
      $email = explode(':', $uri)[1];
      $displayable_string = $email;
    }

    return $displayable_string;
  }

  /**
   * Extracts the allowed values array from the allowed_values element.
   *
   * @return array<array-key, string>
   *   The array of extracted key/value pairs, empty when the class list is
   *   empty or invalid.
   *
   * @see \Drupal\options\Plugin\Field\FieldType\ListItemBase::allowedValuesString()
   */
  public function getClassList() {
    $string = $this->getSetting('class_list');
    return ClassList::parse($string, \Closure::fromCallable([$this, 'validateClassListValue']));
  }

  /**
   * {@inheritdoc}
   */
  protected function validateClassListValue($option) {
    if (mb_strlen($option) > 255) {
      return new TranslatableMarkup('Allowed values list: each key must be a string at most 255 characters long.');
    }
  }

}
