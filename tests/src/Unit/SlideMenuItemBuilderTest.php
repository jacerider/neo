<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\neo\SlideMenu;
use Drupal\neo_icon\IconElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the slide menu value object's item builder.
 *
 * `SlideMenu::buildItems()` and `SlideMenu::buildItem()` are the array-in,
 * array-out half of the slide menu seam: they turn a tree of slide menu items
 * into the render array every mobile menu on every site is built from.
 * `Element\SlideMenu` walks a real menu tree and hands the result here,
 * `neo_alchemist_menu` alters the items on the way, and `neo.api.php`
 * publishes the item contract. Nothing had ever asserted the output.
 *
 * **The harness is a stub translation service and nothing else.** The object
 * reaches `$this->t()` and `IconTrait::icon()`, and an `IconElement` touches
 * no service until it is rendered, so no container is needed.
 * `Url::fromRoute()` is likewise container-free — it only stores a route name
 * and parameters, and `isRouted()`/`getRouteName()` read them back.
 *
 * Assertions are against the render array, not markup: the array is the
 * contract `slide_list` and its preprocessor read, and the templates are not
 * this plan's.
 *
 * The two builders are `protected`, so they are reached through reflection
 * rather than through `toRenderable()`. That keeps a failure pointing at the
 * builder instead of at the wrapper, and it is the only way to drive
 * `buildItem()` at a chosen depth.
 *
 * Characterised, not repaired. Four answers pinned here are quirks rather
 * than intentions, each named in the docblock of the test that pins it:
 *
 * 1. **A row's `item_attributes` and `link_attributes` replace the menu's
 *    rather than merging with them.** `$item['item_attributes'] ?? …` is a
 *    null-coalesce, not a merge, so a row that wants one extra class silently
 *    loses every wrapper attribute the menu set.
 * 2. **A content row silently drops `title`, `url`, `children` and `after`.**
 *    The early return fires before any of the four is read, so an item
 *    carrying both `content` and a link renders only the content, with no
 *    diagnostic.
 * 3. **The "children built to nothing" guard is unreachable from the public
 *    surface.** `buildItems()` appends exactly one built item per input row and
 *    the branch is only entered when `!empty($item['children'])`, so
 *    `$build['children']['#items']` can never be empty there. Both the
 *    `$expanded = FALSE` reset and the third clause of the non-linking-url
 *    check are dead today; `Element\SlideMenu` already produces an empty
 *    `children` array when every child is inaccessible, which `!empty()`
 *    rejects one line earlier. It is pinned through a subclass, and named in
 *    testTurnsSpecialRouteTokensIntoButtonsOnlyWithSurvivingChildren.
 * 4. **`data-parent-title` takes the raw title.** It is assigned before the
 *    title is wrapped in the inline template, so a caller that supplies a
 *    render array as a title — a shape `buildItemBack()` and `buildItemAll()`
 *    both anticipate — would put an array into an HTML attribute.
 */
#[Group('neo')]
final class SlideMenuItemBuilderTest extends UnitTestCase {

  /**
   * The link attributes a menu applies when a row overrides nothing.
   */
  private const DEFAULT_LINK_CLASS = 'flex items-center justify-between';

  /**
   * The template every built title is wrapped in.
   */
  private const TITLE_TEMPLATE = '<span>{{ label }}</span>{% if suffix %} <span>{{ suffix }}</span>{% endif %}';

