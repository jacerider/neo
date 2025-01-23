<?php

namespace Drupal\neo\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
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
      'placeholder_url' => '',
      'placeholder_title' => '',
      'icon' => TRUE,
      'icon_required' => FALSE,
      'icon_libraries' => [],
      'target' => FALSE,
      'class' => FALSE,
      'class_list' => [],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($this->supportsInternalLinks() && $this->linkitModuleExists()) {
      $profile = $this->getLinkitProfile($this->getSetting('linkit_profile'));
      if ($profile) {
        $summary[] = $this->t('Use Linkit: %profile', ['%profile' => $profile->label()]);
      }
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
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    if ($this->supportsInternalLinks() && $this->linkitModuleExists()) {
      $element['linkit_profile'] = [
        '#type' => 'select',
        '#title' => $this->t('Linkit profile'),
        '#options' => $this->getLinkitProfilesAsOptions(),
        '#empty_option' => $this->t('- Do not use Linkit -'),
        '#default_value' => $this->getSetting('linkit_profile'),
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
        [get_class(), 'validatePackages'],
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

    return $element;
  }

  /**
   * Recursively clean up options array if no data-icon is set.
   */
  public static function validatePackages($element, FormStateInterface $form_state, $form) {
    $values = $form_state->getValue($element['#parents']);
    $values = array_filter($values);
    $form_state->setValueForElement($element, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['#element_validate'][] = [get_called_class(), 'validateElement'];
    $element['title']['#weight'] = -1;

    $item = $items[$delta];
    $options = $item->get('options')->getValue();
    $attributes = $options['attributes'] ?? [];

    if ($this->supportsInternalLinks() && $this->linkitModuleExists()) {
      $element = $this->formElementLinkit($items, $delta, $element, $form, $form_state);
    }

    if (!empty($element['title'])) {
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
        '#default_value' => !empty($attributes['class']) ? implode(' ', $attributes['class']) : NULL,
      ];
      if (!empty($this->getSetting('class_list'))) {
        $element['options']['attributes']['class']['#type'] = 'select';
        $element['options']['attributes']['class']['#description'] = $this->t('A style may apply special styling the the link and/or its children.');
        $element['options']['attributes']['class']['#title'] = $this->t('Style');
        $element['options']['attributes']['class']['#options'] = ['' => $this->t('- Select -')] + $this->getSetting('class_list');
      }
    }

    if ($this->getSetting('target')) {
      $element['options']['attributes']['target'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Open link in new window'),
        '#description' => $this->t('See WCAG guidance on <a href="https://www.w3.org/WAI/WCAG21/Techniques/general/G200" target="_blank">opening links in new windows/tabs</a>.'),
        '#default_value' => !empty($attributes['target']),
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
    $item = $items[$delta];
    $uri = $item->uri;
    $uri_scheme = $uri ? parse_url($uri, PHP_URL_SCHEME) : NULL;
    $is_nolink = $uri && substr($uri, 0, 14) === 'route:<nolink>';
    if (!empty($uri) && empty($uri_scheme) && $is_nolink) {
      $uri = self::getLinkitUriFromUserInput($uri);
      $uri_scheme = parse_url($uri, PHP_URL_SCHEME);
    }
    if ($is_nolink) {
      $uri_as_url = $uri;
    }
    else {
      $uri_as_url = !empty($uri) ? Url::fromUri($uri)->toString() : '';
    }
    $uri_as_url = self::getLinkitPathByAlias($uri_as_url);
    $linkit_profile_id = $this->getSetting('linkit_profile');

    // The current field value could have been entered by a different user.
    // However, if it is inaccessible to the current user, do not display it
    // to them.
    $default_allowed = !$item->isEmpty() && (\Drupal::currentUser()->hasPermission('link to any page') || $item->getUrl()->access());

    if ($default_allowed && $uri_scheme == 'entity') {
      $entity = self::getLinkitEntityFromUri($uri);
    }

    $element['uri'] = [
      '#type' => 'linkit',
      '#title' => $this->t('URL'),
      '#placeholder' => $this->getSetting('placeholder_url'),
      '#default_value' => $default_allowed ? $uri_as_url : NULL,
      '#maxlength' => 2048,
      '#required' => $element['#required'],
      '#description' => $this->t('Start typing to find content or paste a URL and click on the suggestion below.'),
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#autocomplete_route_parameters' => [
        'linkit_profile' => $linkit_profile_id,
        // Support old linkit module.
        'linkit_profile_id' => $linkit_profile_id,
      ],
      '#error_no_message' => TRUE,
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

    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#placeholder' => $this->getSetting('placeholder_title'),
      '#default_value' => $items[$delta]->title ?? NULL,
      '#maxlength' => 255,
      '#access' => $this->getFieldSetting('title') != DRUPAL_DISABLED,
      '#required' => $this->getFieldSetting('title') === DRUPAL_REQUIRED && $element['#required'],
      '#attributes' => [
        'class' => ['linkit-widget-title'],
      ],
      '#error_no_message' => TRUE,
    ];
    // Post-process the title field to make it conditionally required if URL is
    // non-empty. Omit the validation on the field edit form, since the field
    // settings cannot be saved otherwise.
    if (!$this->isDefaultValueWidget($form_state) && $this->getFieldSetting('title') == DRUPAL_REQUIRED) {
      $element['#element_validate'][] = [
        get_called_class(),
        'validateTitleElement',
      ];
    }

    // If cardinality is 1, ensure a proper label is output for the field.
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
      // If the link title is disabled, use the field definition label as the
      // title of the 'uri' element.
      if ($this->getFieldSetting('title') == DRUPAL_DISABLED) {
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
   * Recursively clean up options array if no data-icon is set.
   */
  public static function validateElement($element, FormStateInterface $form_state, $form) {
    $values = $form_state->getValue($element['#parents']);
    $values['icon_libraries'] = array_filter($values['icon_libraries'] ?? []);
    if (!empty($values['options']['attributes']['target'])) {
      $values['options']['attributes']['target'] = '_blank';
    }
    if (empty($values['options']['attributes']['data-icon'])) {
      $values['options']['attributes']['data-icon-position'] = '';
    }
    if (!empty($values)) {
      foreach ($values['options']['attributes'] as $attribute => $value) {
        if (!empty($value)) {
          if ($attribute == 'class') {
            $value = explode(' ', $value);
          }
          $values['options']['attributes'][$attribute] = $value;
          $values['attributes'][$attribute] = $value;
        }
        else {
          unset($values['options']['attributes'][$attribute]);
          unset($values['attributes'][$attribute]);
        }
      }
    }
    $form_state->setValueForElement($element, $values);
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

}
