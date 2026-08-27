<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_menu_link\Hook\NeoMenuLinkHooks;
use Drupal\neo_settings\SettingsRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The sub-module's entity, menu and schema hooks, driven through the hooks.
 *
 * Four of `neo_menu_link.module`'s nine hook implementations are methods on
 * `Drupal\neo_menu_link\Hook\NeoMenuLinkHooks`: the base field info alter that
 * swaps the menu link's link field onto Neo's widget, the discovered links
 * alter that points core's logout link at Neo's class, the menu preprocessor
 * and the recursive helper behind it, and the config schema alter that extends
 * core's static menu link overrides. The four form alters moved onto
 * `Drupal\neo_menu_link\Hook\NeoMenuLinkFormHooks` in the ticket after this
 * one, which is what emptied the `.module` and let the module set its hook
 * scan skip parameter; `MenuLinkFormHooksTest` owns that half.
 *
 * The bodies moved unchanged, with one substitution: the base field alter's
 * `\Drupal::service('neo_menu_link.settings')` became a constructor argument.
 * Nothing any of them decides moved with them — not which widget the link field
 * gets, not the order the menu preprocessor unsets its options in, not the
 * wrapper class it derives, not which keys reach the overrides schema.
 *
 * So what is at risk here is not a decision but a registration: a wrong hook
 * name, a class in a namespace nothing scans, or a missing attribute produces a
 * class that reads correctly and is never called. A method nobody invokes is
 * not a hook implementation. Every assertion below therefore goes through the
 * module handler rather than through the object, and every behavioural test
 * closes by naming the implementation the hook system actually resolved —
 * because "the hook still answers" and "the class is what answers it" are two
 * different statements, and only the second one fails when a hook silently
 * stays procedural.
 *
 * `menu_link_content` is installed so the entity type the base field alter
 * guards on is the real one, and `neo_icon` because the menu preprocessor
 * builds a real `IconElement` and renders it. Neither is a fixture for its own
 * sake: the guard and the icon branch are the two halves of this class that a
 * double would let through.
 */