  /**
   * Builds one row per item under the list theme, with wrapper attributes.
   *
   * Covers: "it builds one row per item under the list theme and applies the
   * menu's wrapper attributes unless the row overrides them".
   *
   * `slide_list` is the theme hook `neo_theme()` registers and the one whose
   * preprocessor reads `#items`; naming it here is what stops a rename from
   * silently detaching the builder from its template.
   *
   * The third row pins quirk 1 from the class docblock: `item_attributes` is a
   * null-coalesce, so the row's own bag **replaces** the menu's rather than
   * merging into it, and `data-level` is gone from that row entirely.
   */
  public function testBuildsOneRowPerItemUnderTheListThemeWithWrapperAttributes(): void {
    $menu = $this->menu([
      'item_attributes' => ['class' => ['menu-row'], 'data-level' => '1'],
    ]);

    $build = $this->buildItems($menu, [
      ['title' => 'One', 'url' => Url::fromRoute('one')],
      ['title' => 'Two', 'url' => Url::fromRoute('two')],
      [
        'title' => 'Three',
        'url' => Url::fromRoute('three'),
        'item_attributes' => ['class' => ['own-row']],
      ],
    ]);

    $this->assertSame(['#theme', '#items'], array_keys($build));
    $this->assertSame('slide_list', $build['#theme']);

    // One built row per input row, in order, under sequential keys.
    $this->assertSame([0, 1, 2], array_keys($build['#items']));
    $this->assertSame('One', $build['#items'][0]['link']['#title']['#context']['label']);
    $this->assertSame('Two', $build['#items'][1]['link']['#title']['#context']['label']);
    $this->assertSame('Three', $build['#items'][2]['link']['#title']['#context']['label']);

    // The menu's wrapper attributes reach every row that overrides nothing.
    $expected = ['class' => ['menu-row'], 'data-level' => '1'];
    $this->assertSame($expected, $build['#items'][0]['#wrapper_attributes']);
    $this->assertSame($expected, $build['#items'][1]['#wrapper_attributes']);

    // Quirk 1: the row's own bag replaces the menu's outright.
    $this->assertSame(['class' => ['own-row']], $build['#items'][2]['#wrapper_attributes']);

    // A menu that sets no item attributes gives every row an empty bag.
    $plain = $this->menu();
    $plainBuild = $this->buildItems($plain, [
      ['title' => 'One', 'url' => Url::fromRoute('one')],
    ]);
    $this->assertSame([], $plainBuild['#items'][0]['#wrapper_attributes']);

    // An empty collection is still a list, with no rows.
    $this->assertSame(
      ['#theme' => 'slide_list', '#items' => []],
      $this->buildItems($plain, [])
    );
  }

