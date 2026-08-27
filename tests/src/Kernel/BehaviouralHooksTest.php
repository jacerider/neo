<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo\Helpers\TableProps;
use Drupal\neo\Hook\NeoHooks;
use Drupal\neo\NeoPreRender;
use Drupal\neo\NeoProcess;
use Drupal\neo\Plugin\views\area\NeoResult;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module's five behavioural hooks, driven through the hook system.
 *
 * The library alter, the entity view alter, the element info alter, the config
 * schema alter and the views area alter are methods on
 * `Drupal\neo\Hook\NeoHooks`. None of their bodies changed on the way over —
 * none of them reaches a container service, so the class takes no constructor
 * arguments at all — so what is at risk here is not a decision but a
 * registration: a wrong hook name, a class in a namespace nothing scans, or a
 * missing attribute produces a class that reads correctly and is never called.
 * A method nobody invokes is not a hook implementation.
 *
 * So every assertion below goes through the module handler rather than through
 * the object, and the first test names the implementation the hook system
 * actually resolved for each of the five — because "the hook still answers" and
 * "the class is what answers it" are two different statements, and only the
 * second one fails when a hook silently stays procedural.
 *
 * Three of the five have no observable surface on this site — no entity view
 * display uses the `neo_entity_attribute` formatter, no view configures a table
 * prop, and the schema alter's effect only shows in a config object nobody has
 * written — so they are covered here or nowhere. Each is driven with a
 * constructed argument, which is what the hook would have been handed in
 * production: the definition array the typed config manager alters, the plugin
 * definition array the views handler manager alters, and a real user entity
 * beside a display double naming the formatter.
 *
 * `views` is deliberately not installed. Both views-shaped hooks work on an
 * array they are handed, so installing the module would buy nothing this test
 * asserts and would put the whole of Views into a fixture about five array
 * alterations.
 */
