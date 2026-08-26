<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Menu\InaccessibleMenuLink;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo\Element\SlideMenu as SlideMenuElement;
use Drupal\neo_icon\IconElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the slide menu element's menu tree walk.
 *
 * `Element\SlideMenu` is the half of the slide menu seam that turns real
 * Drupal menus into slide menu items, and it is where this module's one
 * cacheability fix — `SlideMenu: respect link access and bubble tree
 * cacheability` — actually landed. `Drupal\neo\SlideMenu`, the value object
 * the unit tests cover, never sees a menu tree; everything below happens
 * before it is constructed.
 *
 * `preRenderSlideMenu()` loads each named menu with the current route's
 * parameters, drops the expanded-parents restriction, runs core's access-check
 * and sort manipulators, and walks the result:
 *
 * - It gathers the access result and the link of **every** element, including
 *   the ones it is about to skip. Core's `checkAccess()` manipulator
 *   deliberately keeps a forbidden top-level link in the tree so exactly this
 *   can happen; without it the menu render-caches with no user variance and the
 *   first request to build it fixes the markup for everyone.
 * - It then skips any element whose access result is not allowed. Core has
 *   already swapped the link for an `InaccessibleMenuLink` whose title is the
 *   literal string "Inaccessible", so rendering one leaks a placeholder row.
 * - It adds `config:system.menu.{id}` per menu id and **merges** the collected
 *   cacheability with what the element already carries before applying it —
 *   `applyTo()` alone replaces `#cache` outright and drops those tags.
 * - It recurses into the subtree of an element with children, and lets
 *   `hook_neo_slide_menu_item_alter()` enrich an item or drop it by setting it
 *   to NULL. `neo_alchemist_menu` uses the first to swap a region item for a
 *   rendered component tree.
 *
 * The tree is real. `neo_test` ships `neo_test.open` with the accessible child
 * `neo_test.child`, and `neo_test.gated` on a permission-gated route, all three
 * in `main`, so the forbidden element is produced by core's own access checking
 * rather than simulated. Its slide-menu alter hook is switchable from state, so
 * both the enrich and the veto outcomes are this test's to choose.
 *
 * Assertions are against the pre-rendered element array and the cacheability it
 * carries, never markup.
 *
 * **Characterised, not repaired.** Two answers pinned here are quirks rather
 * than intentions:
 *
 * 1. **`#back_icon` is declared on the element and never forwarded.** The
 *    options array `preRenderSlideMenu()` builds names eleven of the value
 *    object's twelve setters and omits `back_icon`, so setting `#back_icon` on
 *    a `slide_menu` element does nothing at all. It goes unnoticed because the
 *    element's declared default and the value object's own default are the same
 *    string, `chevron-left`. Pinned in
 *    testPassesEveryDeclaredElementOptionThroughToTheSlideMenuItBuilds, and
 *    reported for the backlog.
 * 2. **`#title` and `#attributes` are inert.** Both are declared in
 *    `getInfo()`, neither is read by either pre-render callback, and no theme
 *    hook is registered for `slide_menu` itself — `neo_theme()` registers
 *    only `slide_list`, which the value object supplies. Both are pinned as
 *    inert in the same test.
 */
