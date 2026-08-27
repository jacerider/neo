<?php

declare(strict_types=1);

namespace Drupal\neo_menu_link\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent as MenuMenuLinkContent;
use Drupal\neo_menu_link\NeoMenuLinkDefault;
use Drupal\neo_menu_link\NeoMenuLinkLoginLogoutMenuLink;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\node\NodeInterface;

/**
 * The sub-module's form alter hook implementations.
 *
 * The other five of the nine hooks `neo_menu_link.module` carried, split from
 * \Drupal\neo_menu_link\Hook\NeoMenuLinkHooks the way core splits its own
 * `…Hooks` / `…FormHooks` pairs: the node form alter that adds the Neo icon
 * select to core's menu settings section and forks core's menu submit handler,
 * the menu link content form alter that adds the spacer checkbox, relaxes the
 * title and link required flags and prepends its validator, the menu link edit
 * form alter that adds the icon, target, class and description controls and
 * appends its submit handler, and the menu overview alter that styles each row
 * and puts the row's icon into its title.
 *
 * The fifth went rather than moved. `hook_module_implements_alter()` is one of
 * eleven hooks core's collector refuses on a class outright, so relocating it
 * was never available. Its whole body pushed this module's node form alter to
 * the end of the implementations list, which the ordering attribute on
 * ::formNodeFormAlter() states directly — and states more decisively, because
 * the attribute is applied to the combined listener list a form alter actually
 * runs through, which the legacy hook's build-time reordering is not. Deleting
 * it also clears a deprecation: a procedural implements-alter without core's
 * legacy attribute is deprecated as of Drupal 11.2.
 *
 * The four callbacks the forms carry are static methods here, named by their
 * `Class::method` string in the `#submit` and `#validate` arrays that carry
 * them. No global forwarder is kept for any of them: nothing outside this file
 * ever named them, and the arrays are rebuilt by the alter on every request.
 * One string deliberately does not change — the node form alter finds core's
 * own `menu_ui_form_node_form_submit` by `array_search` in order to replace it
 * in place, and core still defines that as a global on 11.4, so the search
 * stays exactly as it was.
 *
 * Every body below is what stood in `neo_menu_link.module`, with two
 * substitutions: the three alters that fetched this module's settings
 * repository through `\Drupal::service()` take it as a constructor argument,
 * and the two that fetched the current user take that the same way. Both are
 * always present — one is this module's own service and the other is core's —
 * so injecting them moves no failure earlier than the static calls they
 * replace. The `t()` calls became `$this->t()`, or a `TranslatableMarkup` in
 * the one static context, which is core's convention and renders the same
 * string. Nothing about what any of them decides moved with them.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods, and because a form's
 * `#submit` and `#validate` arrays reach the four statics by name.
 */
class NeoMenuLinkFormHooks {

  use StringTranslationTrait;

  /**
   * Constructs a NeoMenuLinkFormHooks object.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settingsRepository
   *   This module's own settings repository, which answers the icon libraries,
   *   the spacer, target and class flags and the class list the three form
   *   alters build their controls from. It is declared in this module's own
   *   services file and is always present.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, whose `use neo_menu_link` permission decides whether
   *   the icon select and the menu link edit controls are visible.
   */
  public function __construct(
    protected readonly SettingsRepositoryInterface $settingsRepository,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   *
   * Runs last, which is the single instruction the module's deleted
   * `hook_module_implements_alter()` carried: core's `menu_ui` has to have
   * built the menu settings section, and written the menu link's entity id
   * into it, before this reads either.
   */
  #[Hook('form_node_form_alter', order: Order::Last)]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state, $form_id): void {
    if (isset($form['menu'])) {
      // The menu_ui node form alter runs first and writes the menu link's
      // entity id into the form as a value element beside the link title and
      // weight. Read it from there rather than deriving the menu link defaults
      // again.
      $entity_id = $form['menu']['link']['entity_id']['#value'] ?? NULL;
      $options = [];
      if ($entity_id) {
        $menu = MenuLinkContent::load($entity_id);
        /** @var \Drupal\link\LinkItemInterface $item */
        $item = $menu->get('link')->first();
        $options = $item->get('options')->getValue();
      }
      /** @var \Drupal\neo_menu_link\Settings\MenuLinkSettings $settings */
      $settings = $this->settingsRepository->getActive();
      $form['menu']['link']['title']['#weight'] = -2;
      $form['menu']['link']['options']['#tree'] = TRUE;
      $form['menu']['link']['options']['#weight'] = -1;
      $form['menu']['link']['options']['attributes']['#tree'] = TRUE;
      $libraries = $settings->getValue('icon_libraries');
      $form['menu']['link']['options']['attributes']['data-icon'] = [
        '#type' => 'neo_icon_select',
        '#title' => $this->t('Icon'),
        '#default_value' => $options['attributes']['data-icon'] ?? NULL,
        '#libraries' => $libraries,
        '#access' => $this->currentUser->hasPermission('use neo_menu_link'),
      ];
      foreach (array_keys($form['actions']) as $action) {
        if ($action != 'preview' && isset($form['actions'][$action]['#type']) && $form['actions'][$action]['#type'] === 'submit') {
          // Drupal 11.4 converted menu_ui to OOP hooks, so the handler
          // core registers became the method callable. Older supported
          // cores still register the procedural function, so match either
          // and replace it in place: appending would run the fork after
          // core has already saved the link.
          foreach ([
            'menu_ui_form_node_form_submit',
            'Drupal\\menu_ui\\Hook\\MenuUiHooks:formNodeFormSubmit',
          ] as $handler) {
            if (($key = array_search($handler, $form['actions'][$action]['#submit'])) !== FALSE) {
              $form['actions'][$action]['#submit'][$key] = static::class . '::menuUiFormNodeFormSubmit';
            }
          }
        }
      }
    }
  }

