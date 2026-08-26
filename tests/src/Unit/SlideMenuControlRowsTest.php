<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\neo\SlideMenu;
use Drupal\neo_icon\IconElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the slide menu's control rows, expand depth and options.
 *
 * The other half of the slide menu value object, beside ticket 05's item
 * builder: the two control rows `buildItem()` injects into every submenu, the
 * **expand depth** mode that turns a sliding level into an inline group, the
 * reflection-dispatched option setter every caller configures the menu
 * through, and `toRenderable()`'s outer wrapper.
 *
 * **The harness is the one ticket 05 established** — a stub translation
 * service and nothing else. The object reaches `$this->t()` and
 * `IconTrait::icon()`, and an `IconElement` touches no service until it is
 * rendered, so no container is needed. `Url::fromRoute()` is likewise
 * container-free.
 *
 * Assertions are against the render array, not markup: the array is the
 * contract `slide_list` and its preprocessor read, and the templates are not
 * this plan's.
 *
 * `buildItem()`, `buildItemBack()` and `buildItemAll()` are `protected`, so
 * they are reached through reflection. That keeps a failure pointing at the
 * builder that produced it, and driving `buildItem()` at a chosen depth is the
 * only way to exercise expand depth at all.
 *
 * Characterised, not repaired. Five answers pinned here are quirks rather than
 * intentions, each named again in the docblock of the test that pins it:
 *
 * 1. **The back row comes above the view-all row, not below it.**
 *    `buildItem()` prepends the view-all row and then prepends the back row on
 *    top of it, so the finished submenu reads back, view-all, then the real
 *    children — the reverse of the order this ticket's own criterion
 *    describes. Pinned as built in
 *    testPrependsBothControlRowsAboveTheRealChildren.
 * 2. **`neo-slide-menu--backlink` lands on the back row twice.** The default
 *    back attributes already carry the class and `buildItemBack()` calls
 *    `addClass()` with it again; core's `addClass()` merges without
 *    deduplicating, so the rendered `class` attribute repeats it.
 * 3. **The two rows carry their accessible label in different types.** The
 *    back row's goes through `Attribute::setAttribute()`, which plain-texts a
 *    Stringable, so it is a `string`; the view-all row's is written straight
 *    into a PHP array and stays a `TranslatableMarkup`. A consumer reading
 *    either one gets a different thing.
 * 4. **An unknown option key is silently ignored.** `applyOptions()` checks
 *    `method_exists()` and moves on, so a typo in an option array raises
 *    nothing and changes nothing — the option surface has no valid key set to
 *    check against, anywhere in the class.
 * 5. **A `0` option reaching a string setter becomes the string `'0'`, which
 *    is falsy.** `child_icon => 0` is stored as `'0'` and then never builds an
 *    icon, because the builder tests the icon name for truthiness.
 *
 * The "children built to nothing" guard is unreachable from the public
 * surface — the quirk ticket 05 recorded third — so the expand-depth half of
 * it is driven here through the same subclass instrument.
 */
#[Group('neo')]
final class SlideMenuControlRowsTest extends UnitTestCase {

  /**
   * The link attributes a menu applies when a row overrides nothing.
   */
  private const DEFAULT_LINK_CLASS = 'flex items-center justify-between';

  /**
   * The template every built title is wrapped in.
   */
  private const TITLE_TEMPLATE = '<span>{{ label }}</span>{% if suffix %} <span>{{ suffix }}</span>{% endif %}';