#[Group('neo')]
final class SlideMenuElementTreeWalkTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `KernelTestBase` installs exactly what
   * is named and does not resolve the info file's dependency closure, so
   * `neo_build`, `neo_color` and `neo_icon` stay out — their classes autoload
   * and only a service lookup would force one in, and nothing here renders an
   * icon.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'neo',
    'neo_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // `user`'s roles are what the permission-gated route resolves against, and
    // `system`'s menu config entities are the ones the walk tags.
    $this->installConfig(['system', 'user']);
    // Discovery of the fixture's *.links.menu.yml into the menu tree storage.
    \Drupal::service('plugin.manager.menu.link')->rebuild();
  }

  /**
   * Skips an element whose access result is not allowed.
   *
   * Covers: "it skips a link whose access result is not allowed rather than
   * rendering the inaccessible placeholder".
   *
   * The first three assertions establish that the tree really does carry a
   * forbidden element at the point the walk sees it, and that core has
   * already replaced its link with the placeholder — which is what makes the
   * skip load bearing rather than cosmetic. `neo_test.gated` is the only
   * inaccessible link in `main`, so a single item is the skip.
   */
  public function testSkipsLinksWhoseAccessResultIsNotAllowed(): void {
    $tree = $this->loadTransformedTree('main');
    $gated = $tree[$this->treeKey($tree, 'neo_test.gated')];
    $this->assertFalse($gated->access->isAllowed());
    $this->assertInstanceOf(InaccessibleMenuLink::class, $gated->link);
    $this->assertSame('Inaccessible', (string) $gated->link->getTitle());

    $built = SlideMenuElement::preRenderSlideMenu($this->element(['#menu_ids' => ['main']]));
    $titles = array_map(
      static fn (array $item): string => (string) $item['title'],
      $built['#items']
    );
    $this->assertSame(['Neo test open'], array_values($titles));
  }

  /**
   * Collects the cacheability of an element it is about to skip.
   *
   * Covers: "it collects the access and link cacheability of an inaccessible
   * element anyway".
   *
   * The walk is driven over a one-element tree holding nothing but the real,
   * manipulator-produced forbidden element, so anything the collector comes
   * back with can only have come from that element. `user.permissions` is what
   * core's `_permission` access check and `menuLinkCheckAccess()`'s
   * `cachePerPermissions()` put on the forbidden result; it is present even
   * though the element contributes no item.
   *
   * The max age is the other half of the claim: `addCacheableDependency()`
   * drops max age to 0 for anything that is not a cacheable dependency, and an
   * `InaccessibleMenuLink` delegates its cacheability to the link it wraps, so
   * a permanent answer says the link was added and was cacheable.
   */
  public function testCollectsTheAccessAndLinkCacheabilityOfAnInaccessibleElementAnyway(): void {
    $tree = $this->loadTransformedTree('main');
    $key = $this->treeKey($tree, 'neo_test.gated');

    $cacheability = new CacheableMetadata();
    $items = $this->walk([$key => $tree[$key]], $cacheability);

    $this->assertSame([], $items);
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
    $this->assertSame([], $cacheability->getCacheTags());
  }

  /**
   * Recurses into a subtree and builds an item per accessible child.
   *
   * Covers: "it recurses into a subtree and builds an item for each accessible
   * child".
   *
   * An item is `title` plus `url`, with `children` added only when the element
   * reports having them — the leaf carries no `children` key at all, which is
   * what the value object's `!empty($item['children'])` branch reads.
   */
  public function testRecursesIntoSubtreesAndBuildsAnItemForEachAccessibleChild(): void {
    $built = SlideMenuElement::preRenderSlideMenu($this->element(['#menu_ids' => ['main']]));

    $item = reset($built['#items']);
    $this->assertSame('Neo test open', (string) $item['title']);
    $this->assertInstanceOf(Url::class, $item['url']);
    $this->assertSame('neo_test.open', $item['url']->getRouteName());

    $this->assertArrayHasKey('children', $item);
    $this->assertCount(1, $item['children']);
    $child = reset($item['children']);
    $this->assertSame('Neo test child', (string) $child['title']);
    $this->assertInstanceOf(Url::class, $child['url']);
    $this->assertSame('neo_test.child', $child['url']->getRouteName());
    $this->assertArrayNotHasKey('children', $child);
  }

  /**
   * Tags per menu, and keeps those tags through the merge.
   *
   * Covers: "it adds a config cache tag for every named menu and keeps those
   * tags after the collected cacheability is applied".
   *
   * This is the assertion the cacheability fix exists for. The element is given
   * cache metadata of its own before the pre-render runs, so the final array
   * has to show three sources at once: the tags the walk adds per menu id, the
   * metadata the element already carried, and the contexts collected from the
   * tree. `applyTo()` on its own replaces `#cache` outright and loses the first
   * two.
   *
   * `footer` is named alongside `main` because it is empty in this install:
   * its tag proves the tag is added per menu id rather than per link found.
   */
  public function testAddsConfigCacheTagForEveryNamedMenuAndKeepsThemAfterTheMerge(): void {
    $element = $this->element(['#menu_ids' => ['main', 'footer']]);
    $element['#cache']['tags'] = ['neo_test:already_carried'];
    $element['#cache']['contexts'] = ['theme'];

    $built = SlideMenuElement::preRenderSlideMenu($element);

    $this->assertContains('config:system.menu.main', $built['#cache']['tags']);
    $this->assertContains('config:system.menu.footer', $built['#cache']['tags']);
    $this->assertContains('neo_test:already_carried', $built['#cache']['tags']);
    $this->assertContains('theme', $built['#cache']['contexts']);
    $this->assertContains('user.permissions', $built['#cache']['contexts']);
  }

  /**
   * Lets the alter hook enrich an item and veto one.
   *
   * Covers: "it lets the alter hook enrich an item and drop one by setting it
   * to null".
   *
   * Both outcomes matter to a caller: `neo_alchemist_menu` uses the enrich half
   * to swap a region item for its rendered component tree, and the veto half is
   * the only way a module can remove a link the tree walk would otherwise
   * build. The veto is exercised twice — once on a child, where the parent
   * has to survive with an emptied `children` array, and once at the top
   * level, where the item never reaches `#items` at all.
   *
   * `neo_test`'s implementation is inert until switched on from state, and its
   * target key narrows it to one menu link plugin id.
   */
  public function testLetsTheAlterHookEnrichAnItemAndDropOneBySettingItToNull(): void {
    $state = \Drupal::state();

    // Enrich: the hook appends an `after` render array to the targeted item
    // and leaves every other item as the walk built it.
    $state->set('neo_test.slide_menu_item_alter', 'after');
    $state->set('neo_test.slide_menu_item_alter_target', 'neo_test.child');
    $built = SlideMenuElement::preRenderSlideMenu($this->element(['#menu_ids' => ['main']]));
    $parent = reset($built['#items']);
    $this->assertArrayNotHasKey('after', $parent);
    $child = reset($parent['children']);
    $this->assertArrayHasKey('after', $child);
    $this->assertSame(
      '<span class="neo-test-after">neo_test.child</span>',
      $child['after']['#markup']
    );

    // Veto inside a subtree: the child is dropped and its parent survives with
    // an empty children array.
    $state->set('neo_test.slide_menu_item_alter', 'veto');
    $built = SlideMenuElement::preRenderSlideMenu($this->element(['#menu_ids' => ['main']]));
    $parent = reset($built['#items']);
    $this->assertSame('Neo test open', (string) $parent['title']);
    $this->assertSame([], $parent['children']);

    // Veto at the top level: the item never reaches the menu at all.
    $state->set('neo_test.slide_menu_item_alter_target', 'neo_test.open');
    $built = SlideMenuElement::preRenderSlideMenu($this->element(['#menu_ids' => ['main']]));
    $this->assertSame([], $built['#items']);
  }

  /**
   * Uses the given items as they stand when no menu is named.
   *
   * Covers: "it leaves the given items alone and loads no menu when no menu ids
   * are named".
   *
   * This is the element's pass-through mode, and the one every caller that
   * builds its own items uses. The menu link tree service is replaced with a
   * mock that expects never to be called, because "no menu is loaded" is a
   * stronger claim than "no menu items appeared": the whole tag-and-collect
   * block sits behind the same guard, which is why `#cache` is absent too.
   */
  public function testLeavesTheGivenItemsAloneAndLoadsNoMenuWhenNoMenuIdsAreNamed(): void {
    $menuLinkTree = $this->createMock(MenuLinkTreeInterface::class);
    $menuLinkTree->expects($this->never())->method('getCurrentRouteMenuTreeParameters');
    $menuLinkTree->expects($this->never())->method('load');
    $menuLinkTree->expects($this->never())->method('transform');
    $this->container->set('menu.link_tree', $menuLinkTree);

    $items = [
      'given' => [
        'title' => 'Given',
        'url' => Url::fromRoute('<front>'),
      ],
    ];
    $built = SlideMenuElement::preRenderSlideMenu($this->element([
      '#items' => $items,
      '#menu_ids' => [],
    ]));

    $this->assertSame($items, $built['#items']);
    $this->assertArrayNotHasKey('#cache', $built);
    $this->assertArrayHasKey('slide', $built);
  }

  /**
   * Forwards the element's options to the value object it builds.
   *
   * Covers: "it passes every declared element option through to the slide menu
   * it builds".
   *
   * Every option is set to a value nothing else could produce and then read
   * back off `$element['slide']`, the render array the value object returned.
   * The items nest three deep so that one item is reached at each of the two
   * treatments an expand depth of 2 produces: the top level slides, and so
   * builds the back row, the view-all row and the child indicator icon, while
   * the second level groups.
   *
   * Two answers here are characterised rather than endorsed:
   *
   * - **`#back_icon` never reaches the value object.** The options array
   *   omits it, so the back icon is `chevron-left` — the value object's own
   *   default — no matter what the element declares. It is invisible in
   *   practice only because the element's default is the same string.
   *   Reported for the backlog.
   * - **`#title` and `#attributes` are inert.** Neither pre-render callback
   *   reads either one and no theme hook is registered for `slide_menu`, so the
   *   nav keeps the class the value object gives it and the title sits on the
   *   element unread.
   */
  public function testPassesEveryDeclaredElementOptionThroughToTheSlideMenuItBuilds(): void {
    $built = SlideMenuElement::preRenderSlideMenu($this->element([
      '#menu_ids' => [],
      '#title' => 'Pinned title',
      '#attributes' => ['class' => ['pinned-nav']],
      '#items' => [
        [
          'title' => 'Parent',
          'url' => Url::fromRoute('<front>'),
          'children' => [
            [
              'title' => 'Child',
              'url' => Url::fromRoute('<front>'),
              'children' => [
                ['title' => 'Grandchild', 'url' => Url::fromRoute('<front>')],
              ],
            ],
          ],
        ],
      ],
      '#item_attributes' => ['class' => ['pinned-item']],
      '#link_attributes' => ['class' => ['pinned-link']],
      '#child_icon' => 'pinned-child-icon',
      '#child_icon_attributes' => ['class' => ['pinned-child-icon-class']],
      '#back_status' => FALSE,
      '#back_label' => 'Pinned back',
      '#back_icon' => 'pinned-back-icon',
      '#back_attributes' => ['class' => ['pinned-back']],
      '#back_icon_attributes' => ['class' => ['pinned-back-icon-class']],
      '#all_status' => FALSE,
      '#all_prefix' => 'Pinned prefix',
      '#all_suffix' => 'Pinned suffix',
      '#expand_depth' => 2,
    ]));
    $slide = $built['slide'];

    // back_label is the one option that crosses into drupalSettings.
    $this->assertSame(
      'Pinned back',
      $slide['#attached']['drupalSettings']['neo']['slideMenu']['backLabel']
    );

    // item_attributes and link_attributes.
    $parent = $slide['menu']['#items'][0];
    $this->assertSame(['class' => ['pinned-item']], $parent['#wrapper_attributes']);
    $this->assertContains('pinned-link', $parent['link']['#attributes']['class']);

    // child_icon and child_icon_attributes, on the child indicator.
    $childIcon = $parent['link']['#title']['#context']['suffix'];
    $this->assertInstanceOf(IconElement::class, $childIcon);
    $this->assertSame('pinned-child-icon', $this->iconName($childIcon));
    $this->assertSame(
      ['class' => ['pinned-child-icon-class']],
      $childIcon->getIconAttributes()
    );

    // back_status, back_attributes, back_label and back_icon_attributes, read
    // off the back control row. A disabled row is hidden, not omitted.
    $back = $parent['children']['#items']['back'];
    $this->assertContains('sr-only', $back['#wrapper_attributes']['class']);
    $this->assertContains('pinned-back', $back['link']['#attributes']['class']);
    $backTitle = $back['link']['value']['#context']['label']['#context'];
    $this->assertSame('Pinned back', $backTitle['label']);
    $this->assertInstanceOf(IconElement::class, $backTitle['icon']);
    $this->assertSame(
      ['class' => ['pinned-back-icon-class']],
      $backTitle['icon']->getIconAttributes()
    );
    // But back_icon is not forwarded at all — see the method docblock.
    $this->assertSame('chevron-left', $this->iconName($backTitle['icon']));

    // all_status, all_prefix and all_suffix, read off the view-all row.
    $all = $parent['children']['#items']['all'];
    $this->assertContains('sr-only', $all['#wrapper_attributes']['class']);
    $allTitle = $all['link']['#title']['#context']['label']['#context'];
    $this->assertSame('Pinned prefix', $allTitle['prefix']);
    $this->assertSame('Pinned suffix', $allTitle['suffix']);

    // expand_depth: the second level groups instead of sliding.
    $child = $parent['children']['#items'][0];
    $this->assertContains('neo-slide-menu--group', $child['#wrapper_attributes']['class']);
    $this->assertContains('neo-slide-menu--inline', $child['children']['#attributes']['class']);

    // And the two inert element properties.
    $this->assertSame(['neo-slide-menu'], $slide['#attributes']['class']);
    $this->assertSame('Pinned title', $built['#title']);
  }

  /**
   * Builds a slide_menu element from its own declared defaults.
   *
   * @param array $overrides
   *   Element properties to set, in their `#`-prefixed form.
   *
   * @return array
   *   The element, ready for the pre-render callback.
   */
  private function element(array $overrides = []): array {
    $info = \Drupal::service('plugin.manager.element_info')->getInfo('slide_menu');
    return $overrides + $info;
  }

  /**
   * Loads a menu the way the element does, with the same two manipulators.
   *
   * @param string $menu_id
   *   The menu name.
   *
   * @return \Drupal\Core\Menu\MenuLinkTreeElement[]
   *   The loaded, access-checked and sorted tree.
   */
  private function loadTransformedTree(string $menu_id): array {
    $menuLinkTree = \Drupal::menuTree();
    $parameters = $menuLinkTree->getCurrentRouteMenuTreeParameters($menu_id);
    $parameters->expandedParents = [];
    return $menuLinkTree->transform($menuLinkTree->load($menu_id, $parameters), [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);
  }

  /**
   * Finds the key of one tree element by its menu link plugin id.
   *
   * The sort manipulator re-keys the tree by an opaque sort index, so a plugin
   * id is the only stable handle a test has on one element.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   The loaded tree.
   * @param string $plugin_id
   *   The menu link plugin id to look for.
   *
   * @return string
   *   The key that element sits at.
   */
  private function treeKey(array $tree, string $plugin_id): string {
    foreach ($tree as $key => $element) {
      if ($element->link->getPluginId() === $plugin_id) {
        return (string) $key;
      }
    }
    $this->fail('No tree element for ' . $plugin_id . '.');
  }

  /**
   * Runs the element's protected tree walk over a chosen tree.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   The tree to walk.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   (optional) The collector to hand the walk.
   *
   * @return array
   *   The slide menu items the walk built.
   */
  private function walk(array $tree, ?CacheableMetadata $cacheability = NULL): array {
    return (new \ReflectionMethod(SlideMenuElement::class, 'generateItemFromMenuTree'))
      ->invoke(NULL, $tree, $cacheability);
  }

  /**
   * Reads the icon id an icon element was built with.
   *
   * `IconElement::getIcon()` resolves the icon through the icon repository
   * service, and `neo_icon` is deliberately not installed, so the id is read
   * off the object instead.
   *
   * @param \Drupal\neo_icon\IconElement $icon
   *   The icon element.
   *
   * @return string
   *   The icon id it was constructed with.
   */
  private function iconName(IconElement $icon): string {
    return (string) (new \ReflectionProperty(IconElement::class, 'icon'))->getValue($icon);
  }

}