  /**
   * Form submission handler for menu item field on the node form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @see menu_ui_form_node_form_submit()
   */
  public static function menuUiFormNodeFormSubmit(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_object->getEntity();
    if (!$form_state->isValueEmpty('menu')) {
      $values = $form_state->getValue('menu');
      if (empty($values['enabled'])) {
        if ($values['entity_id']) {
          $entity = MenuLinkContent::load($values['entity_id']);
          $entity->delete();
        }
      }
      elseif (trim($values['title'])) {
        // Decompose the selected menu parent option into 'menu_name' and
        // 'parent', if the form used the default parent selection widget.
        if (!empty($values['menu_parent'])) {
          [$menu_name, $parent] = explode(':', $values['menu_parent'], 2);
          $values['menu_name'] = $menu_name;
          $values['parent'] = $parent;
        }
        static::menuUiNodeSave($node, $values);
      }
    }
  }

  /**
   * Helper function to create or update a menu link for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node entity.
   * @param array $values
   *   Values for the menu link.
   *
   * @see _menu_ui_node_save()
   */
  public static function menuUiNodeSave(NodeInterface $node, array $values): void {
    /** @var \Drupal\menu_link_content\MenuLinkContentInterface $entity */
    if (!empty($values['entity_id'])) {
      $entity = MenuLinkContent::load($values['entity_id']);
      if ($entity->isTranslatable()) {
        if (!$entity->hasTranslation($node->language()->getId())) {
          $entity = $entity->addTranslation($node->language()->getId(), $entity->toArray());
        }
        else {
          $entity = $entity->getTranslation($node->language()->getId());
        }
      }
    }
    else {
      // Create a new menu_link_content entity.
      $entity = MenuLinkContent::create([
        'link' => ['uri' => 'entity:node/' . $node->id()],
        'langcode' => $node->language()->getId(),
      ]);
      $entity->enabled->value = 1;
    }

    $entity->title->value = trim($values['title']);
    $entity->description->value = trim($values['description']);

    // Save link attributes as necessary.
    $link = $entity->link->first()->getValue();
    $link['options'] += ['attributes' => []];
    $link['options']['attributes'] = array_filter($values['options']['attributes'] + $link['options']['attributes']);
    $link['options'] = array_filter($link['options']);
    $entity->link->first()->setValue($link);

    $entity->menu_name->value = $values['menu_name'];
    $entity->parent->value = $values['parent'];
    $entity->weight->value = $values['weight'] ?? 0;
    $entity->save();
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_menu_link_content_menu_link_content_form_alter')]
  public function formMenuLinkContentMenuLinkContentFormAlter(&$form, FormStateInterface $form_state, $form_id): void {
    /** @var \Drupal\neo_menu_link\Settings\MenuLinkSettings $settings */
    $settings = $this->settingsRepository->getActive();
    /** @var \Drupal\menu_link_content\Form\MenuLinkContentForm $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\menu_link_content\MenuLinkContentInterface $entity */
    $entity = $form_object->getEntity();
    if ($settings->getValue('spacers')) {
      // Set fields as non-required. We will check them in validation. We do
      // this because when spacer is enabled we set these dynamically but
      // FormValidator already checked #required and returns an error at this
      // stage.
      $form['title']['widget'][0]['value']['#required'] = FALSE;
      $form['link']['widget'][0]['uri']['#required'] = FALSE;
      $spacer_enabled = FALSE;
      if (!$entity->isNew()) {
        $options = $entity->getUrlObject()->getOptions();
        $spacer_enabled = !empty($options['spacer']);
      }
      $html_id = Html::getUniqueId('neo-menu-link-spacer');
      $form['spacer'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show as spacer'),
        '#description' => $this->t('If selected and this menu link will be used as a spacer between other links.'),
        '#default_value' => $spacer_enabled,
        '#id' => $html_id,
        '#weight' => -10,
      ];
      $form['spacer']['widget']['value']['#id'] = $html_id;
      foreach ([
        'title',
        'link',
        'expanded',
        'description',
      ] as $key) {
        $form[$key]['#states'] = [
          'invisible' => [
            '#' . $html_id => ['checked' => TRUE],
          ],
        ];
      }
      array_unshift($form['#validate'], static::class . '::formMenuLinkContentMenuLinkContentFormValidate');
    }
  }

  /**
   * Validation callback for menu link form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function formMenuLinkContentMenuLinkContentFormValidate(&$form, FormStateInterface $form_state): void {
    if ($form_state->getValue(['spacer'], FALSE)) {
      $form_state->setValue(['title', 0, 'value'], 'Spacer');
      $form_state->setValue(['link', 0, 'uri'], '#');
      $form_state->setValue(['link', 0, 'options', 'spacer'], TRUE);
    }
    else {
      if (!$form_state->getValue(['title', 0, 'value'], FALSE)) {
        $form_state->setError($form['title']['widget'][0]['value'], new TranslatableMarkup('@name field is required.', ['@name' => $form['title']['widget'][0]['value']['#title']]));
      }
      if (!$form_state->getValue(['link', 0, 'uri'], FALSE)) {
        $form_state->setError($form['link']['widget'][0]['uri'], new TranslatableMarkup('@name field is required.', ['@name' => $form['link']['widget'][0]['uri']['#title']]));
      }
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_menu_link_edit_alter')]
  public function formMenuLinkEditAlter(&$form, FormStateInterface $form_state, $form_id): void {
    /** @var \Drupal\neo_menu_link\Settings\MenuLinkSettings $settings */
    $settings = $this->settingsRepository->getActive();
    $access = $this->currentUser->hasPermission('use neo_menu_link');

    $options = $form_state->getBuildInfo()['args'][0]->getOptions();
    $form['path']['link']['data-icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#default_value' => $options['attributes']['data-icon'] ?? NULL,
      '#packages' => $settings->getValue('icon_libraries'),
      '#access' => $access,
    ];
    if (!empty($settings->getValue('target'))) {
      $form['path']['link']['data-target'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Open link in new window'),
        '#description' => $this->t('If selected, the menu link will open in a new window/tab when clicked.'),
        '#default_value' => $options['attributes']['data-target'] ?? NULL,
        '#return_value' => '_blank',
        '#access' => $access,
      ];
    }
    if (!empty($settings->getValue('class'))) {
      if ($listOptions = $settings->getClassList()) {
        $form['path']['link']['data-class'] = [
          '#type' => 'select',
          '#title' => $this->t('CSS classes'),
          '#options' => $listOptions,
          '#empty_option' => $this->t('- None -'),
          '#description' => $this->t('Enter space-separated CSS class names that will be added to the link.'),
          '#default_value' => $options['attributes']['data-class'] ?? NULL,
          '#access' => $access,
        ];
      }
      else {
        $form['path']['link']['data-class'] = [
          '#type' => 'textfield',
          '#title' => $this->t('CSS classes'),
          '#description' => $this->t('Enter space-separated CSS class names that will be added to the link.'),
          '#default_value' => $options['attributes']['data-class'] ?? NULL,
          '#access' => $access,
        ];
      }
    }

    $form['path']['link']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $options['attributes']['title'] ?? NULL,
      '#access' => $access,
    ];