  /**
   * Prepends both control rows above the real children of a submenu.
   *
   * Covers: "it prepends the view-all row above the back row above the real
   * children of a submenu".
   *
   * Pins quirk 1 from the class docblock, and pins it against the criterion's
   * own wording. `buildItem()` prepends the view-all row and then prepends the
   * back row on top of it; `+` preserves the left operand's order, so the
   * finished submenu reads back, view-all, then the real children — the
   * reverse of the order the criterion describes. Characterised as built.
   *
   * The view-all row takes a clone of the parent's url, so an option set on
   * one cannot reach the other.
   */
  public function testPrependsBothControlRowsAboveTheRealChildren(): void {
    $menu = $this->menu();
    $url = Url::fromRoute('entity.node.canonical', ['node' => 12]);

    $build = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => $url,
      'children' => [
        ['title' => 'Widgets', 'url' => Url::fromRoute('widgets')],
        ['title' => 'Gadgets', 'url' => Url::fromRoute('gadgets')],
      ],
    ]);

    $items = $build['children']['#items'];
    $this->assertSame(['back', 'all', 0, 1], array_keys($items));

    // The real children keep their order and their own labels below both.
    $this->assertSame('Widgets', $items[0]['link']['#title']['#context']['label']);
    $this->assertSame('Gadgets', $items[1]['link']['#title']['#context']['label']);

    // The back row has no url, so it is a button; the view-all row has one.
    $this->assertSame('html_tag', $items['back']['link']['#type']);
    $this->assertSame('button', $items['back']['link']['#tag']);
    $this->assertSame('link', $items['all']['link']['#type']);

    // The view-all row takes a clone of the parent's url, not the instance.
    $this->assertNotSame($url, $items['all']['link']['#url']);
    $this->assertEquals($url, $items['all']['link']['#url']);

    // A childless row gets neither control row, and no child list at all.
    $childless = $this->buildItem($menu, ['title' => 'Products', 'url' => $url]);
    $this->assertArrayNotHasKey('children', $childless);
  }

  /**
   * Labels the back row with the parent title and hides a disabled row.
   *
   * Covers: "it labels the back row with the parent title, prefixes the back
   * icon when one is set, and hides rather than omits a disabled row".
   *
   * The row is itself built through the item builder, from a clone of the
   * menu's back attributes — so the aria label naming this parent, and the
   * extra class, land on the copy rather than on the bag the next submenu will
   * read.
   *
   * "Disabled" here means visually hidden, not absent: the row is still built
   * and still reachable to a screen reader and to keyboard navigation, which
   * is the whole reason it is worth pinning.
   *
   * Pins quirk 2 from the class docblock — `neo-slide-menu--backlink` appears
   * twice, because the default bag already carries it and `addClass()` merges
   * without deduplicating — and the back half of quirk 3: this label is a
   * plain `string`, because `Attribute::setAttribute()` plain-texts a
   * Stringable on the way in.
   */
  public function testLabelsTheBackRowWithTheParentTitleAndHidesDisabledRows(): void {
    $menu = $this->menu(['back_icon_attributes' => ['class' => ['size-4']]]);
    $back = $this->buildItemBack($menu, ['title' => 'Products']);

    // The row is built through the item builder: no url, so a button.
    $this->assertSame('html_tag', $back['link']['#type']);
    $this->assertSame('button', $back['link']['#tag']);

    // The parent names the accessible label; the visible label is the menu's.
    $this->assertSame('Back to Products', $back['link']['#attributes']['aria-label']);
    $this->assertSame('back', $back['link']['#attributes']['data-action']);
    $this->assertSame([
      'flex items-center justify-between w-full',
      'neo-slide-menu--backlink',
      'neo-slide-menu--control',
      'neo-slide-menu--backlink',
      'cursor-pointer',
    ], $back['link']['#attributes']['class']);

    // The bag is cloned, so the menu's own back attributes are untouched by
    // the aria label and the extra class the row just added to its copy.
    $this->assertSame([
      'class' => [
        'flex items-center justify-between w-full',
        'neo-slide-menu--backlink',
        'neo-slide-menu--control',
      ],
      'data-action' => 'back',
    ], $menu->getBackAttributes()->toArray());

    // The title is the back template, wrapped again by the item builder.
    $title = $back['link']['value'];
    $this->assertSame(self::TITLE_TEMPLATE, $title['#template']);
    $inner = $title['#context']['label'];
    $this->assertSame(
      '{% if icon %}{{ icon }} {% endif %}{{ label }}',
      $inner['#template']
    );
    $this->assertSame('Back', $inner['#context']['label']);

    // The icon prefixes the label, icon-only, labelled with the parent.
    $icon = $inner['#context']['icon'];
    $this->assertInstanceOf(IconElement::class, $icon);
    $this->assertTrue($icon->isIconOnly());
    $this->assertSame('Back to Products', (string) $icon->getText());
    $this->assertSame(['class' => ['size-4']], $icon->getIconAttributes());

    // An emptied back icon leaves the prefix slot empty and builds no icon.
    $none = $this->menu(['back_icon' => '']);
    $noneBack = $this->buildItemBack($none, ['title' => 'Products']);
    $emptyIcon = $noneBack['link']['value']['#context']['label']['#context']['icon'];
    $this->assertNotInstanceOf(IconElement::class, $emptyIcon);
    $this->assertSame('', $emptyIcon);

    // A render-array title is read through its own label context.
    $wrapped = $this->buildItemBack($menu, [
      'title' => [
        '#type' => 'inline_template',
        '#template' => self::TITLE_TEMPLATE,
        '#context' => ['label' => 'Services', 'suffix' => ''],
      ],
    ]);
    $this->assertSame('Back to Services', $wrapped['link']['#attributes']['aria-label']);

    // Enabled by default: the row carries no screen-reader class.
    $this->assertTrue($menu->getBackStatus());
    $this->assertSame([], $back['#wrapper_attributes']);

    // Disabled means hidden, not omitted: the row is still built.
    $off = $this->menu(['back_status' => FALSE]);
    $hidden = $this->buildItemBack($off, ['title' => 'Products']);
    $this->assertSame(['class' => ['sr-only']], $hidden['#wrapper_attributes']);
    $this->assertSame('button', $hidden['link']['#tag']);
    $this->assertSame('Back to Products', $hidden['link']['#attributes']['aria-label']);

    // A row may opt itself back in even when the menu turned them off.
    $optedIn = $this->buildItemBack($off, [
      'title' => 'Products',
      '#back_status' => TRUE,
    ]);
    $this->assertSame([], $optedIn['#wrapper_attributes']);
  }

  /**
   * Omits the view-all row without a url or with a special route token.
   *
   * Covers: "it omits the view-all row entirely when the parent has no url or
   * its url is a special route token".
   *
   * A "links to nothing" parent has no destination to view all of, so the row
   * returns nothing at all — not a hidden row, not an empty container. That
   * early return is the one place these two control rows behave differently
   * from each other; a *disabled* view-all row is hidden like the back row is.
   *
   * The emptiness assertions lead with a count on purpose: an `assertSame([],
   * …)` against a built row prints the whole render array, translation mock
   * included, and buries the finding.
   *
   * Pins the view-all half of quirk 3 from the class docblock: this label is
   * written straight into a PHP array, so unlike the back row's it stays a
   * `TranslatableMarkup`.
   */
  public function testOmitsTheViewAllRowWithoutUrlOrWithSpecialRouteTokens(): void {
    $menu = $this->menu();
    $children = [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]];

    // Nothing at all comes back — not a hidden row, not an empty container.
    // The count is asserted first so a regression reports a size rather than
    // a whole render array.
    $noUrl = $this->buildItemAll($menu, ['title' => 'Products']);
    $this->assertCount(0, $noUrl);
    $this->assertSame([], $noUrl);
    foreach (['<nolink>', '<none>', '<button>'] as $route) {
      $token = $this->buildItemAll($menu, [
        'title' => 'Products',
        'url' => Url::fromRoute($route),
      ]);
      $this->assertCount(0, $token, $route);
      $this->assertSame([], $token, $route);
    }

    // In a finished submenu the row is simply absent, and the back row —
    // which has no such early return — is still there above the children.
    foreach ([NULL, '<nolink>', '<none>', '<button>'] as $route) {
      $item = ['title' => 'Products', 'children' => $children];
      if ($route !== NULL) {
        $item['url'] = Url::fromRoute($route);
      }
      $build = $this->buildItem($menu, $item);
      $this->assertSame(
        ['back', 0],
        array_keys($build['children']['#items']),
        $route ?? 'no url'
      );
    }

    // A routed url gets the row, with the menu's link classes plus its own.
    $all = $this->buildItemAll($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
    ]);
    $this->assertSame(
      [self::DEFAULT_LINK_CLASS, 'neo-slide-menu--all'],
      $all['link']['#attributes']['class']
    );

    // The label is composed from the menu's prefix, the parent and the suffix.
    $ariaLabel = $all['link']['#attributes']['aria-label'];
    $this->assertInstanceOf(TranslatableMarkup::class, $ariaLabel);
    $this->assertSame('View all Products Right now', (string) $ariaLabel);
    $inner = $all['link']['#title']['#context']['label'];
    $this->assertSame(
      '{% if prefix %}{{ prefix }} {% endif %}{{ label }}{% if suffix %} {{ suffix }}{% endif %}',
      $inner['#template']
    );
    $this->assertSame([
      'prefix' => 'View all',
      'label' => 'Products',
      'suffix' => 'Right now',
    ], $inner['#context']);

    // A menu may reword both halves.
    $reworded = $this->menu(['all_prefix' => 'See every', 'all_suffix' => '']);
    $rewordedAll = $this->buildItemAll($reworded, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
    ]);
    $this->assertSame(
      'See every Products ',
      (string) $rewordedAll['link']['#attributes']['aria-label']
    );
    $this->assertSame([
      'prefix' => 'See every',
      'label' => 'Products',
      'suffix' => '',
    ], $rewordedAll['link']['#title']['#context']['label']['#context']);

    // An external url is not routed, so the token guard never rejects it.
    $external = $this->buildItemAll($menu, [
      'title' => 'Docs',
      'url' => Url::fromUri('https://example.com/docs'),
    ]);
    $this->assertNotSame([], $external);
    $this->assertFalse($external['link']['#url']->isRouted());

    // Like the back row, a disabled row is hidden rather than omitted — the
    // early return above is the one place the two rows differ.
    $off = $this->menu(['all_status' => FALSE]);
    $hidden = $this->buildItemAll($off, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
    ]);
    $this->assertSame(['class' => ['sr-only']], $hidden['#wrapper_attributes']);
    $this->assertSame('link', $hidden['link']['#type']);

    // A row may opt itself back in even when the menu turned them off.
    $optedIn = $this->buildItemAll($off, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
      '#all_status' => TRUE,
    ]);
    $this->assertSame([], $optedIn['#wrapper_attributes']);

    // But a disabled row with no url is still nothing at all.
    $this->assertCount(0, $this->buildItemAll($off, ['title' => 'Products']));
  }

  /**
   * Renders a level at or below the expand depth as an inline group.
   *
   * Covers: "it renders a level at or below the expand depth as a group
   * heading with an inline child list, and gives it no aria, back, view-all or
   * icon treatment".
   *
   * The inline class is what opts the child list out of the slide mechanics —
   * CSS panel positioning, JS forward navigation and content wrapping all key
   * off it — so an expanded level takes none of the sliding treatment at all.
   * The three assertions that name what is *absent* are the load-bearing half:
   * a level that gained the group classes and kept its aria attributes would
   * announce a popup that is already open.
   *
   * Zero is the default and disables expansion at every depth, which is what
   * makes this a mode rather than a behaviour change.
   */
  public function testRendersExpandedLevelsAsGroupHeadingsWithInlineChildLists(): void {
    $menu = $this->menu(['expand_depth' => 2]);
    $this->assertSame(2, $menu->getExpandDepth());
    $item = [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
      'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
    ];

    // Above the depth the level still slides: aria, parent title, both
    // control rows and the child indicator icon.
    $sliding = $this->buildItem($menu, $item, 1);
    $this->assertSame([
      'class' => [self::DEFAULT_LINK_CLASS, 'neo-slide-menu--children'],
      'aria-expanded' => 'false',
      'aria-haspopup' => 'true',
    ], $sliding['link']['#attributes']);
    $this->assertSame([], $sliding['#wrapper_attributes']);
    $this->assertSame(
      ['data-parent-title' => 'Products'],
      $sliding['children']['#attributes']
    );
    $this->assertSame(['back', 'all', 0], array_keys($sliding['children']['#items']));
    $this->assertInstanceOf(
      IconElement::class,
      $sliding['link']['#title']['#context']['suffix']
    );

    // At the depth the level becomes a group heading with an inline list.
    foreach ([2, 3, 9] as $depth) {
      $expanded = $this->buildItem($menu, $item, $depth);

      $this->assertSame(
        ['class' => ['neo-slide-menu--group']],
        $expanded['#wrapper_attributes'],
        (string) $depth
      );
      $this->assertSame([
        'class' => [
          self::DEFAULT_LINK_CLASS,
          'neo-slide-menu--group-heading',
          'font-bold',
        ],
      ], $expanded['link']['#attributes'], (string) $depth);
      $this->assertSame(
        ['class' => ['neo-slide-menu--inline']],
        $expanded['children']['#attributes'],
        (string) $depth
      );

      // None of the sliding treatment reaches it.
      $this->assertArrayNotHasKey('aria-expanded', $expanded['link']['#attributes']);
      $this->assertArrayNotHasKey('aria-haspopup', $expanded['link']['#attributes']);
      $this->assertArrayNotHasKey(
        'data-parent-title',
        $expanded['children']['#attributes']
      );
      $this->assertSame([0], array_keys($expanded['children']['#items']));
      $this->assertSame('', $expanded['link']['#title']['#context']['suffix']);
    }

    // An expanded heading with a special route token keeps the plain span
    // core renders: its children are already visible, so it triggers nothing.
    $token = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('<nolink>'),
      'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
    ], 2);
    $this->assertSame('link', $token['link']['#type']);
    $this->assertSame('<nolink>', $token['link']['#url']->getRouteName());

    // Zero is the default and disables expansion at every depth.
    $off = $this->menu();
    $this->assertSame(0, $off->getExpandDepth());
    foreach ([1, 2, 3, 9] as $depth) {
      $build = $this->buildItem($off, $item, $depth);
      $this->assertSame([], $build['#wrapper_attributes'], (string) $depth);
      $this->assertSame(
        'false',
        $build['link']['#attributes']['aria-expanded'],
        (string) $depth
      );
      $this->assertArrayNotHasKey('class', $build['children']['#attributes']);
    }
  }

  /**
   * Leaves an empty child list unexpanded and clamps a negative depth.
   *
   * Covers: "it does not expand a row whose children built to nothing, and
   * clamps a negative expand depth to zero".
   *
   * The empty-children guard is unreachable from the public surface — the
   * quirk ticket 05 recorded third — so it is driven through the same subclass
   * instrument: an anonymous `SlideMenu` whose `buildItems()` always returns
   * an empty list. A row that reaches it takes neither branch, so it gets the
   * group treatment and the sliding treatment alike: neither.
   */
  public function testDoesNotExpandEmptyChildrenAndClampsNegativeDepths(): void {
    $menu = $this->menuWithNoBuildableChildren(['expand_depth' => 1]);
    $this->assertSame(1, $menu->getExpandDepth());

    $build = $this->buildItem($menu, [
      'title' => 'Products',
      'url' => Url::fromRoute('entity.node.canonical', ['node' => 12]),
      'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
    ], 1);

    // The depth qualifies, but the empty list takes the row out of both
    // branches: no group treatment and no sliding treatment either.
    $this->assertSame([], $build['children']['#items']);
    $this->assertSame([], $build['#wrapper_attributes']);
    $this->assertSame(
      ['class' => [self::DEFAULT_LINK_CLASS]],
      $build['link']['#attributes']
    );
    $this->assertArrayNotHasKey('#attributes', $build['children']);
    $this->assertSame('', $build['link']['#title']['#context']['suffix']);

    // A negative depth is clamped to zero rather than stored as given.
    foreach ([-1, -2, -99] as $negative) {
      $clamped = $this->menu();
      $clamped->setExpandDepth($negative);
      $this->assertSame(0, $clamped->getExpandDepth(), (string) $negative);

      // The option setter reaches the same clamp.
      $this->assertSame(
        0,
        $this->menu(['expand_depth' => $negative])->getExpandDepth(),
        (string) $negative
      );
    }

    // Zero and a positive depth are stored untouched.
    $this->assertSame(0, $this->menu(['expand_depth' => 0])->getExpandDepth());
    $this->assertSame(4, $this->menu(['expand_depth' => 4])->getExpandDepth());
  }

  /**
   * Casts an option to the setter's type and ignores an unknown key.
   *
   * Covers: "it casts an option to the setter's declared parameter type and
   * ignores an unknown option key without raising".
   *
   * The setter is reflection-driven: the key is camel-cased, prefixed with
   * `set`, checked with `method_exists()` and the value cast to the setter's
   * own declared parameter type before the call. `SlideMenu.php` declares
   * `strict_types=1`, so the cast is not a convenience — without it the
   * dispatched call raises a `TypeError`.
   *
   * Pins quirks 4 and 5 from the class docblock: a key with no matching setter
   * is silently ignored, which is why a typo in an option array is invisible
   * today, and a `0` reaching a string setter becomes the string `'0'`, which
   * is falsy and therefore disables the very thing it was setting.
   */
  public function testCastsOptionsToTheSettersTypeAndIgnoresUnknownKeys(): void {
    // A string reaching an int setter arrives as an int. The class declares
    // strict_types, so without the cast this call would raise a TypeError.
    $this->assertSame(3, $this->menu(['expand_depth' => '3'])->getExpandDepth());
    $this->assertSame(0, $this->menu(['expand_depth' => 'nonsense'])->getExpandDepth());

    // An int reaching a string setter arrives as a string.
    $this->assertSame('42', $this->menu(['back_label' => 42])->getBackLabel());
    // '0' is a string, and it is also falsy — an icon set this way is never
    // built, because the builder tests the icon name for truthiness.
    $this->assertSame('0', $this->menu(['child_icon' => 0])->getChildIcon());

    // Anything reaching a bool setter arrives as a bool, on PHP's own rules:
    // a non-empty string is TRUE, the string '0' is FALSE.
    $this->assertTrue($this->menu(['back_status' => 'no'])->getBackStatus());
    $this->assertFalse($this->menu(['all_status' => '0'])->getAllStatus());
    $this->assertFalse($this->menu(['back_status' => 0])->getBackStatus());

    // A setter whose parameter is not one of the three scalar types takes the
    // value untouched.
    $this->assertSame(
      ['class' => ['x']],
      $this->menu(['item_attributes' => ['class' => ['x']]])->getItemAttributes()->toArray()
    );

    // The key is camel-cased and prefixed, so a camelCase key hits the same
    // setter a snake_case one does.
    $this->assertSame(2, $this->menu(['expandDepth' => 2])->getExpandDepth());

    // A key with no matching setter is silently ignored — no exception, no
    // warning, and nothing else disturbed. This is why a typo in an option
    // array is invisible today.
    $typo = $this->menu([
      'expand_dept' => 4,
      'back_lable' => 'Return',
      'bogus_option' => 'red',
      'expand_depth' => 2,
    ]);
    $this->assertSame(2, $typo->getExpandDepth());
    $this->assertSame('Back', $typo->getBackLabel());

    // There is no valid key set to check against: the dispatcher reaches any
    // setter on the object, including ones no caller would think of as menu
    // options.
    $translation = $this->getStringTranslationStub();
    $reached = new SlideMenu([], [
      'string_translation' => $translation,
      'items' => [['title' => 'Products']],
    ]);
    $this->assertSame([['title' => 'Products']], $reached->getItems());
    $this->assertSame(
      'Hi there',
      (string) (new \ReflectionMethod($reached, 't'))->invoke($reached, 'Hi @a', ['@a' => 'there'])
    );

    // And the option keys themselves appear nowhere in the class — the only
    // record of what an option may be called is the setter names.
    $source = file_get_contents((new \ReflectionClass(SlideMenu::class))->getFileName());
    $this->assertIsString($source);
    foreach ([
      'expand_depth',
      'back_status',
      'back_icon',
      'back_label',
      'back_attributes',
      'back_icon_attributes',
      'all_status',
      'all_prefix',
      'all_suffix',
      'child_icon',
      'child_icon_attributes',
    ] as $key) {
      $this->assertStringNotContainsString("'" . $key . "'", $source, $key);
    }
  }

  /**
   * Wraps the menu in a labelled nav with the library and the settings.
   *
   * Covers: "it wraps the menu in a labelled nav tag, attaches the slide menu
   * library and passes the back label into drupal settings".
   *
   * This is the only method on the object a caller is expected to call, and
   * the only place the browser side is attached. `backLabel` is the one option
   * that crosses into `drupalSettings`, because the JS builds its own back
   * control when it clones a level.
   *
   * The last two cases pin the starting depth from both sides: the walk begins
   * at depth 1, so an expand depth of 1 groups the top level and an expand
   * depth of 2 leaves it sliding. Every expand-depth answer is relative to it.
   */
  public function testWrapsTheMenuInLabelledNavWithLibraryAndSettings(): void {
    $items = [
      ['title' => 'Products', 'url' => Url::fromRoute('products')],
      ['title' => 'Services', 'url' => Url::fromRoute('services')],
    ];
    $menu = $this->menu(['items' => $items]);
    $build = $menu->toRenderable();

    $this->assertSame(
      ['#type', '#tag', '#attributes', '#attached', 'menu'],
      array_keys($build)
    );
    $this->assertSame('html_tag', $build['#type']);
    $this->assertSame('nav', $build['#tag']);

    $this->assertSame(['neo-slide-menu'], $build['#attributes']['class']);
    $this->assertSame('navigation', $build['#attributes']['role']);
    $this->assertInstanceOf(
      TranslatableMarkup::class,
      $build['#attributes']['aria-label']
    );
    $this->assertSame('Slide menu', (string) $build['#attributes']['aria-label']);

    // The browser side is attached here and nowhere else in the object.
    $this->assertSame(['library', 'drupalSettings'], array_keys($build['#attached']));
    $this->assertSame(['neo/slide-menu'], $build['#attached']['library']);
    $this->assertSame([
      'neo' => ['slideMenu' => ['backLabel' => 'Back']],
    ], $build['#attached']['drupalSettings']);

    // The label the browser side reads is the menu's own, not the default.
    $relabelled = $this->menu(['back_label' => 'Up one level'])->toRenderable();
    $this->assertSame(
      'Up one level',
      $relabelled['#attached']['drupalSettings']['neo']['slideMenu']['backLabel']
    );

    // The nav wraps the built item list, one row per item the menu holds.
    $this->assertSame('slide_list', $build['menu']['#theme']);
    $this->assertSame([0, 1], array_keys($build['menu']['#items']));
    $this->assertSame(
      'Products',
      $build['menu']['#items'][0]['link']['#title']['#context']['label']
    );
    $this->assertSame(
      'Services',
      $build['menu']['#items'][1]['link']['#title']['#context']['label']
    );

    // An empty menu is still a nav around an empty list.
    $empty = $this->menu()->toRenderable();
    $this->assertSame(['#theme' => 'slide_list', '#items' => []], $empty['menu']);

    // The walk starts at depth 1, pinned from both sides: an expand depth of
    // 1 groups the top level and an expand depth of 2 leaves it sliding.
    $nested = [
      [
        'title' => 'Products',
        'url' => Url::fromRoute('products'),
        'children' => [['title' => 'Widgets', 'url' => Url::fromRoute('widgets')]],
      ],
    ];
    $grouped = $this->menu([
      'expand_depth' => 1,
      'items' => $nested,
    ])->toRenderable();
    $this->assertSame(
      ['class' => ['neo-slide-menu--group']],
      $grouped['menu']['#items'][0]['#wrapper_attributes']
    );

    $sliding = $this->menu([
      'expand_depth' => 2,
      'items' => $nested,
    ])->toRenderable();
    $this->assertSame([], $sliding['menu']['#items'][0]['#wrapper_attributes']);
    $this->assertSame(
      'false',
      $sliding['menu']['#items'][0]['link']['#attributes']['aria-expanded']
    );
  }

  /**
   * Builds a slide menu carrying a stub translation service.
   *
   * @param array $options
   *   Options for the menu, in the snake_case form the option setter takes.
   *
   * @return \Drupal\neo\SlideMenu
   *   The menu.
   */
  private function menu(array $options = []): SlideMenu {
    $menu = new SlideMenu([], $options);
    $menu->setStringTranslation($this->getStringTranslationStub());
    return $menu;
  }

  /**
   * Builds a slide menu whose child lists always come back empty.
   *
   * The only instrument that reaches the "children built to nothing" guard:
   * nothing a caller can pass to the real `buildItems()` produces an empty
   * `#items` from a non-empty input.
   *
   * @param array $options
   *   Options for the menu, in the snake_case form the option setter takes.
   *
   * @return \Drupal\neo\SlideMenu
   *   The menu.
   */
  private function menuWithNoBuildableChildren(array $options = []): SlideMenu {
    $menu = new class([], $options) extends SlideMenu {

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
   * Invokes the protected back-row builder.
   *
   * @param \Drupal\neo\SlideMenu $menu
   *   The menu to build through.
   * @param array $item
   *   The parent item the row navigates back from.
   *
   * @return array
   *   The built back row.
   */
  private function buildItemBack(SlideMenu $menu, array $item): array {
    return (new \ReflectionMethod($menu, 'buildItemBack'))->invoke($menu, $item);
  }

  /**
   * Invokes the protected view-all-row builder.
   *
   * @param \Drupal\neo\SlideMenu $menu
   *   The menu to build through.
   * @param array $item
   *   The parent item the row links to.
   *
   * @return array
   *   The built view-all row, or an empty array when there is nothing to
   *   view all of.
   */
  private function buildItemAll(SlideMenu $menu, array $item): array {
    return (new \ReflectionMethod($menu, 'buildItemAll'))->invoke($menu, $item);
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
