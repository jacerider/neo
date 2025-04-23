<?php

declare(strict_types=1);

namespace Drupal\neo;

use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\neo\Helpers\Str;
use Drupal\neo_icon\IconTranslationTrait;

/**
 * Provides a slide menu component for Drupal.
 *
 * This class creates a hierarchical slide menu with customizable attributes
 * and behaviors. The menu can have multiple levels of nested items, with
 * back and "view all" controls for navigation.
 *
 * @example
 * $menu = new SlideMenu([
 *   [
 *     'title' => 'Products',
 *     'url' => Url::fromRoute('<front>'),
 *     'after' => ['#markup' => 'will be after'],
 *     'children' => [
 *       [
 *        'title' => 'Category 1',
 *        'url' => Url::fromRoute('<front>'),
 *        'after' => ['#markup' => 'will be after'],
 *       ],
 *       [
 *        'title' => 'Category 1',
 *        'url' => Url::fromRoute('<front>'),
 *       ],
 *     ],
 *   ],
 * ]);
 * $render = $menu->toRenderable();
 */
class SlideMenu implements RenderableInterface {

  use StringTranslationTrait;
  use IconTranslationTrait;

  /**
   * The menu items.
   *
   * @var array
   */
  protected array $items;

  /**
   * Attributes applied to each menu item.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected Attribute $itemAttributes;

  /**
   * Attributes applied to each menu link.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected Attribute $linkAttributes;

  /**
   * Icon displayed for items with children.
   *
   * @var string
   */
  protected string $childIcon = 'chevron-right';

  /**
   * Attributes applied to child indicator icons.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected Attribute $childIconAttributes;

  /**
   * Whether to show back links in submenu levels.
   *
   * @var bool
   */
  protected bool $backStatus = TRUE;

  /**
   * Icon used for back navigation.
   *
   * @var string
   */
  protected string $backIcon = 'chevron-left';

  /**
   * Label used for back navigation.
   *
   * @var string
   */
  protected string $backLabel = 'Back';

  /**
   * Attributes applied to back links.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected Attribute $backAttributes;

  /**
   * Attributes applied to back icons.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected Attribute $backIconAttributes;

  /**
   * Whether to show "view all" links in submenus.
   *
   * @var bool
   */
  protected bool $allStatus = TRUE;

  /**
   * Prefix text for "view all" links.
   *
   * @var string
   */
  protected string $allPrefix = 'View all';

  /**
   * Suffix text for "view all" links.
   *
   * @var string
   */
  protected string $allSuffix = 'Right now';

  /**
   * Constructs a new SlideMenu.
   *
   * @param array $items
   *   The menu items to display.
   * @param array $options
   *   Optional configuration options. Any property of this class can be set
   *   via these options using snake_case keys.
   */
  public function __construct(array $items, array $options = []) {
    $this->setItems($items);

    // Initialize default attributes.
    $this->itemAttributes = new Attribute();
    $this->linkAttributes = new Attribute([
      'class' => [
        'flex items-center justify-between',
      ],
    ]);
    $this->backAttributes = new Attribute([
      'class' => [
        'flex items-center justify-between w-full',
        'neo-slide-menu--backlink',
        'neo-slide-menu--control',
      ],
      'data-action' => 'back',
    ]);
    $this->backIconAttributes = new Attribute();
    $this->childIconAttributes = new Attribute();

    // Apply options.
    $this->applyOptions($options);
  }

  /**
   * Applies configuration options to this menu instance.
   *
   * @param array $options
   *   Configuration options as key-value pairs.
   */
  protected function applyOptions(array $options): void {
    if (empty($options)) {
      return;
    }

    $class = new \ReflectionClass($this);
    foreach ($options as $key => $option) {
      $method = 'set' . ucfirst(Str::camel($key));
      if (method_exists($this, $method)) {
        $parameter = $class->getMethod($method)->getParameters()[0] ?? NULL;
        if ($parameter) {
          $type = (string) $parameter->getType();
          $option = match ($type) {
            'string' => (string) $option,
            'int' => (int) $option,
            'bool' => (bool) $option,
            default => $option,
          };
        }
        $this->$method($option);
      }
    }
  }

  /**
   * Sets the menu items.
   *
   * @param array $items
   *   The menu items.
   */
  public function setItems(array $items): void {
    $this->items = $items;
  }

  /**
   * Gets the menu items.
   *
   * @return array
   *   The menu items.
   */
  public function getItems(): array {
    return $this->items;
  }

  /**
   * Sets attributes for menu items.
   *
   * @param array $attributes
   *   The attributes to set.
   */
  public function setItemAttributes(array $attributes): void {
    $this->itemAttributes->merge(new Attribute($attributes));
  }

  /**
   * Gets the item attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The item attributes.
   */
  public function getItemAttributes(): Attribute {
    return $this->itemAttributes;
  }

  /**
   * Sets attributes for menu links.
   *
   * @param array $attributes
   *   The attributes to set.
   */
  public function setLinkAttributes(array $attributes): void {
    $this->linkAttributes->merge(new Attribute($attributes));
  }

