<?php

namespace Drupal\neo\Plugin\Field\FieldFormatter;

use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Utility\Token;
use Drupal\neo_icon\IconTranslationTrait;

/**
 * Plugin implementation of the 'neo_link' formatter.
 *
 * @FieldFormatter(
 *   id = "neo_link",
 *   label = @Translation("Neo | Link"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
class NeoLinkFormatter extends LinkFormatter {

  use IconTranslationTrait;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, PathValidatorInterface $path_validator, Token $token) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $path_validator);
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('path.validator'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'title' => '',
      'icon' => '',
      'position' => '',
      'title_only' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($title = $this->getSetting('title')) {
      $summary[] = $this->t('Link title as @title', ['@title' => $title]);
    }
    if ($icon = $this->getSetting('icon')) {
      $summary[] = $this->icon('Icon as', $icon)->iconAfter();
    }
    if ($icon = $this->getSetting('title_only')) {
      $summary[] = $this->t('Title only (no link)');
    }
    if ($position = $this->getSetting('position')) {
      $positions = [
        'before' => $this->t('Before'),
        'after' => $this->t('After'),
      ];
      $summary[] = $this->t('Icon position: %position', [
        '%position' => $positions[$position],
      ]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link title'),
      '#default_value' => $this->getSetting('title'),
      '#description' => $this->t('Will be used as the link title even if one has been set on the field. Supports token replacement.'),
    ];
    $form['icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#default_value' => $this->getSetting('icon'),
      '#description' => $this->t('Will be used as the link icon even if one has been set on the field.'),
    ];
    $form['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Icon position'),
      '#description' => $this->t('Will be used as the link icon position even if one has been set on the field.'),
      '#options' => [
        'before' => $this->t('Before'),
        'after' => $this->t('After'),
      ],
      '#empty_option' => $this->t('Use field defined position'),
      '#default_value' => $this->getSetting('position'),
    ];
    $form['title_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Do not link'),
      '#default_value' => $this->getSetting('title_only'),
      '#description' => $this->t('Show only the link title without making it linkable.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    $entity = $items->getEntity();
    $entity_type = $entity->getEntityTypeId();
    $title = $this->getSetting('title');
    $default_position = $this->getSetting('position');
    $icon_id = $this->getSetting('icon');
    $title_only = $this->getSetting('title_only');
    foreach ($elements as $delta => &$element) {
      $element['#type'] = 'link';
      if ($title && empty($items[$delta]->title)) {
        $element['#title'] = $this->token->replace($title, [$entity_type => $entity]);
      }
      if (!$icon_id && !empty($element['#options']['attributes']['data-icon'])) {
        $icon_id = $element['#options']['attributes']['data-icon'];
      }
      if (!$icon_id) {
        $element['#title'] = [
          '#markup' => '<span>' . $element['#title'] . '</span>',
        ];
      }
      if ($icon_id) {
        $position = !empty($element['#options']['attributes']['data-icon-position']) ? $element['#options']['attributes']['data-icon-position'] : $default_position;
        $icon = $this->icon($element['#title'], $icon_id);
        if ($position == 'after') {
          $icon->iconAfter();
        }
        $element['#title'] = $icon;
        unset($element['#options']['attributes']['data-icon']);
      }
      if ($title_only) {
        $element = $element['#title'];
      }
    }
    return $elements;
  }

}