    $form['#submit'][] = static::class . '::formMenuLinkEditAlterSubmit';
  }

  /**
   * Process the submitted form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function formMenuLinkEditAlterSubmit(array $form, FormStateInterface $form_state): void {
    $menu_link_id = $form_state->getValue('menu_link_id');
    if (!empty($menu_link_id)) {
      $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
      $options = $form_state->getBuildInfo()['args'][0]->getOptions();
      foreach (['title'] as $key) {
        $options['attributes'][$key] = $form_state->getValue($key);
      }
      foreach (['icon', 'target', 'class'] as $key) {
        $options['attributes']['data-' . $key] = $form_state->getValue('data-' . $key);
      }
      $menu_link_manager->updateDefinition($menu_link_id, ['options' => $options]);
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_menu_edit_form_alter')]
  public function formMenuEditFormAlter(array &$form, FormStateInterface $form_state, $form_id): void {
    if (empty($form['links']['links'])) {
      return;
    }
    foreach (Element::children($form['links']['links']) as $key) {
      $element = &$form['links']['links'][$key];
      $element['title']['#neo_style'] = 'heading';
      $element['operations']['#neo_size'] = 'min';
      $item = $element['#item'];
      $icon = NULL;
      if ($item instanceof MenuLinkTreeElement) {
        $link = $item->link;
        if ($link instanceof NeoMenuLinkDefault) {
          $icon = $link->getOptions()['attributes']['data-icon'] ?? NULL;
        }
        elseif ($link instanceof MenuMenuLinkContent || $link instanceof NeoMenuLinkLoginLogoutMenuLink) {
          $definition = $link->getPluginDefinition();
          $icon = $definition['options']['attributes']['data-icon'] ?? NULL;
        }
      }
      if ($icon) {
        if (is_array($element['title'])) {
          if (isset($element['title'][1]['#title'])) {
            $element['title'][1]['#title'] = neo_icon($element['title'][1]['#title'], $icon);
          }
        }
        else {
          $element['title'] = neo_icon($element['title'], $icon);
        }
      }
    }
  }

}