  /**
   * Gets the link attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The link attributes.
   */
  public function getLinkAttributes(): Attribute {
    return $this->linkAttributes;
  }

  /**
   * Sets the icon to use for menu items with children.
   *
   * @param string $icon
   *   The icon name.
   */
  public function setChildIcon(string $icon): void {
    $this->childIcon = $icon;
  }

  /**
   * Gets the child icon.
   *
   * @return string
   *   The child icon.
   */
  public function getChildIcon(): string {
    return $this->childIcon;
  }

  /**
   * Sets attributes for child icons.
   *
   * @param array $attributes
   *   The attributes to set.
   */
  public function setChildIconAttributes(array $attributes): void {
    $this->childIconAttributes->merge(new Attribute($attributes));
  }

  /**
   * Gets the child icon attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The child icon attributes.
   */
  public function getChildIconAttributes(): Attribute {
    return $this->childIconAttributes;
  }

  /**
   * Sets whether back links should be shown.
   *
   * @param bool $status
   *   TRUE to show back links, FALSE to hide them.
   */
  public function setBackStatus(bool $status): void {
    $this->backStatus = $status;
  }

  /**
   * Gets whether back links are shown.
   *
   * @return bool
   *   TRUE if back links are shown, FALSE otherwise.
   */
  public function getBackStatus(): bool {
    return $this->backStatus;
  }

  /**
   * Sets the icon to use for back links.
   *
   * @param string $icon
   *   The icon name.
   */
  public function setBackIcon(string $icon): void {
    $this->backIcon = $icon;
  }

  /**
   * Gets the back icon.
   *
   * @return string
   *   The back icon.
   */
  public function getBackIcon(): string {
    return $this->backIcon;
  }

  /**
   * Sets attributes for back links.
   *
   * @param array $attributes
   *   The attributes to set.
   */
  public function setBackAttributes(array $attributes): void {
    $this->backAttributes->merge(new Attribute($attributes));
  }

  /**
   * Gets the back link attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The back link attributes.
   */
  public function getBackAttributes(): Attribute {
    return $this->backAttributes;
  }

  /**
   * Sets attributes for back icons.
   *
   * @param array $attributes
   *   The attributes to set.
   */
  public function setBackIconAttributes(array $attributes): void {
    $this->backIconAttributes->merge(new Attribute($attributes));
  }

  /**
   * Gets the back icon attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The back icon attributes.
   */
  public function getBackIconAttributes(): Attribute {
    return $this->backIconAttributes;
  }

  /**
   * Sets the label for back links.
   *
   * @param string $label
   *   The label to use.
   */
  public function setBackLabel(string $label): void {
    $this->backLabel = $label;
  }

  /**
   * Gets the back link label.
   *
   * @return string
   *   The back link label.
   */
  public function getBackLabel(): string {
    return $this->backLabel;
  }

  /**
   * Sets whether "view all" links should be shown.
   *
   * @param bool $status
   *   TRUE to show "view all" links, FALSE to hide them.
   */
  public function setAllStatus(bool $status): void {
    $this->allStatus = $status;
  }

  /**
   * Gets whether "view all" links are shown.
   *
   * @return bool
   *   TRUE if "view all" links are shown, FALSE otherwise.
   */
  public function getAllStatus(): bool {
    return $this->allStatus;
  }

  /**
   * Sets the prefix text for "view all" links.
   *
   * @param string $prefix
   *   The prefix text.
   */
  public function setAllPrefix(string $prefix): void {
    $this->allPrefix = $prefix;
  }

  /**
   * Gets the "view all" prefix text.
   *
   * @return string
   *   The "view all" prefix text.
   */
  public function getAllPrefix(): string {
    return $this->allPrefix;
  }

  /**
   * Sets the suffix text for "view all" links.
   *
   * @param string $suffix
   *   The suffix text.
   */
  public function setAllSuffix(string $suffix): void {
    $this->allSuffix = $suffix;
  }

  /**
   * Gets the "view all" suffix text.
   *
   * @return string
   *   The "view all" suffix text.
   */
  public function getAllSuffix(): string {
    return $this->allSuffix;
  }

  /**
   * Builds render arrays for a collection of menu items.
   *
   * @param array $items
   *   The menu items to build.
   *
   * @return array
   *   A render array representing the items.
   */
  protected function buildItems(array $items): array {
    $build = [
      '#theme' => 'item_list',
      '#items' => [],
    ];

    foreach ($items as $item) {
      $build['#items'][] = $this->buildItem($item);
    }

    return $build;
  }

