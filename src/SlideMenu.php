<?php

declare(strict_types=1);

namespace Drupal\neo;

use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\neo\Helpers\Str;
use Drupal\neo_icon\IconTrait;

/**
 * Provides a slide menu component for Drupal.
 *
 * This class creates a hierarchical slide menu with customizable attributes
 * and behaviors. The menu can have multiple levels of nested items, with
 * back and "view all" controls for navigation.
 *
 * The second constructor argument is the option array. Every key it accepts is
 * a case of \Drupal\neo\SlideMenuOption, which is where the option set is
 * stated: each case carries the key, the value the option starts at and the
 * type an incoming value is cast to. An attribute option merges into the
 * default rather than replacing it, which is why the example below adds a
 * class to the link bag instead of losing the three it already carries. A key
 * the enum does not name is ignored in silence.
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
 * ], [
 *   'link_attributes' => ['class' => ['gap-2']],
 * ]);
 * $render = $menu->toRenderable();
 */
class SlideMenu implements RenderableInterface {

  use StringTranslationTrait;
  use IconTrait;

  /**
   * The menu items.
   *
   * This and the thirteen properties below are storage for one option apiece
   * and nothing else. The value each starts at lives on SlideMenuOption, the
   * constructor seeds it from there, and ::option() and ::setOption() are the
   * only code that reaches them — nothing else in the class, the constructor
   * and ::applyOptions() included, knows an option is stored here at all.
   *
   * @var array<int|string, mixed>
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
  protected string $childIcon;

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
  protected bool $backStatus;

  /**
   * Icon used for back navigation.
   *
   * @var string
   */
  protected string $backIcon;

  /**
   * Label used for back navigation.
   *
   * @var string
   */
  protected string $backLabel;

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
  protected bool $allStatus;

  /**
   * The depth at which children render inline instead of as slide levels.
   *
   * 0 disables inline expansion. With a value of N, items at depth N (and
   * deeper) render their children expanded within the current panel as a
   * grouped list — e.g. 2 turns second-level items into group headings whose
   * children are listed directly beneath them (a mobile mega menu) instead
   * of triggers for a third slide level.
   *
   * @var int
   */
  protected int $expandDepth;

  /**
   * Prefix text for "view all" links.
   *
   * @var string
   */
  protected string $allPrefix;

  /**
   * Suffix text for "view all" links.
   *
   * @var string
   */
  protected string $allSuffix;

  /**
   * Constructs a new SlideMenu.
   *
   * @param array<int|string, mixed> $items
   *   The menu items to display.
   * @param array<string, mixed> $options
   *   Optional configuration options, keyed by the option key each
   *   \Drupal\neo\SlideMenuOption case is valued with.
   */
  public function __construct(array $items, array $options = []) {
    // Seed every option from its case's default, which is where the fourteen
    // defaults are stated. Seeding happens before the options are applied
    // because an attribute option merges into what it finds: a caller passing
    // a class to the link bag adds one to the three the default carries
    // rather than replacing them.
    foreach (SlideMenuOption::cases() as $option) {
      $this->setOption($option, $option->defaultValue());
    }

    $this->setItems($items);

    // Apply options.
    $this->applyOptions($options);
  }