  /**
   * Returns a content row as a typed container with no link.
   *
   * Covers: "it returns a content row as a typed container with its own
   * content attributes and no link".
   *
   * The `#type` is the whole point of the early return: without it
   * `template_preprocess_slide_list()` mistakes the raw render array for a
   * nested list and wraps it in a hidden submenu `<ul>`. This is the shape
   * `neo_alchemist_menu` produces for a region item — content, content
   * attributes and item attributes, and no title at all — which is why the
   * first case here supplies no title: the early return fires before the
   * title is read.
   *
   * Pins quirk 2 from the class docblock: the `title`, `url`, `children` and
   * `after` handed to the first row below are all silently dropped rather than
   * rendered or reported.
   */
  public function testReturnsContentRowsAsTypedContainersWithNoLink(): void {
    $menu = $this->menu(['item_attributes' => ['class' => ['menu-row']]]);
    $content = ['#markup' => 'a component tree'];

    // A row carrying a title, a url, children and an after row alongside its
    // content still returns at the content — quirk 2, all four dropped.
    $loaded = $this->buildItem($menu, [
      'title' => 'Products',
      'content' => $content,
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 1]),
      'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
      'after' => ['#markup' => 'badge'],
    ]);
    $this->assertSame(['#wrapper_attributes', 'content'], array_keys($loaded));
    $this->assertArrayNotHasKey('link', $loaded);
    $this->assertArrayNotHasKey('children', $loaded);
    $this->assertArrayNotHasKey('after', $loaded);

    // The shape neo_alchemist_menu produces for a region item: content,
    // content attributes and item attributes, and no title at all. The early
    // return fires before the title is read, which is what makes that legal.
    $build = $this->buildItem($menu, [
      'content' => $content,
      'content_attributes' => [
        'class' => ['neo-slide-menu--region'],
        'data-region' => 'main',
      ],
      'item_attributes' => ['class' => ['neo-slide-menu--region-item']],
    ]);

    $this->assertSame(['#wrapper_attributes', 'content'], array_keys($build));
    $this->assertArrayNotHasKey('link', $build);

    $this->assertArrayHasKey('#type', $build['content']);
    $this->assertSame('container', $build['content']['#type']);
    $this->assertSame(
      ['class' => ['neo-slide-menu--region'], 'data-region' => 'main'],
      $build['content']['#attributes']
    );
    $this->assertSame($content, $build['content']['content']);

    // The container's attributes are the row's content attributes; the
    // wrapper's are its item attributes. The two bags never cross.
    $this->assertSame(
      ['class' => ['neo-slide-menu--region-item']],
      $build['#wrapper_attributes']
    );

    // Without content attributes the container carries an empty bag, and the
    // wrapper still falls back to the menu's own item attributes.
    $bare = $this->buildItem($menu, ['content' => $content]);
    $this->assertSame([], $bare['content']['#attributes']);
    $this->assertSame(['class' => ['menu-row']], $bare['#wrapper_attributes']);
  }

  /**
   * Builds a link for a row with a url and a button for a row without one.
   *
   * Covers: "it builds a row with a url as a link and a row without one as a
   * button carrying the pointer class".
   *
   * The title is never the bare label — it is always an inline template
   * wrapping the label in a span, with a second slot the child indicator icon
   * fills. The button branch adds `cursor-pointer` and unions in
   * `type="button"`, so a `<button>` inside a menu list is not mistaken for a
   * submit control.
   *
   * The second case pins quirk 1 again on the link side: `link_attributes`
   * replaces the menu's bag rather than merging into it, so the row loses the
   * layout classes the menu set.
   */
  public function testBuildsLinksForRowsWithUrlsAndButtonsForRowsWithout(): void {
    $menu = $this->menu();
    $url = Url::fromRoute('entity.node.canonical', ['node' => 12]);

    $link = $this->buildItem($menu, ['title' => 'Products', 'url' => $url]);

    $this->assertSame('link', $link['link']['#type']);
    $this->assertSame($url, $link['link']['#url']);
    $this->assertSame(['class' => [self::DEFAULT_LINK_CLASS]], $link['link']['#attributes']);
    $this->assertSame([
      '#type' => 'inline_template',
      '#template' => self::TITLE_TEMPLATE,
      '#context' => ['label' => 'Products', 'suffix' => ''],
    ], $link['link']['#title']);

    // Quirk 1, link side: the row's bag replaces the menu's.
    $custom = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => $url,
      'link_attributes' => ['class' => ['own-link'], 'data-track' => 'nav'],
    ]);
    $this->assertSame(
      ['class' => ['own-link'], 'data-track' => 'nav'],
      $custom['link']['#attributes']
    );

    $button = $this->buildItem($menu, ['title' => 'Solutions']);

    $this->assertSame('html_tag', $button['link']['#type']);
    $this->assertSame('button', $button['link']['#tag']);
    $this->assertArrayNotHasKey('#url', $button['link']);
    $this->assertSame([
      'class' => [self::DEFAULT_LINK_CLASS, 'cursor-pointer'],
      'type' => 'button',
    ], $button['link']['#attributes']);

    // The label rides in `value`, not `#title`, because it is an html_tag.
    $this->assertSame([
      '#type' => 'inline_template',
      '#template' => self::TITLE_TEMPLATE,
      '#context' => ['label' => 'Solutions', 'suffix' => ''],
    ], $button['link']['value']);
  }

  /**
   * Adds the child classes, both aria attributes and the parent title.
   *
   * Covers: "it adds the child classes, both aria attributes and the
   * parent-title attribute to a row with children".
   *
   * The two aria attributes are string literals, not booleans, because that is
   * what an HTML attribute is; `aria-expanded` starts `'false'` and the browser
   * side flips it.
   *
   * Pins quirk 4 from the class docblock: `data-parent-title` is assigned
   * before the title becomes an inline template, so it is the raw value the
   * caller handed in — a string here, but an array for any caller that supplies
   * a render array as a title.
   */
  public function testAddsChildClassesAriaAttributesAndParentTitle(): void {
    $menu = $this->menu();
    $build = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
      'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
    ]);

    $this->assertSame([
      'class' => [self::DEFAULT_LINK_CLASS, 'neo-slide-menu--children'],
      'aria-expanded' => 'false',
      'aria-haspopup' => 'true',
    ], $build['link']['#attributes']);

    // The child list is a list of its own, named after the parent.
    $this->assertSame('slide_list', $build['children']['#theme']);
    $this->assertArrayHasKey('data-parent-title', $build['children']['#attributes']);
    $this->assertSame('Products', $build['children']['#attributes']['data-parent-title']);
    $this->assertIsString($build['children']['#attributes']['data-parent-title']);

    // The real child survives beside the control rows ticket 06 covers.
    $this->assertSame(
      'Widgets',
      $build['children']['#items'][0]['link']['#title']['#context']['label']
    );

    // A childless row gains none of it.
    $childless = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
    ]);
    $this->assertSame(
      ['class' => [self::DEFAULT_LINK_CLASS]],
      $childless['link']['#attributes']
    );
    $this->assertArrayNotHasKey('children', $childless);
  }

  /**
   * Appends the child icon as the title suffix, or leaves the slot empty.
   *
   * Covers: "it appends the child icon as the title suffix and leaves the
   * suffix empty when no child icon is set".
   *
   * The suffix is an `IconElement`, not markup — it is built here and rendered
   * later, which is what keeps this seam free of a container. The icon is
   * icon-only and its text is the accessible label naming the parent, so the
   * indicator is announced rather than read out as a chevron.
   *
   * When the child icon is emptied the ternary short-circuits: the slot is the
   * empty string and no `IconElement` is constructed at all. The inline
   * template's `{% if suffix %}` then drops the whole second span.
   */
  public function testAppendsChildIconAsTitleSuffixAndLeavesItEmptyWithoutOne(): void {
    $item = [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
      'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
    ];

    $menu = $this->menu(['child_icon_attributes' => ['class' => ['size-4']]]);
    $this->assertSame('chevron-right', $menu->getChildIcon());

    $build = $this->buildItem($menu, $item);
    $suffix = $build['link']['#title']['#context']['suffix'];

    $this->assertInstanceOf(IconElement::class, $suffix);
    $this->assertTrue($suffix->isIconOnly());
    $this->assertSame('View children of Products', (string) $suffix->getText());
    $this->assertSame(['class' => ['size-4']], $suffix->getIconAttributes());

    // The label rides beside it, untouched.
    $this->assertSame('Products', $build['link']['#title']['#context']['label']);
    $this->assertSame(self::TITLE_TEMPLATE, $build['link']['#title']['#template']);

    // An emptied child icon leaves the slot empty and builds no icon.
    $none = $this->menu(['child_icon' => '']);
    $this->assertSame('', $none->getChildIcon());
    $noneBuild = $this->buildItem($none, $item);
    $emptySuffix = $noneBuild['link']['#title']['#context']['suffix'];
    $this->assertNotInstanceOf(IconElement::class, $emptySuffix);
    $this->assertSame('', $emptySuffix);

    // A childless row never reaches the suffix branch at all.
    $childless = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
    ]);
    $this->assertSame('', $childless['link']['#title']['#context']['suffix']);
  }

  /**
   * Turns a special route token into a button only with surviving children.
   *
   * Covers: "it turns a special route token into a button only when the row has
   * children that survived the build".
   *
   * `<nolink>`, `<none>` and `<button>` are the routes core's link generator
   * renders as a plain `<span>`. A span is neither focusable nor clickable, so
   * a parent row carrying one would make its submenu unreachable — the builder
   * drops the url and emits a real button instead. A childless row has nothing
   * to open, so it keeps the span. An external url is not routed at all, and
   * the `isRouted()` guard is what stops the check asking it for a route name
   * it would raise on.
   *
   * The last case pins quirk 3 from the class docblock. `!empty($build
   * ['children']['#items'])` is unreachable from the public surface —
   * `buildItems()` returns one built row per input row, and the branch is only
   * entered when the input children are non-empty. It is driven here through a
   * subclass whose `buildItems()` returns an empty list, which is the only
   * instrument that can say what the guard does.
   */
  public function testTurnsSpecialRouteTokensIntoButtonsOnlyWithSurvivingChildren(): void {
    $menu = $this->menu();
    $children = [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]];

    foreach (['<nolink>', '<none>', '<button>'] as $route) {
      $build = $this->buildItem($menu, [
        'title' => 'Products',
        'url' => Url::fromRoute($route),
        'children' => $children,
      ]);

      $this->assertSame('html_tag', $build['link']['#type'], $route);
      $this->assertSame('button', $build['link']['#tag'], $route);
      $this->assertArrayNotHasKey('#url', $build['link'], $route);
      $this->assertSame([
        'class' => [
          self::DEFAULT_LINK_CLASS,
          'neo-slide-menu--children',
          'cursor-pointer',
        ],
        'aria-expanded' => 'false',
        'aria-haspopup' => 'true',
        'type' => 'button',
      ], $build['link']['#attributes'], $route);
    }

    // An ordinary route with children stays a link.
    $routed = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
      'children' => $children,
    ]);
    $this->assertSame('link', $routed['link']['#type']);

    // An external url is not routed at all, so the guard never asks it for a
    // route name — the row keeps its link instead of raising.
    $external = $this->buildItem($menu, [
      'title' => 'Docs',
      'url' => Url::fromUri('https://example.com/docs'),
      'children' => $children,
    ]);
    $this->assertFalse($external['link']['#url']->isRouted());
    $this->assertSame('link', $external['link']['#type']);

    // A childless special route keeps its link.
    $childless = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('<nolink>'),
    ]);
    $this->assertSame('link', $childless['link']['#type']);
    $this->assertSame('<nolink>', $childless['link']['#url']->getRouteName());

    // Quirk 3: a row whose children built to nothing keeps its link too.
    $empty = $this->menuWithNoBuildableChildren();
    $built = $this->buildItem($empty, [
      'title' => 'Products',
      'url' => Url::fromRoute('<nolink>'),
      'children' => $children,
    ]);
    $this->assertSame('link', $built['link']['#type']);
    $this->assertSame('<nolink>', $built['link']['#url']->getRouteName());
    $this->assertSame([], $built['children']['#items']);
    // The empty list also skips every other child treatment.
    $this->assertSame(
      ['class' => [self::DEFAULT_LINK_CLASS]],
      $built['link']['#attributes']
    );
    $this->assertArrayNotHasKey('#attributes', $built['children']);
    $this->assertSame('', $built['link']['#title']['#context']['suffix']);
  }

  /**
   * Re-appends the children after the link.
   *
   * Covers: "it re-appends the children after the link".
   *
   * `children` is assigned before the link is built, so without the re-append
   * the finished row reads children-then-link. Slide levels are absolutely
   * positioned so the order never showed, but an inline (expanded) list renders
   * in flow below its heading — which is where the wrong order would surface.
   *
   * `after` is the control: it is assigned after the link and before the
   * re-append, so it must sit between the two.
   */
  public function testReappendsChildrenAfterTheLink(): void {
    $menu = $this->menu();
    $children = [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]];
    $url = Url::fromRoute('entity.node.canonical', ['node' => 12]);

    $build = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => $url,
      'after' => ['#markup' => 'badge'],
      'children' => $children,
    ]);
    $this->assertSame(
      ['#wrapper_attributes', 'link', 'after', 'children'],
      array_keys($build)
    );

    // Without an after row the children still land last.
    $noAfter = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => $url,
      'children' => $children,
    ]);
    $this->assertSame(
      ['#wrapper_attributes', 'link', 'children'],
      array_keys($noAfter)
    );

    // The control: a childless row ends at the after row.
    $childless = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => $url,
      'after' => ['#markup' => 'badge'],
    ]);
    $this->assertSame(
      ['#wrapper_attributes', 'link', 'after'],
      array_keys($childless)
    );

    // The move preserves the built list rather than rebuilding it.
    $this->assertSame('slide_list', $build['children']['#theme']);
    $this->assertSame(['#markup' => 'badge'], $build['after']);
  }

  /**
   * Builds a slide menu carrying a stub translation service.
   *
   * @param array $options
   *   Options for the menu, in the snake_case form the option setter takes.
   *
   * @return \Drupal\neo\SlideMenu
   *   The menu, with no items — every test drives the builders directly.
   */
  private function menu(array $options = []): SlideMenu {
    $menu = new SlideMenu([], $options);
    $menu->setStringTranslation($this->getStringTranslationStub());
    return $menu;
  }

  /**
   * Builds a slide menu whose child lists always come back empty.
   *
   * The only instrument that reaches the "children built to nothing" guard —
   * see quirk 3 in the class docblock. Nothing a caller can pass to the real
   * `buildItems()` produces an empty `#items` from a non-empty input.
   *
   * @return \Drupal\neo\SlideMenu
   *   The menu.
   */
  private function menuWithNoBuildableChildren(): SlideMenu {
    $menu = new class([]) extends SlideMenu {

      /**
       * {@inheritdoc}
       */
      protected function buildItems(array $items, int $depth = 1): array {
        return ['#theme' => 'slide_list', '#items' => []];
      }

    };
    $menu->setStringTranslation($this->getStringTranslationStub());
    return $menu;
  }

  /**
   * Invokes the protected item-collection builder.
   *
   * @param \Drupal\neo\SlideMenu $menu
   *   The menu to build through.
   * @param array $items
   *   The items to build.
   * @param int $depth
   *   The depth of the items, starting at 1 for the top level.
   *
   * @return array
   *   The built list render array.
   */
  private function buildItems(SlideMenu $menu, array $items, int $depth = 1): array {
    return (new \ReflectionMethod($menu, 'buildItems'))->invoke($menu, $items, $depth);
  }

  /**
   * Invokes the protected single-item builder.
   *
   * @param \Drupal\neo\SlideMenu $menu
   *   The menu to build through.
   * @param array $item
   *   The item to build.
   * @param int $depth
   *   The depth of the item, starting at 1 for the top level.
   *
   * @return array
   *   The built row render array.
   */
  private function buildItem(SlideMenu $menu, array $item, int $depth = 1): array {
    return (new \ReflectionMethod($menu, 'buildItem'))->invoke($menu, $item, $depth);
  }

}
