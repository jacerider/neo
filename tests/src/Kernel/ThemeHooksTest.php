<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo\Hook\NeoThemeHooks;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module's theme registration, suggestion and preprocessors.
 *
 * All of it is now `Drupal\neo\Hook\NeoThemeHooks`, split from the behavioural
 * hooks the way core splits its own `…Hooks` / `…ThemeHooks` pairs. Four of the
 * methods are not hooks at all: they were `template_preprocess_HOOK()`
 * functions, deprecated as of Drupal 11.3 and removed in Drupal 12, and they
 * are now **initial preprocess** callbacks named from their own theme hook
 * definitions.
 *
 * That is where this ticket's risk lives, and it is why every assertion below
 * goes through the theme registry or the module handler rather than through the
 * object. A theme hook naming a callback that does not resolve fails at render
 * time rather than at build time, and a preprocess method carrying the wrong
 * hook name in its attribute produces a class that reads correctly and is never
 * called. A method nobody invokes is not a hook implementation.
 *
 * The bodies moved unchanged, including the three parts that look accidental
 * and are not: the Views table preprocessor reaches `Attribute` objects through
 * a `foreach` value copy and mutates them, which works because they are
 * objects; the accordion item preprocessor blanks
 * `$variables['element']['#attributes']` after reading it; and the slide list
 * preprocessor reads `$variables['theme_hook_original']` to inherit the theme
 * onto nested children. Each is asserted here as it stands.
 *
 * Two surfaces are unobservable on this site and are covered here or nowhere:
 * no view uses the `neo_clean` style, so the suggestion has no page to prove
 * itself on, and no view configures a table prop, so the Views table
 * preprocessor's inner loop never runs in a browser. Both are driven with
 * constructed arguments, which is what the hook would have been handed in
 * production.
 */