  /**
   * Applies configuration options to this menu instance.
   *
   * A key SlideMenuOption names is cast to that option's own type and
   * dispatched through the typed match below — that is every key a caller
   * passes, and none of them reaches a ReflectionClass any more. A key the
   * enum does not name falls back to the reflection dispatch this class has
   * always used, so a subclass that added a setter of its own keeps having its
   * key accepted. A key neither answers is ignored in silence: the option
   * array is assembled by other packages and by site code, so a stale key
   * stays a no-op rather than becoming an outage.
   *
   * @param array<string, mixed> $options
   *   Configuration options as key-value pairs.
   */
  protected function applyOptions(array $options): void {
    if (empty($options)) {
      return;
    }

    $class = NULL;
    foreach ($options as $key => $option) {
      // The key is cast because a PHP array key may be an int, and the
      // fallback below has always taken one without raising.
      $case = SlideMenuOption::tryFrom((string) $key);
      if ($case) {
        // The setters are the write side on purpose: an attribute option
        // merges into the bag it already holds and the expand depth clamps,
        // and both of those live there.
        $value = $case->cast($option);
        match ($case) {
          SlideMenuOption::Items => $this->setItems($value),
          SlideMenuOption::ItemAttributes => $this->setItemAttributes($value),
          SlideMenuOption::LinkAttributes => $this->setLinkAttributes($value),
          SlideMenuOption::ChildIcon => $this->setChildIcon($value),
          SlideMenuOption::ChildIconAttributes => $this->setChildIconAttributes($value),
          SlideMenuOption::BackStatus => $this->setBackStatus($value),
          SlideMenuOption::BackIcon => $this->setBackIcon($value),
          SlideMenuOption::BackAttributes => $this->setBackAttributes($value),
          SlideMenuOption::BackIconAttributes => $this->setBackIconAttributes($value),
          SlideMenuOption::BackLabel => $this->setBackLabel($value),
          SlideMenuOption::AllStatus => $this->setAllStatus($value),
          SlideMenuOption::AllPrefix => $this->setAllPrefix($value),
          SlideMenuOption::AllSuffix => $this->setAllSuffix($value),
          SlideMenuOption::ExpandDepth => $this->setExpandDepth($value),
        };
        continue;
      }

      $method = 'set' . ucfirst(Str::camel($key));
      if (method_exists($this, $method)) {
        $class ??= new \ReflectionClass($this);
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
   * Answers the value an option currently holds.
   *
   * The only code in this class that knows where an option is stored, which is
   * what makes a fifteenth option one SlideMenuOption case and two forwarders
   * rather than a property, a constructor line, a default and two more method
   * bodies.
   *
   * It answers the menu's own value, never a copy. The five attribute bags are
   * mutable and shared on purpose: buildItemBack() clones the back bag itself
   * before labelling its own row, precisely because the bag it is handed is
   * the one every later setBackAttributes() writes to. A copy handed out here
   * would make every one of those writes vanish.
   *
   * Protected, deliberately: every option is already reachable from a
   * fixture-free unit test through the twenty-eight methods that have always
   * been public, so publishing this would add a permanent promise to an API
   * another package calls and change nothing about what a test has to build.
   *
   * @param \Drupal\neo\SlideMenuOption $option
   *   The option to answer.
   *
   * @return mixed
   *   The menu's own value for that option.
   */
  protected function option(SlideMenuOption $option): mixed {
    return match ($option) {
      SlideMenuOption::Items => $this->items,
      SlideMenuOption::ItemAttributes => $this->itemAttributes,
      SlideMenuOption::LinkAttributes => $this->linkAttributes,
      SlideMenuOption::ChildIcon => $this->childIcon,
      SlideMenuOption::ChildIconAttributes => $this->childIconAttributes,
      SlideMenuOption::BackStatus => $this->backStatus,
      SlideMenuOption::BackIcon => $this->backIcon,
      SlideMenuOption::BackAttributes => $this->backAttributes,
      SlideMenuOption::BackIconAttributes => $this->backIconAttributes,
      SlideMenuOption::BackLabel => $this->backLabel,
      SlideMenuOption::AllStatus => $this->allStatus,
      SlideMenuOption::AllPrefix => $this->allPrefix,
      SlideMenuOption::AllSuffix => $this->allSuffix,
      SlideMenuOption::ExpandDepth => $this->expandDepth,
    };
  }

  /**
   * Replaces the value an option holds.
   *
   * The write half of ::option(), and the other half of what knows where an
   * option is stored. An attribute option is never written through here after
   * construction: its setter merges into the bag ::option() answers, which is
   * what keeps calling one twice accumulating.
   *
   * @param \Drupal\neo\SlideMenuOption $option
   *   The option to write.
   * @param mixed $value
   *   The value to store, already cast to that option's type.
   */
  protected function setOption(SlideMenuOption $option, mixed $value): void {
    match ($option) {
      SlideMenuOption::Items => $this->items = $value,
      SlideMenuOption::ItemAttributes => $this->itemAttributes = $value,
      SlideMenuOption::LinkAttributes => $this->linkAttributes = $value,
      SlideMenuOption::ChildIcon => $this->childIcon = $value,
      SlideMenuOption::ChildIconAttributes => $this->childIconAttributes = $value,
      SlideMenuOption::BackStatus => $this->backStatus = $value,
      SlideMenuOption::BackIcon => $this->backIcon = $value,
      SlideMenuOption::BackAttributes => $this->backAttributes = $value,
      SlideMenuOption::BackIconAttributes => $this->backIconAttributes = $value,
      SlideMenuOption::BackLabel => $this->backLabel = $value,
      SlideMenuOption::AllStatus => $this->allStatus = $value,
      SlideMenuOption::AllPrefix => $this->allPrefix = $value,
      SlideMenuOption::AllSuffix => $this->allSuffix = $value,
      SlideMenuOption::ExpandDepth => $this->expandDepth = $value,
    };
  }

  /**
   * Sets the menu items.
   *
   * @param array<int|string, mixed> $items
   *   The menu items.
   */
  public function setItems(array $items): void {
    $this->setOption(SlideMenuOption::Items, $items);
  }

  /**
   * Gets the menu items.
   *
   * @return array<int|string, mixed>
   *   The menu items.
   */
  public function getItems(): array {
    return $this->option(SlideMenuOption::Items);
  }

  /**
   * Sets attributes for menu items.
   *
   * @param array<string, mixed> $attributes
   *   The attributes to set.
   */
  public function setItemAttributes(array $attributes): void {
    $this->option(SlideMenuOption::ItemAttributes)->merge(new Attribute($attributes));
  }

  /**
   * Gets the item attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The item attributes.
   */
  public function getItemAttributes(): Attribute {
    return $this->option(SlideMenuOption::ItemAttributes);
  }

  /**
   * Sets attributes for menu links.
   *
   * @param array<string, mixed> $attributes
   *   The attributes to set.
   */
  public function setLinkAttributes(array $attributes): void {
    $this->option(SlideMenuOption::LinkAttributes)->merge(new Attribute($attributes));
  }

  /**
   * Gets the link attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The link attributes.
   */
  public function getLinkAttributes(): Attribute {
    return $this->option(SlideMenuOption::LinkAttributes);
  }

  /**
   * Sets the icon to use for menu items with children.
   *
   * @param string $icon
   *   The icon name.
   */
  public function setChildIcon(string $icon): void {
    $this->setOption(SlideMenuOption::ChildIcon, $icon);
  }

  /**
   * Gets the child icon.
   *
   * @return string
   *   The child icon.
   */
  public function getChildIcon(): string {
    return $this->option(SlideMenuOption::ChildIcon);
  }

  /**
   * Sets attributes for child icons.
   *
   * @param array<string, mixed> $attributes
   *   The attributes to set.
   */
  public function setChildIconAttributes(array $attributes): void {
    $this->option(SlideMenuOption::ChildIconAttributes)->merge(new Attribute($attributes));
  }

  /**
   * Gets the child icon attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The child icon attributes.
   */
  public function getChildIconAttributes(): Attribute {
    return $this->option(SlideMenuOption::ChildIconAttributes);
  }

  /**
   * Sets whether back links should be shown.
   *
   * @param bool $status
   *   TRUE to show back links, FALSE to hide them.
   */
  public function setBackStatus(bool $status): void {
    $this->setOption(SlideMenuOption::BackStatus, $status);
  }

  /**
   * Gets whether back links are shown.
   *
   * @return bool
   *   TRUE if back links are shown, FALSE otherwise.
   */
  public function getBackStatus(): bool {
    return $this->option(SlideMenuOption::BackStatus);
  }

  /**
   * Sets the icon to use for back links.
   *
   * @param string $icon
   *   The icon name.
   */
  public function setBackIcon(string $icon): void {
    $this->setOption(SlideMenuOption::BackIcon, $icon);
  }

  /**
   * Gets the back icon.
   *
   * @return string
   *   The back icon.
   */
  public function getBackIcon(): string {
    return $this->option(SlideMenuOption::BackIcon);
  }

  /**
   * Sets attributes for back links.
   *
   * @param array<string, mixed> $attributes
   *   The attributes to set.
   */
  public function setBackAttributes(array $attributes): void {
    $this->option(SlideMenuOption::BackAttributes)->merge(new Attribute($attributes));
  }

  /**
   * Gets the back link attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The back link attributes.
   */
  public function getBackAttributes(): Attribute {
    return $this->option(SlideMenuOption::BackAttributes);
  }

  /**
   * Sets attributes for back icons.
   *
   * @param array<string, mixed> $attributes
   *   The attributes to set.
   */
  public function setBackIconAttributes(array $attributes): void {
    $this->option(SlideMenuOption::BackIconAttributes)->merge(new Attribute($attributes));
  }

  /**
   * Gets the back icon attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The back icon attributes.
   */
  public function getBackIconAttributes(): Attribute {
    return $this->option(SlideMenuOption::BackIconAttributes);
  }

  /**
   * Sets the label for back links.
   *
   * @param string $label
   *   The label to use.
   */
  public function setBackLabel(string $label): void {
    $this->setOption(SlideMenuOption::BackLabel, $label);
  }

  /**
   * Gets the back link label.
   *
   * @return string
   *   The back link label.
   */
  public function getBackLabel(): string {
    return $this->option(SlideMenuOption::BackLabel);
  }

  /**
   * Sets whether "view all" links should be shown.
   *
   * @param bool $status
   *   TRUE to show "view all" links, FALSE to hide them.
   */
  public function setAllStatus(bool $status): void {
    $this->setOption(SlideMenuOption::AllStatus, $status);
  }

  /**
   * Gets whether "view all" links are shown.
   *
   * @return bool
   *   TRUE if "view all" links are shown, FALSE otherwise.
   */
  public function getAllStatus(): bool {
    return $this->option(SlideMenuOption::AllStatus);
  }

  /**
   * Sets the prefix text for "view all" links.
   *
   * @param string $prefix
   *   The prefix text.
   */
  public function setAllPrefix(string $prefix): void {
    $this->setOption(SlideMenuOption::AllPrefix, $prefix);
  }

  /**
   * Gets the "view all" prefix text.
   *
   * @return string
   *   The "view all" prefix text.
   */
  public function getAllPrefix(): string {
    return $this->option(SlideMenuOption::AllPrefix);
  }

  /**
   * Sets the suffix text for "view all" links.
   *
   * @param string $suffix
   *   The suffix text.
   */
  public function setAllSuffix(string $suffix): void {
    $this->setOption(SlideMenuOption::AllSuffix, $suffix);
  }

  /**
   * Gets the "view all" suffix text.
   *
   * @return string
   *   The "view all" suffix text.
   */
  public function getAllSuffix(): string {
    return $this->option(SlideMenuOption::AllSuffix);
  }

  /**
   * Builds render arrays for a collection of menu items.
   *
   * @param array $items
   *   The menu items to build.
   * @param int $depth
   *   The depth of the items, starting at 1 for the top level.
   *
   * @return array
   *   A render array representing the items.
   */
  protected function buildItems(array $items, int $depth = 1): array {
    $build = [
      '#theme' => 'slide_list',
      '#items' => [],
    ];

    foreach ($items as $item) {
      $build['#items'][] = $this->buildItem($item, $depth);
    }

    return $build;
  }

  /**
   * Builds a render array for a single menu item.
   *
   * @param array $item
   *   The menu item to build.
   * @param int $depth
   *   The depth of the item, starting at 1 for the top level.
   *
   * @return array
   *   A render array representing the item.
   */
  protected function buildItem(array $item, int $depth = 1): array {
    $build = [
      '#wrapper_attributes' => $item['item_attributes'] ?? $this->getItemAttributes()->toArray(),
    ];

    // A content-only row renders a render array in place of a link — e.g. a
    // neo_alchemist_menu region item's component tree. The wrapper must be a
    // fully-specified render array (#type) or template_preprocess_slide_list
    // mistakes the raw render array for a nested list and wraps it in a
    // hidden submenu <ul>. Attributes for the wrapper may be supplied via
    // content_attributes (the li itself takes item_attributes as usual).
    if (!empty($item['content'])) {
      $build['content'] = [
        '#type' => 'container',
        '#attributes' => $item['content_attributes'] ?? [],
        'content' => $item['content'],
      ];
      return $build;
    }

    $linkAttributes = $item['link_attributes'] ?? $this->getLinkAttributes()->toArray();

    // Handle items with children.
    $expanded = FALSE;
    if (!empty($item['children'])) {
      $expanded = $this->getExpandDepth() > 0 && $depth >= $this->getExpandDepth();
      $build['children'] = $this->buildItems($item['children'], $depth + 1);

      if (empty($build['children']['#items'])) {
        $expanded = FALSE;
      }
      elseif ($expanded) {
        // Render the children expanded within the current panel — the item
        // becomes a group heading followed by its children as an inline
        // list — instead of as a nested slide level. The inline class opts
        // the list out of the slide mechanics (CSS panel positioning, JS
        // forward navigation, content wrapping).
        $build['#wrapper_attributes']['class'][] = 'neo-slide-menu--group';
        $linkAttributes['class'][] = 'neo-slide-menu--group-heading';
        $linkAttributes['class'][] = 'font-bold';
        $build['children']['#attributes']['class'][] = 'neo-slide-menu--inline';
      }
      else {
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
      '#template' => '<span>{{ label }}</span>{% if suffix %} <span>{{ suffix }}</span>{% endif %}',
      '#context' => [
        'label' => $item['title'],
        'suffix' => $suffix ?? '',
      ],
    ];

    // A non-linking url (<nolink>, <none>, <button>) on an item with children
    // renders as a real button — core renders such urls as a plain <span>,
    // which is neither focusable nor clickable, making the submenu
    // unreachable. Expanded groups keep the plain rendering: their children
    // are already visible, so the heading triggers nothing.
    if (!empty($item['url']) && !$expanded && !empty($build['children']['#items']) && $this->isNonLinkingUrl($item['url'])) {
      unset($item['url']);
    }

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
      $linkAttributes['class'][] = 'cursor-pointer';
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

    // Children were assigned before the link; re-append them so they render
    // after it. Slide levels are position:absolute so order never showed,
    // but inline (expanded) lists render in flow below their heading.
    if (isset($build['children'])) {
      $children = $build['children'];
      unset($build['children']);
      $build['children'] = $children;
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
    $backAttributes->addClass('neo-slide-menu--backlink');

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
    if (empty($item['url']) || $this->isNonLinkingUrl($item['url'])) {
      // A non-linking url has no destination to "view all" of.
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
   * Sets the depth at which children render inline.
   *
   * @param int $depth
   *   The depth, starting at 1 for the top level; 0 disables inline
   *   expansion (children always open a new slide level).
   */
  public function setExpandDepth(int $depth): void {
    // The clamp stays on the write side of the forwarder, so a negative depth
    // reaching the option array reads back as 0 exactly as it always has.
    $this->setOption(SlideMenuOption::ExpandDepth, max(0, $depth));
  }

  /**
   * Gets the depth at which children render inline.
   *
   * @return int
   *   The depth; 0 when inline expansion is disabled.
   */
  public function getExpandDepth(): int {
    return $this->option(SlideMenuOption::ExpandDepth);
  }

  /**
   * Checks whether a url is a non-linking route.
   *
   * @param mixed $url
   *   The value stored as the item url.
   *
   * @return bool
   *   TRUE for <nolink>, <none> and <button> routes, which core's link
   *   generator renders as a plain <span>.
   */
  protected function isNonLinkingUrl($url): bool {
    return $url instanceof Url
      && $url->isRouted()
      && in_array($url->getRouteName(), ['<nolink>', '<none>', '<button>'], TRUE);
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
