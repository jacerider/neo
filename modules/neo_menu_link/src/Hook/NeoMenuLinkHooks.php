<?php

declare(strict_types=1);

namespace Drupal\neo_menu_link\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_icon\IconElement;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * The sub-module's entity, menu and schema hook implementations.
 *
 * Four of the nine hooks `neo_menu_link.module` carried: the base field info
 * alter that swaps the menu link entity's link field onto Neo's link widget,
 * the discovered links alter that points core's logout link at Neo's
 * login/logout menu link class, the menu preprocessor that turns a row's
 * `data-icon`, `data-class` and `data-target` options into an icon element,
 * wrapper classes and a target attribute, and the config schema alter that
 * extends core's static menu link overrides. The four form alters and the
 * callbacks they carry are on
 * \Drupal\neo_menu_link\Hook\NeoMenuLinkFormHooks, which is core's
 * `…Hooks` / `…FormHooks` split; between the two classes nothing procedural is
 * left, which is what lets the module set the hook scan skip parameter.
 *
 * Every body below is what stood in `neo_menu_link.module`, with one
 * substitution: the base field alter's `\Drupal::service()` reach for this
 * module's own settings repository became the constructor argument. Nothing
 * about what any of them decides moved with them — not which widget the link
 * field gets, not the order the menu preprocessor unsets its options in, not
 * the wrapper class it derives from each of them, not which keys reach the
 * overrides schema. The recursion the preprocessor ran through a second global
 * is a private method now, because nothing outside that body ever called it.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods, and nothing but the hook
 * system calls them.
 */
class NeoMenuLinkHooks {

  /**
   * Constructs a NeoMenuLinkHooks object.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settingsRepository
   *   This module's own settings repository, which answers the icon libraries,
   *   the target and class flags and the class list the link widget is
   *   configured with. It is declared in this module's own services file and is
   *   always present, so injecting it moves no failure earlier than the static
   *   call it replaces.
   */
  public function __construct(
    protected readonly SettingsRepositoryInterface $settingsRepository,
  ) {}

  /**
   * Implements hook_entity_base_field_info_alter().
   */
  #[Hook('entity_base_field_info_alter')]
  public function entityBaseFieldInfoAlter(&$fields, EntityTypeInterface $entity_type): void {
    if ($entity_type->id() === 'menu_link_content') {
      /** @var \Drupal\neo_menu_link\Settings\MenuLinkSettings $settings */
      $settings = $this->settingsRepository->getActive();
      $fields['link']->setDisplayOptions('form', [
        'type' => 'neo_link',
        'weight' => -2,
        'settings' => [
          'icon_libraries' => $settings->getValue('icon_libraries') ?? [],
          'target' => !empty($settings->getValue('target')),
          'class' => !empty($settings->getValue('class')),
          'class_list' => $settings->getClassList() ?? [],
        ],
      ]);
    }
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(&$links): void {
    $links['user.logout']['class'] = 'Drupal\neo_menu_link\NeoMenuLinkLoginLogoutMenuLink';
  }

  /**
   * Implements hook_preprocess_menu().
   */
  #[Hook('preprocess_menu')]
  public function preprocessMenu(&$variables): void {
    $variables['items'] = $this->preprocessMenuItems($variables['items']);
  }

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(&$definitions): void {
    if (isset($definitions['core.menu.static_menu_link_overrides'])) {
      $schema = [
        'options' => [
          'type' => 'mapping',
          'label' => 'Options',
          'mapping' => [
            'attributes' => [
              'type' => 'mapping',
              'title' => 'Attributes',
              'mapping' => [
                'data-icon' => [
                  'type' => 'string',
                  'label' => 'Icon',
                ],
                'data-target' => [
                  'type' => 'boolean',
                  'label' => 'Target',
                ],
                'data-class' => [
                  'type' => 'string',
                  'label' => 'Class',
                ],
                'title' => [
                  'type' => 'string',
                  'label' => 'Title',
                ],
              ],
            ],
          ],
        ],
      ];
      $definitions['core.menu.static_menu_link_overrides']['mapping']['definitions']['sequence']['mapping'] += $schema;
    }
  }

  /**
   * Iterates over each menu item and utilizes icon.
   *
   * @param array $items
   *   The menu items, each carrying a url, attributes and any children.
   *
   * @return array
   *   The same items, with the icon, class and target options applied.
   */
  private function preprocessMenuItems(array $items): array {
    foreach ($items as &$item) {
      $options = $item['url']->getOptions();
      if (!empty($options['attributes']['data-icon']) && !empty($item['title'])) {
        $position = $options['attributes']['data-icon-position'] ?? 'before';
        $icon = new IconElement($item['title'], $options['attributes']['data-icon']);
        $icon->iconPosition($position);
        $item['title'] = $icon->render();
        $item['url']->setOptions($options);
      }
      unset($options['attributes']['data-icon']);
      if (!empty($options['attributes']['data-class'])) {
        $options['attributes'] += ['class' => []];
        $classes = explode(' ', $options['attributes']['data-class']);
        $options['attributes']['class'] = array_merge($options['attributes']['class'], $classes);
        foreach ($classes as $class) {
          $item['attributes']->addClass($class . '-wrapper');
        }
      }
      unset($options['attributes']['data-class']);
      if (!empty($options['attributes']['data-target'])) {
        $options['attributes']['target'] = $options['attributes']['data-target'];
      }
      unset($options['attributes']['data-target']);
      if (!empty($item['below'])) {
        $item['below'] = $this->preprocessMenuItems($item['below']);
      }
      $item['url']->setOptions($options);
    }
    return $items;
  }

}