#[Group('neo')]
final class BehaviouralHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `user` is here for a second reason —
   * the entity view alter needs a real content entity carrying both a
   * single-valued field and a multi-valued one, and a user account is the
   * cheapest one this module list already has.
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
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * All five behavioural hooks answer from the class, and no function is left.
   *
   * Acceptance criterion: it registers all five behavioural hooks from the
   * class rather than from the `.module`, and none of the five functions
   * remains.
   *
   * Both halves are needed. A class-based implementation registered beside a
   * surviving global would run the same body twice, and a global that stayed
   * behind would still be found by the collector for as long as `neo.module` is
   * scanned at all.
   */
  public function testRegistersAllFiveBehaviouralHooksFromTheClass(): void {
    $expected = [
      'library_info_alter' => 'libraryInfoAlter',
      'entity_view_alter' => 'entityViewAlter',
      'element_info_alter' => 'elementInfoAlter',
      'config_schema_info_alter' => 'configSchemaInfoAlter',
      'views_plugins_area_alter' => 'viewsPluginsAreaAlter',
    ];
    foreach ($expected as $hook => $method) {
      $this->assertContains(
        'neo: ' . NeoHooks::class . '::' . $method,
        $this->hookImplementations($hook),
        sprintf('hook_%s is implemented by the class.', $hook)
      );
    }

    foreach (array_keys($expected) as $hook) {
      $this->assertFalse(
        function_exists('neo_' . $hook),
        sprintf('neo_%s() no longer exists.', $hook)
      );
    }
  }

  /**
   * Core's autocomplete library gains Neo's, and nothing else does.
   *
   * Acceptance criterion: it appends the Neo autocomplete library to core's
   * autocomplete library and leaves every other extension alone.
   */
  public function testAppendsTheNeoAutocompleteLibraryToCores(): void {
    $moduleHandler = $this->container->get('module_handler');

    $libraries = [
      'drupal.autocomplete' => ['dependencies' => ['core/once']],
      'drupal.dialog' => ['dependencies' => ['core/jquery']],
    ];
    $extension = 'core';
    $moduleHandler->alter('library_info', $libraries, $extension);

    // Appended, so whatever core declared still comes first.
    $this->assertSame(['core/once', 'neo/autocomplete'], $libraries['drupal.autocomplete']['dependencies']);
    // The other library in the same extension is not touched.
    $this->assertSame(['core/jquery'], $libraries['drupal.dialog']['dependencies']);

    // A library of the same name in any other extension is left alone, which is
    // the whole of the `$extension == 'core'` guard.
    $other = [
      'drupal.autocomplete' => ['dependencies' => ['core/once']],
    ];
    $untouched = $other;
    $extension = 'neo';
    $moduleHandler->alter('library_info', $other, $extension);
    $this->assertSame($untouched, $other);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo: ' . NeoHooks::class . '::libraryInfoAlter',
      $this->hookImplementations('library_info_alter')
    );
  }

  /**
   * The formatter's components become classes or data attributes on the build.
   *
   * Acceptance criterion: it adds the configured class or data attribute to an
   * entity build for a component using the Neo entity attribute formatter, and
   * leaves a build with no such component untouched.
   *
   * No entity view display on this site uses that formatter, so this is the
   * only place the branch runs. The display is a double because the hook reads
   * one thing off it — the components array — while the entity is real, so the
   * field reads, the cardinality check and the `value`/`target_id` fallback are
   * all the real ones.
   */
  public function testAddsTheConfiguredClassOrDataAttributeToAnEntityBuild(): void {
    $moduleHandler = $this->container->get('module_handler');
    $account = User::create([
      'name' => 'Alpha Beta',
      'roles' => ['editor'],
    ]);

    // A single-valued field, read through `value`, stamped as a class.
    $build = [];
    $display = $this->displayWith([
      'name' => [
        'type' => 'neo_entity_attribute',
        'settings' => ['type' => 'class', 'prefix' => 'is-', 'suffix' => ''],
      ],
    ]);
    $moduleHandler->alter('entity_view', $build, $account, $display);
    $this->assertSame(['is-alpha-beta'], $build['#attributes']['class']);

    // A multi-valued reference field, read through the `target_id` fallback.
    $build = [];
    $display = $this->displayWith([
      'roles' => [
        'type' => 'neo_entity_attribute',
        'settings' => ['type' => 'class', 'prefix' => 'role-', 'suffix' => ''],
      ],
    ]);
    $moduleHandler->alter('entity_view', $build, $account, $display);
    $this->assertSame(['role-editor'], $build['#attributes']['class']);

    // The data branch names the attribute after the field, with `field_`
    // stripped, and takes the value itself because the field's cardinality
    // is one.
    $build = [];
    $display = $this->displayWith([
      'name' => [
        'type' => 'neo_entity_attribute',
        'settings' => ['type' => 'data', 'prefix' => 'neo-', 'suffix' => '-attr'],
      ],
    ]);
    $moduleHandler->alter('entity_view', $build, $account, $display);
    $this->assertSame('Alpha Beta', $build['#attributes']['data-neo-name-attr']);
    $this->assertArrayNotHasKey('class', $build['#attributes']);

    // A display carrying no component of that formatter leaves the build
    // exactly as it arrived.
    $build = ['#attributes' => ['class' => ['already-here']]];
    $display = $this->displayWith([
      'name' => ['type' => 'string', 'settings' => []],
    ]);
    $moduleHandler->alter('entity_view', $build, $account, $display);
    $this->assertSame(['#attributes' => ['class' => ['already-here']]], $build);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo: ' . NeoHooks::class . '::entityViewAlter',
      $this->hookImplementations('entity_view_alter')
    );
  }

  /**
   * The element callbacks land in the positions they always landed in.
   *
   * Acceptance criterion: it adds the details process, view pre-render, entity
   * autocomplete process and disable pre-render callbacks in the same positions
   * they held, and clears the entity autocomplete maxlength.
   *
   * Position is the assertion, not membership: the details process is unshifted
   * and the entity-autocomplete process prepended so they run before whatever
   * core declared, while the view pre-render is appended so it runs after. Each
   * element below is seeded with a callback of its own so a body that appended
   * where it used to prepend fails here rather than in a browser.
   */
  public function testAddsTheElementCallbacksInTheSamePositionsAndClearsTheMaxlength(): void {
    $existingProcess = ['Drupal\Core\Render\Element\Details', 'processDetails'];
    $existingPreRender = ['Drupal\Core\Render\Element\RenderElementBase', 'preRenderGroup'];

    $info = [
      'details' => ['#process' => [$existingProcess]],
      'view' => ['#pre_render' => [$existingPreRender]],
      'entity_autocomplete' => [
        '#process' => [$existingProcess],
        '#maxlength' => 128,
      ],
      'link' => ['#pre_render' => [$existingPreRender]],
      'button' => ['#pre_render' => [$existingPreRender]],
      'submit' => [],
      'neo_modal_link' => ['#pre_render' => [$existingPreRender]],
    ];
    $this->container->get('module_handler')->alter('element_info', $info);

    // Unshifted: Neo's details process runs first.
    $this->assertSame(
      [[NeoProcess::class, 'details'], $existingProcess],
      $info['details']['#process']
    );
    // Appended: Neo's view pre-render runs last.
    $this->assertSame(
      [$existingPreRender, [NeoPreRender::class, 'view']],
      $info['view']['#pre_render']
    );
    // Prepended: Neo's entity-autocomplete process runs first.
    $this->assertSame(
      [[NeoProcess::class, 'entityAutocomplete'], $existingProcess],
      $info['entity_autocomplete']['#process']
    );
    // The disable pre-render is prepended to all four types, including the one
    // that declared no pre-render list at all.
    foreach (['link', 'button', 'submit', 'neo_modal_link'] as $type) {
      $expected = $type === 'submit'
        ? [[NeoPreRender::class, 'disable']]
        : [[NeoPreRender::class, 'disable'], $existingPreRender];
      $this->assertSame($expected, $info[$type]['#pre_render'], $type . ' gained the disable pre-render first.');
    }
    // The maxlength is cleared rather than removed, so a form element that
    // reads the key still finds it.
    $this->assertArrayHasKey('#maxlength', $info['entity_autocomplete']);
    $this->assertNull($info['entity_autocomplete']['#maxlength']);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo: ' . NeoHooks::class . '::elementInfoAlter',
      $this->hookImplementations('element_info_alter')
    );
  }

  /**
   * The Views table style schema gains one entry per table prop.
   *
   * Acceptance criterion: it extends the Views table style schema with one
   * mapping entry per table prop.
   *
   * Driven with the definition array the typed config manager would hand it,
   * because no view on this site configures a table prop and the alter's effect
   * is otherwise only visible in a config object nobody has written.
   */
  public function testExtendsTheViewsTableStyleSchemaWithOneEntryPerTableProp(): void {
    $definitions = [
      'views.style.table' => [
        'type' => 'mapping',
        'mapping' => [
          'info' => [
            'type' => 'sequence',
            'sequence' => [
              'type' => 'mapping',
              'mapping' => [
                'sortable' => ['type' => 'boolean'],
              ],
            ],
          ],
        ],
      ],
      'views.style.default' => ['type' => 'mapping', 'mapping' => []],
    ];
    $this->container->get('module_handler')->alter('config_schema_info', $definitions);

    $mapping = $definitions['views.style.table']['mapping']['info']['sequence']['mapping'];
    $props = TableProps::get();
    foreach ($props as $key => $prop) {
      $this->assertArrayHasKey($key, $mapping, $key . ' is declared in the schema.');
      $this->assertSame('string', $mapping[$key]['type']);
      $this->assertSame((string) $prop['label'], (string) $mapping[$key]['label']);
    }
    // One entry per prop and nothing else: what the style already declared
    // survives, and no key beyond the props is added.
    $this->assertSame(
      array_keys($props),
      array_values(array_diff(array_keys($mapping), ['sortable']))
    );
    $this->assertArrayHasKey('sortable', $mapping);
    // Another style's definition is not touched.
    $this->assertSame([], $definitions['views.style.default']['mapping']);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo: ' . NeoHooks::class . '::configSchemaInfoAlter',
      $this->hookImplementations('config_schema_info_alter')
    );
  }

  /**
   * The `result` area plugin is pointed at Neo's own class.
   *
   * Acceptance criterion: it points the `result` views area plugin at Neo's own
   * class.
   */
  public function testPointsTheResultViewsAreaPluginAtNeosOwnClass(): void {
    $plugins = [
      'result' => [
        'id' => 'result',
        'class' => 'Drupal\views\Plugin\views\area\Result',
        'provider' => 'views',
      ],
      'text' => [
        'id' => 'text',
        'class' => 'Drupal\views\Plugin\views\area\Text',
        'provider' => 'views',
      ],
    ];
    $this->container->get('module_handler')->alter('views_plugins_area', $plugins);

    $this->assertSame(NeoResult::class, $plugins['result']['class']);
    // Only the class moves: the rest of the definition, and every other area
    // plugin, is left as it was.
    $this->assertSame('result', $plugins['result']['id']);
    $this->assertSame('views', $plugins['result']['provider']);
    $this->assertSame('Drupal\views\Plugin\views\area\Text', $plugins['text']['class']);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo: ' . NeoHooks::class . '::viewsPluginsAreaAlter',
      $this->hookImplementations('views_plugins_area_alter')
    );
  }

  /**
   * A view display double answering one components array.
   *
   * @param array $components
   *   The components, keyed by field name.
   *
   * @return \Drupal\Core\Entity\Display\EntityViewDisplayInterface
   *   The display.
   */
  protected function displayWith(array $components): EntityViewDisplayInterface {
    $display = $this->createMock(EntityViewDisplayInterface::class);
    $display->method('getComponents')->willReturn($components);
    return $display;
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