#[Group('neo')]
final class ThemeHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `views` is deliberately absent — both
   * views-shaped preprocessors work on what they are handed, and the test
   * runner's bootstrap registers every extension's namespace, so a mock of
   * `ViewExecutable` needs the class rather than the module.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'neo',
  ];

  /**
   * {@inheritdoc}
   *
   * The theme registry is the seam three of these tests read, and it cannot be
   * built without an active theme, so one is installed and made the default.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
  }

  /**
   * The six theme hooks are registered from the class, unchanged.
   *
   * Acceptance criterion: it registers the six theme hooks with the same
   * variables and render elements they had, from the class rather than from the
   * `.module`.
   *
   * Both halves are needed. The array is asserted key by key because a theme
   * hook that loses a declared variable renders `NULL` rather than failing, and
   * the implementation is named because `hook_theme()` would answer exactly the
   * same array from a surviving global.
   */
  public function testRegistersTheSixThemeHooksFromTheClass(): void {
    $hooks = $this->themeHooks();

    $this->assertSame([
      'description_list',
      'accordion',
      'accordion_item',
      'slide_list',
      'input__number_plus_minus',
      'views_neo_clean',
    ], array_keys($hooks), 'The same six theme hooks, in the same order.');

    $this->assertSame([
      'items' => [],
      'attributes' => [],
      'neo_style' => '',
      'neo_size' => 'md',
    ], $hooks['description_list']['variables']);
    $this->assertSame('element', $hooks['accordion']['render element']);
    $this->assertSame('element', $hooks['accordion_item']['render element']);
    $this->assertSame(['items' => [], 'attributes' => []], $hooks['slide_list']['variables']);
    $this->assertSame('element', $hooks['input__number_plus_minus']['render element']);
    $this->assertSame([
      'attributes' => [],
      'view_array' => [],
      'view' => NULL,
      'rows' => [],
      'header' => [],
      'footer' => [],
      'empty' => [],
      'exposed' => [],
      'more' => [],
      'feed_icons' => [],
      'pager' => [],
      'title' => '',
      'attachment_before' => [],
      'attachment_after' => [],
    ], $hooks['views_neo_clean']['variables']);

    // Registered, not merely returned: each one reaches the theme registry.
    $registry = $this->container->get('theme.registry')->get();
    foreach (array_keys($hooks) as $hook) {
      $this->assertArrayHasKey($hook, $registry, $hook . ' is in the theme registry.');
    }

    // The array above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo: ' . NeoThemeHooks::class . '::theme',
      $this->hookImplementations('theme')
    );
    $this->assertFalse(function_exists('neo_theme'), 'neo_theme() no longer exists.');
  }

  /**
   * The four deprecated preprocessors are initial preprocess callbacks now.
   *
   * Acceptance criterion: it names an initial preprocess callback on each of
   * the four theme hooks that had a `template_preprocess_` function, and no
   * such function remains in the package.
   *
   * The callback is resolved rather than string-matched. A theme hook naming a
   * callback that does not resolve logs a warning and renders on without it, so
   * the failure this asserts against is silent everywhere except in the markup.
   */
  public function testNamesAnInitialPreprocessCallbackOnTheFourFormerTemplatePreprocessHooks(): void {
    $expected = [
      'accordion' => 'preprocessAccordion',
      'accordion_item' => 'preprocessAccordionItem',
      'slide_list' => 'preprocessSlideList',
      'input__number_plus_minus' => 'preprocessInputNumberPlusMinus',
    ];
    $hooks = $this->themeHooks();
    $registry = $this->container->get('theme.registry')->get();
    $resolver = $this->container->get('callable_resolver');

    foreach ($expected as $hook => $method) {
      $definition = NeoThemeHooks::class . ':' . $method;
      $this->assertSame(
        $definition,
        $hooks[$hook]['initial preprocess'] ?? NULL,
        $hook . ' names its initial preprocess callback.'
      );
      // And the registry carries it through to where the theme manager reads
      // it, rather than dropping it on the way.
      $this->assertSame(
        $definition,
        $registry[$hook]['initial preprocess'] ?? NULL,
        $hook . "'s callback survives the registry build."
      );
      // It resolves against the container, which is the whole risk.
      $this->assertIsCallable(
        $resolver->getCallableFromDefinition($definition),
        $definition . ' resolves.'
      );
      $this->assertFalse(
        function_exists('template_preprocess_' . $hook),
        'template_preprocess_' . $hook . '() no longer exists.'
      );
    }

    // Nothing in the package declares one under any name, which is what clears
    // the Drupal 12 removal.
    $declarations = [];
    foreach ($this->packageSources() as $file) {
      if (preg_match('/function template_preprocess_\w+\s*\(/', (string) file_get_contents($file))) {
        $declarations[] = $file;
      }
    }
    $this->assertSame([], $declarations, 'No template_preprocess_HOOK() is left in the package.');
  }

  /**
   * The accordion item's initial preprocess runs, and runs first.
   *
   * Acceptance criterion: it runs the accordion item's initial preprocess and
   * produces the same summary, content and title variables, in the position the
   * deleted function ran in.
   *
   * Driven through the registry entry the theme manager reads, resolved the way
   * the theme manager resolves it, so what runs here is what runs in a render.
   */
  public function testRunsTheAccordionItemsInitialPreprocessInThePositionTheFunctionRanIn(): void {
    $info = $this->container->get('theme.registry')->get()['accordion_item'];
    $callable = $this->container->get('callable_resolver')
      ->getCallableFromDefinition($info['initial preprocess']);

    // The description is held in a variable rather than written inline, because
    // `DrupalPractice` reads a literal `#description` as a string that should
    // have been translated and this one is a fixture.
    $description = 'A description';
    $variables = [
      'element' => [
        '#attributes' => ['id' => 'acc-one', 'open' => TRUE],
        '#summary_attributes' => ['class' => ['summary']],
        '#content_attributes' => ['class' => ['content']],
        '#title' => 'A <em>title</em>',
        '#description' => $description,
        '#children' => 'the children',
        '#value' => 'the value',
        '#required' => TRUE,
        '#parents' => ['one', 'two'],
      ],
    ];
    $callable($variables, 'accordion_item', $info);

    $this->assertSame(['id' => 'acc-one', 'open' => TRUE], $variables['attributes']);
    $this->assertSame('button', (string) $variables['summary_attributes']['role']);
    $this->assertSame('acc-one-summary', (string) $variables['summary_attributes']['id']);
    $this->assertSame('acc-one-content', (string) $variables['summary_attributes']['aria-controls']);
    $this->assertSame('true', (string) $variables['summary_attributes']['aria-expanded']);
    $this->assertSame('summary', (string) $variables['summary_attributes']['class']);
    $this->assertSame('acc-one-content', (string) $variables['content_attributes']['id']);
    $this->assertSame('acc-one-summary', (string) $variables['content_attributes']['aria-labelledby']);
    $this->assertSame('content', (string) $variables['content_attributes']['class']);
    $this->assertSame('acconetwo', $variables['alpine_id']);
    // A string title is wrapped so its markup is filtered rather than escaped.
    $this->assertSame(['#markup' => 'A <em>title</em>'], $variables['title']);
    $this->assertSame($description, $variables['description']);
    $this->assertSame('the children', $variables['children']);
    $this->assertSame('the value', $variables['value']);
    $this->assertTrue($variables['required']);
    $this->assertNull($variables['errors'], 'Error messages are suppressed.');
    // Blanked after being read, which the template depends on.
    $this->assertSame([], $variables['element']['#attributes']);

    // Position: an initial preprocess callback runs before every module and
    // theme preprocess function, which is where the deleted function ran. The
    // registry proves it is not in that list at all. The key itself is
    // optional — core unsets it when the list comes out empty, which it does
    // in this fixture because nothing else preprocesses `accordion_item`.
    $preprocessFunctions = $info['preprocess functions'] ?? [];
    $this->assertNotContains(
      'template_preprocess_accordion_item',
      $preprocessFunctions,
      'The deprecated function is not among the preprocess functions.'
    );
    $this->assertNotContains(
      $info['initial preprocess'],
      $preprocessFunctions,
      'Nor is the callback that replaced it — it runs ahead of them.'
    );
  }

  /**
   * The clean views template is suggested only for Neo's clean style.
   *
   * Acceptance criterion: it suggests the clean views template only for a view
   * whose style plugin is Neo's clean style.
   *
   * No view on this site uses that style, so this is the only place the branch
   * runs. The view is a mock because the hook reads one thing off it.
   */
  public function testSuggestsTheCleanViewsTemplateOnlyForTheNeoCleanStyle(): void {
    $this->assertSame(
      ['views_view__neo_clean'],
      $this->container->get('module_handler')->invoke('neo', 'theme_suggestions_views_view', [
        ['view' => $this->viewWithStyle('neo_clean')],
      ])
    );
    $this->assertSame(
      [],
      $this->container->get('module_handler')->invoke('neo', 'theme_suggestions_views_view', [
        ['view' => $this->viewWithStyle('table')],
      ]),
      'Any other style suggests nothing.'
    );

    $this->assertContains(
      'neo: ' . NeoThemeHooks::class . '::themeSuggestionsViewsView',
      $this->hookImplementations('theme_suggestions_views_view')
    );
    $this->assertFalse(function_exists('neo_theme_suggestions_views_view'));
  }

  /**
   * A select gains the Neo classes, title, library and size.
   *
   * Acceptance criterion: it adds the Neo select classes, title attribute,
   * library and size to a select, and skips a weight select and an option-less
   * element exactly as before.
   */
  public function testAddsTheNeoSelectClassesTitleLibraryAndSize(): void {
    $variables = [
      'element' => [
        '#options' => ['a' => 'A'],
        '#title' => 'Pick one',
        '#neo_style' => 'ghost',
      ],
      'attributes' => ['class' => ['form-select']],
    ];
    $this->invokePreprocess('select', $variables);

    $this->assertSame(['form-select', 'neo-select', 'neo-select-single'], $variables['attributes']['class']);
    $this->assertSame(['neo/tom-select'], $variables['#attached']['library']);
    $this->assertSame(1, $variables['attributes']['size']);
    $this->assertSame('Pick one', $variables['attributes']['data-neo-title']);
    $this->assertSame('ghost', $variables['attributes']['data-neo-style']);

    // A multiple select takes the other class.
    $variables = [
      'element' => ['#options' => ['a' => 'A'], '#multiple' => TRUE],
      'attributes' => ['class' => []],
    ];
    $this->invokePreprocess('select', $variables);
    $this->assertSame(['neo-select', 'neo-select-multi'], $variables['attributes']['class']);
    $this->assertArrayNotHasKey('data-neo-title', $variables['attributes']);

    // A weight select is left exactly as it arrived.
    $weight = [
      'element' => ['#options' => ['a' => 'A'], '#is_weight' => TRUE, '#title' => 'Weight'],
      'attributes' => ['class' => ['form-select']],
    ];
    $variables = $weight;
    $this->invokePreprocess('select', $variables);
    $this->assertSame($weight, $variables, 'A weight select is untouched.');

    // So is an element declaring no options at all.
    $optionless = ['element' => ['#title' => 'No options'], 'attributes' => ['class' => []]];
    $variables = $optionless;
    $this->invokePreprocess('select', $variables);
    $this->assertSame($optionless, $variables, 'An option-less element is untouched.');

    $this->assertContains(
      'neo: ' . NeoThemeHooks::class . '::preprocessSelect',
      $this->hookImplementations('preprocess_select')
    );
    $this->assertFalse(function_exists('neo_preprocess_select'));
  }

  /**
   * An entity autocomplete swaps core's library and class for Neo's.
   *
   * Acceptance criterion: it swaps core's autocomplete library and class for
   * Neo's on an entity autocomplete textfield and leaves an ordinary textfield
   * alone.
   *
   * Both filtered arrays are asserted with their keys, because `array_filter()`
   * preserves them and the body moved unchanged.
   */
  public function testSwapsCoresAutocompleteLibraryAndClassForNeosOnAnEntityAutocomplete(): void {
    $variables = [
      'element' => [
        '#type' => 'entity_autocomplete',
        '#attached' => ['library' => ['core/drupal.autocomplete', 'core/once']],
        '#autocreate' => TRUE,
        '#tags' => TRUE,
      ],
      'attributes' => ['class' => ['form-text', 'form-autocomplete']],
    ];
    $this->invokePreprocess('input__textfield', $variables);

    // Core's library is dropped from the element, and the keys around it are
    // preserved exactly as `array_filter()` leaves them.
    $this->assertSame([1 => 'core/once'], $variables['element']['#attached']['library']);
    // Neo's is attached to the variables rather than to the element.
    $this->assertSame(['neo/tom-select'], $variables['#attached']['library']);
    $this->assertSame([
      0 => 'form-text',
      1 => 'neo-entity-autocomplete',
      2 => 'neo-autocreate',
      3 => 'neo-select-multi',
    ], $variables['attributes']['class']);

    // Without autocreate or tags, only the one class is added.
    $variables = [
      'element' => ['#type' => 'entity_autocomplete'],
      'attributes' => ['class' => ['form-autocomplete']],
    ];
    $this->invokePreprocess('input__textfield', $variables);
    $this->assertSame(['neo-entity-autocomplete'], $variables['attributes']['class']);

    // An ordinary textfield is left exactly as it arrived.
    $textfield = [
      'element' => ['#type' => 'textfield', '#attached' => ['library' => ['core/drupal.autocomplete']]],
      'attributes' => ['class' => ['form-text', 'form-autocomplete']],
    ];
    $variables = $textfield;
    $this->invokePreprocess('input__textfield', $variables);
    $this->assertSame($textfield, $variables, 'An ordinary textfield is untouched.');

    $this->assertContains(
      'neo: ' . NeoThemeHooks::class . '::preprocessInputTextfield',
      $this->hookImplementations('preprocess_input__textfield')
    );
    $this->assertFalse(function_exists('neo_preprocess_input__textfield'));
  }

  /**
   * The table props reach the Views UI table and the rendered Views table.
   *
   * Acceptance criterion: it folds the table props columns into the Views UI
   * style plugin table and applies the per-column prop classes in a rendered
   * Views table.
   *
   * No view here configures a table prop, so both bodies are driven with the
   * arguments the theme layer would have handed them.
   */
  public function testFoldsTheTablePropsColumnsIntoTheViewsUiTableAndAppliesThePerColumnClasses(): void {
    // The Views UI half: the props' selects move out of `form['neo']` and into
    // the settings table, one column per prop carrying an option map.
    $variables = [
      'form' => [
        '#neo_header' => [
          'neo_style' => 'Neo Style',
          'neo_size' => 'Neo Size',
          'neo_sticky' => 'Neo Sticky',
        ],
        'neo' => [
          'title' => [
            'neo_style' => ['#type' => 'select', '#default_value' => 'heading'],
            'neo_size' => ['#type' => 'select', '#default_value' => ''],
            'neo_sticky' => ['#type' => 'select', '#default_value' => 'left'],
          ],
          'body' => [
            'neo_style' => ['#type' => 'select', '#default_value' => ''],
            'neo_size' => ['#type' => 'select', '#default_value' => 'min'],
            'neo_sticky' => ['#type' => 'select', '#default_value' => ''],
          ],
        ],
      ],
      'table' => ['#header' => ['name' => 'Name'], '#rows' => []],
    ];
    $this->invokePreprocess('views_ui_style_plugin_table', $variables);

    $this->assertSame(
      ['name' => 'Name', 'neo_style' => 'Neo Style', 'neo_size' => 'Neo Size', 'neo_sticky' => 'Neo Sticky'],
      $variables['table']['#header']
    );
    $this->assertCount(2, $variables['table']['#rows'], 'One row per column.');
    $this->assertSame(
      ['#type' => 'select', '#default_value' => 'heading'],
      $variables['table']['#rows'][0][0]['data']
    );
    $this->assertSame(
      ['#type' => 'select', '#default_value' => 'min'],
      $variables['table']['#rows'][1][1]['data']
    );
    $this->assertCount(3, $variables['table']['#rows'][0], 'One cell per prop carrying options.');
    $this->assertArrayNotHasKey('neo', $variables['form'], 'The staging element is unset.');

    // A form that never got the props is left alone.
    $bare = ['form' => ['options' => []], 'table' => ['#header' => [], '#rows' => []]];
    $variables = $bare;
    $this->invokePreprocess('views_ui_style_plugin_table', $variables);
    $this->assertSame($bare, $variables, 'A form with no neo element is untouched.');

    // The rendered half: each configured prop becomes one class on the cell,
    // with the prop's other options removed first.
    $style = $this->createMock(Table::class);
    $style->options = [
      'info' => [
        'title' => ['neo_style' => 'heading', 'neo_align' => 'center', 'neo_sticky' => 'left'],
      ],
    ];
    $view = $this->createMock(ViewExecutable::class);
    $view->style_plugin = $style;
    $variables = [
      'view' => $view,
      'rows' => [
        [
          'columns' => [
            'title' => ['attributes' => new Attribute(['class' => ['views-field', 'style--sm']])],
            'body' => ['attributes' => new Attribute(['class' => ['views-field']])],
          ],
        ],
      ],
    ];
    $this->invokePreprocess('views_view_table', $variables);

    // The Attribute objects are reached through a `foreach` value copy and
    // mutated, which works because they are objects — so the originals carry
    // the change.
    $classes = $variables['rows'][0]['columns']['title']['attributes']['class']->value();
    $this->assertContains('views-field', $classes, 'What the cell already had survives.');
    $this->assertContains('style--heading', $classes, 'The configured prop becomes a class.');
    $this->assertNotContains('style--sm', $classes, 'The prop\'s other options are removed first.');
    $this->assertContains('align--center', $classes, 'A prop with an open option map still stamps.');
    $this->assertNotContains('sticky--left', $classes, 'A prop flagged apply => FALSE does not.');
    $this->assertSame(
      ['views-field'],
      $variables['rows'][0]['columns']['body']['attributes']['class']->value(),
      'A column with no configured prop is untouched.'
    );

    foreach ([
      'preprocess_views_ui_style_plugin_table' => 'preprocessViewsUiStylePluginTable',
      'preprocess_views_view_table' => 'preprocessViewsViewTable',
    ] as $hook => $method) {
      $this->assertContains(
        'neo: ' . NeoThemeHooks::class . '::' . $method,
        $this->hookImplementations($hook)
      );
      $this->assertFalse(function_exists('neo_' . $hook));
    }
  }

  /**
   * The `.module` holds three globals and no hook.
   *
   * Acceptance criterion: `neo.module` contains no hook implementation, and
   * still defines the table props shim and both debug helpers.
   *
   * `ksm()` and `kint()` are pinned to that file by the packages' own
   * pre-commit hook, which exempts `neo.module` by path from its debug-call
   * check — so they could not live under `src/` even if the style argument went
   * the other way.
   */
  public function testNeoModuleContainsNoHookImplementationAndStillDefinesTheShimAndDebugHelpers(): void {
    $module = (string) file_get_contents($this->packageRoot() . '/neo.module');
    preg_match_all('/function (\w+)\s*\(/', $module, $matches);
    $this->assertSame(
      ['neo_table_props', 'ksm', 'kint'],
      $matches[1],
      'The `.module` declares the shim and the two debug helpers, and nothing else.'
    );

    foreach ([
      'theme',
      'theme_suggestions_views_view',
      'preprocess_select',
      'preprocess_input__textfield',
      'preprocess_views_ui_style_plugin_table',
      'preprocess_views_view_table',
    ] as $hook) {
      $this->assertFalse(function_exists('neo_' . $hook), 'neo_' . $hook . '() no longer exists.');
      $implementations = $this->hookImplementations($hook);
      $this->assertNotContains(
        'neo: neo_' . $hook,
        $implementations,
        'No procedural implementation of ' . $hook . ' is registered.'
      );
      $this->assertNotEmpty(
        preg_grep('/^neo: ' . preg_quote(NeoThemeHooks::class, '/') . '::/', $implementations),
        $hook . ' is implemented by the class.'
      );
    }
  }

  /**
   * The theme hook definitions, as the hook itself answers them.
   *
   * @return array
   *   The `hook_theme()` return value.
   */
  protected function themeHooks(): array {
    return $this->container->get('module_handler')
      ->invoke('neo', 'theme', [[], 'module', 'neo', $this->packageRoot()]);
  }

  /**
   * Invokes one of the module's preprocess hooks the way the theme layer does.
   *
   * @param string $hook
   *   The theme hook, without the `preprocess_` prefix.
   * @param array $variables
   *   The variables, altered in place.
   */
  protected function invokePreprocess(string $hook, array &$variables): void {
    $this->container->get('module_handler')
      ->invoke('neo', 'preprocess_' . $hook, [&$variables, $hook, []]);
  }

  /**
   * A view answering one style plugin id.
   *
   * @param string $pluginId
   *   The style plugin id.
   *
   * @return \Drupal\views\ViewExecutable
   *   The view.
   */
  protected function viewWithStyle(string $pluginId): ViewExecutable {
    $style = $this->createMock(StylePluginBase::class);
    $style->method('getPluginId')->willReturn($pluginId);
    $view = $this->createMock(ViewExecutable::class);
    $view->method('getStyle')->willReturn($style);
    return $view;
  }

  /**
   * The package root.
   *
   * @return string
   *   The absolute path.
   */
  protected function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Every source file in this package, tests excluded.
   *
   * @return list<string>
   *   Absolute paths.
   */
  protected function packageSources(): array {
    $root = $this->packageRoot();
    $extensions = ['php', 'module', 'inc', 'install', 'theme', 'engine', 'profile'];
    $files = [];
    $directories = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator($directories, static function ($current) use ($root) {
      $skip = [$root . '/tests', $root . '/node_modules', $root . '/dist', $root . '/vendor'];
      return !in_array($current->getPathname(), $skip, TRUE);
    });
    foreach (new \RecursiveIteratorIterator($filter) as $file) {
      if (in_array($file->getExtension(), $extensions, TRUE)) {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * The implementations the hook system resolved for a hook, in order.
   *
   * @param string $hook
   *   The hook name, without the `hook_` prefix.
   *
   * @return string[]
   *   One `module: identifier` string per implementation, where the identifier
   *   is `Class::method` for a class-based implementation and the function name
   *   for a procedural one.
   */
  protected function hookImplementations(string $hook): array {
    $implementations = [];
    $this->container->get('module_handler')->invokeAllWith(
      $hook,
      static function (callable $implementation, string $module) use (&$implementations): void {
        if (is_array($implementation)) {
          $identifier = get_class($implementation[0]) . '::' . $implementation[1];
        }
        elseif (is_string($implementation)) {
          $identifier = $implementation;
        }
        else {
          $identifier = get_debug_type($implementation);
        }
        $implementations[] = $module . ': ' . $identifier;
      }
    );
    return $implementations;
  }

}