  /**
   * Builds a render array for a single menu item.
   *
   * @param array $item
   *   The menu item to build.
   *
   * @return array
   *   A render array representing the item.
   */
  protected function buildItem(array $item): array {
    $build = [
      '#wrapper_attributes' => $item['item_attributes'] ?? $this->getItemAttributes()->toArray(),
    ];

    $linkAttributes = $item['link_attributes'] ?? $this->getLinkAttributes()->toArray();

    // Handle items with children.
    if (!empty($item['children'])) {
      $build['children'] = $this->buildItems($item['children']);

      if (!empty($build['children']['#items'])) {
        $linkAttributes['class'][] = 'neo-slide-menu--children';
        $linkAttributes['aria-expanded'] = 'false';
        $linkAttributes['aria-haspopup'] = 'true';

        $build['children']['#attributes']['data-parent-title'] = $item['title'];

        // Add "view all" link if enabled.
        if ($all = $this->buildItemAll($item)) {
          $build['children']['#items'] = [
            'all' => $all,
          ] + $build['children']['#items'];
        }

        // Add back link if enabled.
        if ($back = $this->buildItemBack($item)) {
          $build['children']['#items'] = [
            'back' => $back,
          ] + $build['children']['#items'];
        }

        // Format item title with child indicator icon.
        $suffix = $this->getChildIcon()
          ? $this->icon($this->t('View children of @title', ['@title' => $item['title']]), $this->getChildIcon())->iconOnly()->setIconAttributes($this->getChildIconAttributes()->toArray())
          : '';
      }
    }

    $item['title'] = [
      '#type' => 'inline_template',
      '#template' => '<span>{{ label }}</span>{% if suffix %} {{ suffix }}{% endif %}',
      '#context' => [
        'label' => $item['title'],
        'suffix' => $suffix ?? '',
      ],
    ];

    // Create link or non-link element.
    if (!empty($item['url'])) {
      $build['link'] = [
        '#type' => 'link',
        '#title' => $item['title'],
        '#url' => $item['url'],
        '#attributes' => $linkAttributes,
      ];
    }
    else {
      $build['link'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => $linkAttributes + ['type' => 'button'],
        'value' => $item['title'],
      ];
    }
    if (!empty($item['after'])) {
      $build['after'] = $item['after'];
    }

    return $build;
  }

  /**
   * Builds a back link for navigating up in the menu hierarchy.
   *
   * @param array $item
   *   The parent menu item.
   *
   * @return array
   *   A render array for the back link.
   */
  protected function buildItemBack(array $item): array {
    $title = $this->t('Back to @label', [
      '@label' => is_array($item['title']) ? ($item['title']['#context']['label'] ?? '') : $item['title'],
    ]);

    $backAttributes = clone $this->getBackAttributes();
    $backAttributes->setAttribute('aria-label', $title);

    $back = $this->buildItem([
      'title' => [
        '#type' => 'inline_template',
        '#template' => '{% if icon %}{{ icon }} {% endif %}{{ label }}',
        '#context' => [
          'icon' => $this->getBackIcon()
            ? $this->icon($title, $this->getBackIcon())->iconOnly()->setIconAttributes($this->getBackIconAttributes()->toArray())
            : '',
          'label' => $this->getBackLabel(),
        ],
      ],
      'link_attributes' => $backAttributes->toArray(),
    ]);

    if (empty($item['#back_status']) && empty($this->getBackStatus())) {
      $back['#wrapper_attributes']['class'][] = 'sr-only';
    }

    return $back;
  }

  /**
   * Builds a "view all" link for a submenu.
   *
   * @param array $item
   *   The parent menu item.
   *
   * @return array
   *   A render array for the "view all" link.
   */
  protected function buildItemAll(array $item): array {
    if (empty($item['url'])) {
      return [];
    }

    $allAttributes = $item['link_attributes'] ?? $this->getLinkAttributes()->toArray();
    $allAttributes['class'][] = 'neo-slide-menu--all';

    $label = is_array($item['title']) ? ($item['title']['#context']['label'] ?? '') : $item['title'];
    $title = $this->t('@prefix @label @suffix', [
      '@prefix' => $this->getAllPrefix(),
      '@label' => $label,
      '@suffix' => $this->getAllSuffix(),
    ]);

    $allAttributes['aria-label'] = $title;

    $all = $this->buildItem([
      'title' => [
        '#type' => 'inline_template',
        '#template' => '{% if prefix %}{{ prefix }} {% endif %}{{ label }}{% if suffix %} {{ suffix }}{% endif %}',
        '#context' => [
          'prefix' => $this->getAllPrefix(),
          'label' => $label,
          'suffix' => $this->getAllSuffix(),
        ],
      ],
      'url' => isset($item['url']) ? clone $item['url'] : NULL,
      'link_attributes' => $allAttributes,
    ]);

    if (empty($item['#all_status']) && empty($this->getAllStatus())) {
      $all['#wrapper_attributes']['class'][] = 'sr-only';
    }
    return $all;
  }

  /**
   * {@inheritDoc}
   */
  public function toRenderable(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'nav',
      '#attributes' => [
        'class' => ['neo-slide-menu'],
        'aria-label' => $this->t('Slide menu'),
        'role' => 'navigation',
      ],
      '#attached' => [
        'library' => [
          'neo/slide-menu',
        ],
        'drupalSettings' => [
          'neo' => [
            'slideMenu' => [
              'backLabel' => $this->getBackLabel(),
            ],
          ],
        ],
      ],
      'menu' => $this->buildItems($this->getItems()),
    ];
  }

}