#[Group('neo')]
final class MenuLinkHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `neo_settings` carries the repository
   * the hook class takes as a constructor argument, `link` and
   * `menu_link_content` give the base field alter a real entity type to guard
   * on, and `neo_icon` gives the menu preprocessor the repository and renderer
   * its `IconElement` reaches for.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'neo',
    'neo_settings',
    'neo_icon',
    'link',
    'menu_link_content',
    'neo_menu_link',
  ];

  /**
   * {@inheritdoc}
   *
   * The settings are written straight to the config storage rather than saved
   * through the config factory, and they are written before anything reads
   * them. The hook class takes the settings repository as a constructor
   * argument and the repository resolves its plugin's values once, in its own
   * constructor — so a value saved after the first hook invocation, which is
   * the schema alter the config installer itself triggers, would never be seen.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('config.storage')->write('neo_menu_link.settings', [
      'icon_libraries' => ['solid', 'regular'],
      'target' => TRUE,
      'class' => TRUE,
      'class_list' => "is-primary|Primary\nis-ghost|Ghost",
      'spacers' => FALSE,
    ]);
    $this->container->get('config.factory')->reset('neo_menu_link.settings');
  }

  /**
   * All four hooks answer from the class, and no function is left.
   *
   * Acceptance criterion: it registers all four hooks from the class rather
   * than from the `.module`, and none of the four functions remains.
   *
   * Both halves are needed. A class-based implementation registered beside a
   * surviving global would run the same body twice, and a global that stayed
   * behind would still be found by the collector, because this module's
   * procedural scan is deliberately still on — five form alters are still in
   * the `.module` after this ticket.
   */
  public function testRegistersAllFourHooksFromTheClass(): void {
    $expected = [
      'entity_base_field_info_alter' => 'entityBaseFieldInfoAlter',
      'menu_links_discovered_alter' => 'menuLinksDiscoveredAlter',
      'preprocess_menu' => 'preprocessMenu',
      'config_schema_info_alter' => 'configSchemaInfoAlter',
    ];
    foreach ($expected as $hook => $method) {
      $implementations = $this->hookImplementations($hook);
      $this->assertContains(
        'neo_menu_link: ' . NeoMenuLinkHooks::class . '::' . $method,
        $implementations,
        sprintf('hook_%s is implemented by the class.', $hook)
      );
      $this->assertNotContains(
        'neo_menu_link: neo_menu_link_' . $hook,
        $implementations,
        sprintf('No procedural implementation of %s is registered.', $hook)
      );
    }

    foreach (array_keys($expected) as $hook) {
      $this->assertFalse(
        function_exists('neo_menu_link_' . $hook),
        sprintf('neo_menu_link_%s() no longer exists.', $hook)
      );
    }
    // The recursive helper went with the preprocessor it serves; nothing
    // outside that body ever called it.
    $this->assertFalse(
      function_exists('neo_menu_link_preprocess_menu_items'),
      'neo_menu_link_preprocess_menu_items() no longer exists.'
    );

    // The form alters have since moved onto NeoMenuLinkFormHooks and the
    // `.module` is gone with them, which is what lets the module set its hook
    // scan skip parameter. MenuLinkFormHooksTest owns that half.
    $this->assertFalse(
      function_exists('neo_menu_link_form_node_form_alter'),
      'The form alters are no longer procedural either.'
    );
  }

  /**
   * The link field takes Neo's widget with the module's own settings in it.
   *
   * Acceptance criterion: it sets the menu link entity's link field to Neo's
   * link widget with the icon libraries, target, class and class list the
   * module's settings hold.
   *
   * Driven with the field array and the real entity type definition the entity
   * field manager hands the alter, so the `menu_link_content` guard is
   * exercised against the entity type it names rather than a double.
   */
  public function testSetsTheLinkFieldToNeosLinkWidgetWithTheModulesSettings(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $moduleHandler = $this->container->get('module_handler');

    $fields = ['link' => BaseFieldDefinition::create('link')];
    $entityType = $entityTypeManager->getDefinition('menu_link_content');
    $moduleHandler->alter('entity_base_field_info', $fields, $entityType);

    $options = $fields['link']->getDisplayOptions('form');
    $this->assertSame('neo_link', $options['type']);
    $this->assertSame(-2, $options['weight']);
    // The four settings values, as the settings actually hold them.
    $this->assertSame(['solid', 'regular'], $options['settings']['icon_libraries']);
    $this->assertTrue($options['settings']['target']);
    $this->assertTrue($options['settings']['class']);
    $this->assertSame(
      ['is-primary' => 'Primary', 'is-ghost' => 'Ghost'],
      $options['settings']['class_list']
    );
    // And they are the repository's answer rather than a coincidence.
    $settings = $this->container->get('neo_menu_link.settings')->getActive();
    $this->assertSame($settings->getValue('icon_libraries'), $options['settings']['icon_libraries']);
    $this->assertSame(!empty($settings->getValue('target')), $options['settings']['target']);
    $this->assertSame(!empty($settings->getValue('class')), $options['settings']['class']);
    $this->assertSame($settings->getClassList(), $options['settings']['class_list']);

    // Any other entity type is left exactly as it arrived, which is the whole
    // of the guard.
    $other = ['link' => BaseFieldDefinition::create('link')];
    $otherType = $entityTypeManager->getDefinition('user');
    $moduleHandler->alter('entity_base_field_info', $other, $otherType);
    $this->assertNull($other['link']->getDisplayOptions('form'));

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkHooks::class . '::entityBaseFieldInfoAlter',
      $this->hookImplementations('entity_base_field_info_alter')
    );
  }

  /**
   * Core's logout link is pointed at Neo's login/logout menu link class.
   *
   * Acceptance criterion: it points the discovered logout link at Neo's
   * login/logout menu link class.
   */
  public function testPointsTheDiscoveredLogoutLinkAtNeosClass(): void {
    $links = [
      'user.logout' => [
        'title' => 'Log out',
        'route_name' => 'user.logout',
        'class' => 'Drupal\Core\Menu\MenuLinkDefault',
      ],
      'user.page' => [
        'title' => 'My account',
        'route_name' => 'user.page',
        'class' => 'Drupal\Core\Menu\MenuLinkDefault',
      ],
    ];
    $this->container->get('module_handler')->alter('menu_links_discovered', $links);

    $this->assertSame(
      'Drupal\neo_menu_link\NeoMenuLinkLoginLogoutMenuLink',
      $links['user.logout']['class']
    );
    // Only the class moves: the rest of that definition, and every other
    // discovered link, is left as it was.
    $this->assertSame('Log out', $links['user.logout']['title']);
    $this->assertSame('user.logout', $links['user.logout']['route_name']);
    $this->assertSame('Drupal\Core\Menu\MenuLinkDefault', $links['user.page']['class']);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkHooks::class . '::menuLinksDiscoveredAlter',
      $this->hookImplementations('menu_links_discovered_alter')
    );
  }

  /**
   * A menu row's three options become an icon, classes and a target.
   *
   * Acceptance criterion: it turns a menu row's icon option into an icon
   * element in the title, its class option into wrapper and link classes, and
   * its target option into a target attribute, recursing into children.
   *
   * The unsetting order is part of the body and is asserted: each `data-`
   * option is removed from the url's options after it has been read, while
   * `data-icon-position` — which the icon branch reads but nothing unsets —
   * survives. The row carrying no options at all is the control: it proves the
   * preprocessor's guards, not merely that it ran.
   */
  public function testTurnsTheMenuRowOptionsIntoAnIconClassesAndTarget(): void {
    $variables = [
      'items' => [
        'parent' => [
          'title' => 'Parent',
          'url' => Url::fromUri('https://example.com/parent', [
            'attributes' => [
              'class' => ['already-here'],
              'data-icon' => 'star',
              'data-icon-position' => 'after',
              'data-class' => 'is-primary is-large',
              'data-target' => '_blank',
            ],
          ]),
          'attributes' => new Attribute(['class' => ['menu-item']]),
          'below' => [
            'child' => [
              'title' => 'Child',
              'url' => Url::fromUri('https://example.com/child', [
                'attributes' => [
                  'data-class' => 'is-child',
                  'data-target' => '_self',
                ],
              ]),
              'attributes' => new Attribute(),
              'below' => [],
            ],
          ],
        ],
        'plain' => [
          'title' => 'Plain',
          'url' => Url::fromUri('https://example.com/plain', ['attributes' => []]),
          'attributes' => new Attribute(),
          'below' => [],
        ],
      ],
    ];
    $this->invokePreprocessMenu($variables);

    $parent = $variables['items']['parent'];
    $parentOptions = $parent['url']->getOptions();

    // The icon option became an icon element: the title is no longer the plain
    // string it arrived as, and it still reads as the same text.
    $this->assertNotSame('Parent', $parent['title'], 'The title became an icon element.');
    $this->assertStringContainsString('Parent', (string) $parent['title']);
    // The position the icon branch read survives, because nothing unsets it.
    $this->assertSame('after', $parentOptions['attributes']['data-icon-position']);

    // The class option became link classes, merged onto what the url already
    // carried, and wrapper classes on the row itself.
    $this->assertSame(
      ['already-here', 'is-primary', 'is-large'],
      $parentOptions['attributes']['class']
    );
    $wrapperClasses = $parent['attributes']->toArray()['class'];
    $this->assertContains('menu-item', $wrapperClasses, 'What the row already had survives.');
    $this->assertContains('is-primary-wrapper', $wrapperClasses);
    $this->assertContains('is-large-wrapper', $wrapperClasses);

    // The target option became a real target attribute.
    $this->assertSame('_blank', $parentOptions['attributes']['target']);

    // All three `data-` options are unset once they have been read.
    $this->assertArrayNotHasKey('data-icon', $parentOptions['attributes']);
    $this->assertArrayNotHasKey('data-class', $parentOptions['attributes']);
    $this->assertArrayNotHasKey('data-target', $parentOptions['attributes']);

    // The recursion reaches the child, which is a different row with different
    // options and no icon of its own.
    $child = $variables['items']['parent']['below']['child'];
    $childOptions = $child['url']->getOptions();
    $this->assertSame('Child', $child['title'], 'A row with no icon keeps its title.');
    $this->assertSame(['is-child'], $childOptions['attributes']['class']);
    $this->assertSame('_self', $childOptions['attributes']['target']);
    $this->assertContains('is-child-wrapper', $child['attributes']->toArray()['class']);
    $this->assertArrayNotHasKey('data-class', $childOptions['attributes']);
    $this->assertArrayNotHasKey('data-target', $childOptions['attributes']);

    // A row carrying none of the three options is left exactly as it arrived.
    $plain = $variables['items']['plain'];
    $this->assertSame('Plain', $plain['title']);
    $this->assertSame([], $plain['url']->getOptions()['attributes']);
    $this->assertSame([], $plain['attributes']->toArray());

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkHooks::class . '::preprocessMenu',
      $this->hookImplementations('preprocess_menu')
    );
  }

  /**
   * The static menu link overrides schema gains the four option keys.
   *
   * Acceptance criterion: it extends the static menu link overrides schema with
   * the icon, target, class and title mapping.
   *
   * Driven with the definition array the typed config manager would hand it,
   * because the alter's effect is otherwise only visible inside a config object
   * this site has not written.
   */
  public function testExtendsTheStaticMenuLinkOverridesSchema(): void {
    $definitions = [
      'core.menu.static_menu_link_overrides' => [
        'type' => 'config_object',
        'mapping' => [
          'definitions' => [
            'type' => 'sequence',
            'sequence' => [
              'type' => 'mapping',
              'mapping' => [
                'menu_name' => ['type' => 'string', 'label' => 'Menu name'],
              ],
            ],
          ],
        ],
      ],
      'core.menu.static_menu_link_overrides.other' => ['type' => 'mapping', 'mapping' => []],
    ];
    $this->container->get('module_handler')->alter('config_schema_info', $definitions);

    $mapping = $definitions['core.menu.static_menu_link_overrides']['mapping']['definitions']['sequence']['mapping'];
    $this->assertArrayHasKey('menu_name', $mapping, 'What core declared survives.');
    $this->assertArrayHasKey('options', $mapping);
    $this->assertSame('mapping', $mapping['options']['type']);

    $attributes = $mapping['options']['mapping']['attributes'];
    $this->assertSame('mapping', $attributes['type']);
    $this->assertSame(
      ['data-icon', 'data-target', 'data-class', 'title'],
      array_keys($attributes['mapping']),
      'The four keys, in the order the body declares them.'
    );
    $this->assertSame(['type' => 'string', 'label' => 'Icon'], $attributes['mapping']['data-icon']);
    $this->assertSame(['type' => 'boolean', 'label' => 'Target'], $attributes['mapping']['data-target']);
    $this->assertSame(['type' => 'string', 'label' => 'Class'], $attributes['mapping']['data-class']);
    $this->assertSame(['type' => 'string', 'label' => 'Title'], $attributes['mapping']['title']);

    // No other definition is touched, which is the whole of the isset() guard.
    $this->assertSame([], $definitions['core.menu.static_menu_link_overrides.other']['mapping']);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkHooks::class . '::configSchemaInfoAlter',
      $this->hookImplementations('config_schema_info_alter')
    );
  }

  /**
   * The settings repository is a constructor argument, not a static reach.
   *
   * Acceptance criterion: no body in the class fetches the module's settings
   * repository statically.
   *
   * Asserted three ways, because each catches a different way of getting this
   * wrong: the constructor takes the repository's interface, the container
   * hands the class the module's own repository service rather than some other
   * one, and no line of the file reaches the container statically at all.
   */
  public function testTakesTheSettingsRepositoryAsConstructorArgument(): void {
    $file = $this->packageRoot() . '/modules/neo_menu_link/src/Hook/NeoMenuLinkHooks.php';
    $this->assertFileExists($file, 'The hook class is on disk.');

    $constructor = (new \ReflectionClass(NeoMenuLinkHooks::class))->getConstructor();
    $this->assertNotNull($constructor, 'The class declares a constructor.');
    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $types[] = (string) $parameter->getType();
    }
    $this->assertContains(
      SettingsRepositoryInterface::class,
      $types,
      'The settings repository is a constructor parameter.'
    );

    // The container hands it this module's own repository, not another one.
    $instance = $this->container->get(NeoMenuLinkHooks::class);
    $injected = NULL;
    foreach ((new \ReflectionClass($instance))->getProperties() as $property) {
      $value = $property->getValue($instance);
      if ($value instanceof SettingsRepositoryInterface) {
        $injected = $value;
      }
    }
    $this->assertSame(
      $this->container->get('neo_menu_link.settings'),
      $injected,
      'The injected repository is `neo_menu_link.settings`.'
    );

    // And no body reaches the container statically, which is what the
    // constructor argument replaced. The comments are stripped first, because
    // the class docblock names the static call it replaced.
    $source = (string) php_strip_whitespace($file);
    $this->assertStringNotContainsString('\Drupal::', $source, 'No static container call is left.');
    $this->assertStringNotContainsString("'neo_menu_link.settings'", $source, 'No service id is fetched by name.');
  }

  /**
   * Invokes the module's menu preprocessor the way the theme layer does.
   *
   * The call is wrapped in a render context because the icon branch renders an
   * `IconElement`, and `Renderer::render()` refuses to run outside one — which
   * in production it never does, because a preprocessor runs inside the render
   * of the template it is preprocessing for.
   *
   * @param array $variables
   *   The menu variables, altered in place.
   */
  protected function invokePreprocessMenu(array &$variables): void {
    $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      function () use (&$variables): void {
        $this->container->get('module_handler')
          ->invoke('neo_menu_link', 'preprocess_menu', [&$variables, 'menu', []]);
      }
    );
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
