<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormState;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\neo_icon\IconElement;
use Drupal\neo_menu_link\Hook\NeoMenuLinkFormHooks;
use Drupal\neo_menu_link\NeoMenuLinkDefault;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * The sub-module's four form alters, driven through the hook system.
 *
 * The rest of `neo_menu_link.module`: the node form alter that adds the Neo
 * icon select to core's menu settings section and forks core's menu submit
 * handler, the menu link content form alter that adds the spacer checkbox and
 * prepends its validator, the menu link edit form alter that adds the icon,
 * target, class and description controls and appends its submit handler, and
 * the menu overview alter that styles each row and puts the row's icon into its
 * title. Four callbacks travel with them as static methods named by their
 * `Class::method` string, and `hook_module_implements_alter()` is deleted
 * rather than moved — core's collector refuses that hook on a class outright.
 *
 * The bodies move as they stand. Two substitutions: the three alters that
 * fetched `neo_menu_link.settings` statically take it as a constructor
 * argument, and the two that fetched the current user take that the same way.
 * Nothing any of them decides moved with them.
 *
 * So, as in the sibling class's test, what is at risk is a registration rather
 * than a decision — and one registration more than that. The deleted
 * implements-alter did exactly one thing: push this module's node form alter to
 * the end of the list. An ordering attribute on the method states that
 * directly, and it is the only mechanism in this plan that is replaced by a
 * different mechanism rather than relocated. It is asserted twice below: once
 * against the build-time implementation list, and once against the runtime
 * listener list core actually assembles for a form alter, which is a different
 * code path and the one a node form really runs through.
 */
#[Group('neo')]
final class MenuLinkFormHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`. `neo_settings` carries the repository
   * the hook class takes as a constructor argument, `neo_icon` gives the menu
   * overview alter its icon element, `link` and `menu_link_content` give the
   * menu link forms a real entity, `node` gives the forked submit handler a
   * real node to hang a menu link off, and `menu_ui` is here for one reason
   * only: it is the other implementation of `form_node_form_alter` that this
   * module's own has to come after.
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
    'field',
    'text',
    'filter',
    'node',
    'menu_link_content',
    'menu_ui',
    'neo_menu_link',
  ];

  /**
   * {@inheritdoc}
   *
   * The settings are written straight to the config storage rather than saved
   * through the config factory, and before anything reads them — ahead of the
   * schema installs, because installing config fires the config schema alter,
   * which constructs the sibling hook class, which constructs the repository.
   * The repository resolves its plugin's values once, in its own constructor,
   * so a value written after that is never seen. `spacers` is on here, because
   * the menu link content form alter is a no-op without it.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('config.storage')->write('neo_menu_link.settings', [
      'icon_libraries' => ['solid', 'regular'],
      'target' => TRUE,
      'class' => TRUE,
      'class_list' => "is-primary|Primary\nis-ghost|Ghost",
      'spacers' => TRUE,
    ]);
    $this->container->get('config.factory')->reset('neo_menu_link.settings');

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'node', 'filter']);
  }

  /**
   * The four alters answer from the class, the node one last, and no global.
   *
   * Acceptance criterion: it registers the four form alters from the class,
   * ordered last for the node form, and `hook_module_implements_alter()` no
   * longer exists in the module.
   *
   * Ordering is asserted on both paths, because they are genuinely different
   * code. The build-time list is what `invokeAllWith()` iterates. The runtime
   * list is what `ModuleHandler::alter()` assembles when a node form is built,
   * which regroups every implementation of `form_alter`, `form_node_form_alter`
   * and `form_ID_alter` by module and only then applies the ordering rules —
   * so an attribute that satisfies the first list and not the second would be
   * an ordering that never happens on a real form.
   */
  public function testRegistersTheFourFormAltersOrderedLastAndDropsTheImplementsAlter(): void {
    $expected = [
      'form_node_form_alter' => 'formNodeFormAlter',
      'form_menu_link_content_menu_link_content_form_alter' => 'formMenuLinkContentMenuLinkContentFormAlter',
      'form_menu_link_edit_alter' => 'formMenuLinkEditAlter',
      'form_menu_edit_form_alter' => 'formMenuEditFormAlter',
    ];
    foreach ($expected as $hook => $method) {
      $implementations = $this->hookImplementations($hook);
      $this->assertContains(
        'neo_menu_link: ' . NeoMenuLinkFormHooks::class . '::' . $method,
        $implementations,
        sprintf('hook_%s is implemented by the class.', $hook)
      );
      $this->assertNotContains(
        'neo_menu_link: neo_menu_link_' . $hook,
        $implementations,
        sprintf('No procedural implementation of %s is registered.', $hook)
      );
      $this->assertFalse(
        function_exists('neo_menu_link_' . $hook),
        sprintf('neo_menu_link_%s() no longer exists.', $hook)
      );
    }

    // The node form alter runs last, which is the single instruction the
    // deleted implements-alter carried.
    $nodeFormAlters = $this->hookImplementations('form_node_form_alter');
    $this->assertContains(
      'menu_ui: Drupal\menu_ui\Hook\MenuUiHooks::formNodeFormAlter',
      $nodeFormAlters,
      'Core menu_ui is in the list, so "last" means something.'
    );
    $this->assertSame(
      'neo_menu_link: ' . NeoMenuLinkFormHooks::class . '::formNodeFormAlter',
      end($nodeFormAlters),
      'The node form alter is the last implementation.'
    );

    // The same, on the runtime path a real node form takes.
    $combined = $this->combinedAlterListeners(['form_alter', 'form_node_form_alter']);
    $this->assertContains(
      'Drupal\menu_ui\Hook\MenuUiHooks::formNodeFormAlter',
      $combined,
      'Core menu_ui is in the runtime listener list too.'
    );
    $this->assertSame(
      NeoMenuLinkFormHooks::class . '::formNodeFormAlter',
      end($combined),
      'The node form alter is the last runtime listener for a node form.'
    );

    // The hook core refuses on a class is gone rather than relocated, and so
    // is the last of the module's procedural code.
    $this->assertFalse(
      function_exists('neo_menu_link_module_implements_alter'),
      'neo_menu_link_module_implements_alter() no longer exists.'
    );
    $this->assertNotContains(
      'neo_menu_link',
      array_column(
        array_map(
          static fn (string $line): array => explode(': ', $line, 2),
          $this->hookImplementations('module_implements_alter')
        ),
        0
      ),
      'The module implements no module_implements_alter at all.'
    );
  }

  /**
   * The node form's menu section gains the icon select and the forked handler.
   *
   * Acceptance criterion: it adds the Neo icon select to the node form's menu
   * settings, seeded from the menu link the node already has, and replaces
   * core's menu submit handler with the module's own by the same string search.
   *
   * Driven with the form array `menu_ui` builds, because the alter reads the
   * menu link's entity id out of the value element `menu_ui` put there. The
   * seed is a real saved menu link with a real icon option on it. Both handler
   * strings the search covers are exercised — core's procedural global and the
   * method callable Drupal 11.4 registers instead — and each is asserted to
   * have been replaced *in place*, because appending would run the fork after
   * core had already saved the link.
   */
  public function testAddsTheIconSelectToTheNodeFormAndReplacesCoresMenuSubmitHandler(): void {
    $this->setCurrentUserPermissions(['use neo_menu_link']);

    $link = MenuLinkContent::create([
      'title' => 'Existing link',
      'menu_name' => 'main',
      'link' => [
        [
          'uri' => 'internal:/',
          'options' => ['attributes' => ['data-icon' => 'star']],
        ],
      ],
    ]);
    $link->save();

    $form = $this->nodeFormSkeleton((string) $link->id(), 'menu_ui_form_node_form_submit');
    $this->invokeAlter('form_node_form_alter', $form, 'article_node_form');

    $icon = $form['menu']['link']['options']['attributes']['data-icon'];
    $this->assertSame('neo_icon_select', $icon['#type']);
    $this->assertSame('star', $icon['#default_value'], 'Seeded from the link the node already has.');
    $this->assertSame(['solid', 'regular'], $icon['#libraries']);
    $this->assertTrue($icon['#access'], 'The permitted user sees it.');

    // The weights and tree flags the section needs to render the select.
    $this->assertSame(-2, $form['menu']['link']['title']['#weight']);
    $this->assertTrue($form['menu']['link']['options']['#tree']);
    $this->assertSame(-1, $form['menu']['link']['options']['#weight']);
    $this->assertTrue($form['menu']['link']['options']['attributes']['#tree']);

    // Core's handler is replaced where it stood, not appended after it.
    $this->assertSame(
      ['::submitForm', NeoMenuLinkFormHooks::class . '::menuUiFormNodeFormSubmit', '::save'],
      $form['actions']['submit']['#submit']
    );
    // Preview is skipped by name, and a non-submit action is not touched.
    $this->assertSame(['menu_ui_form_node_form_submit'], $form['actions']['preview']['#submit']);
    $this->assertArrayNotHasKey('#submit', $form['actions']['delete']);

    // Drupal 11.4 registers core's handler as a method callable instead, and
    // the same search finds and replaces that too.
    $oop = $this->nodeFormSkeleton((string) $link->id(), 'Drupal\menu_ui\Hook\MenuUiHooks:formNodeFormSubmit');
    $this->invokeAlter('form_node_form_alter', $oop, 'article_node_form');
    $this->assertSame(
      ['::submitForm', NeoMenuLinkFormHooks::class . '::menuUiFormNodeFormSubmit', '::save'],
      $oop['actions']['submit']['#submit']
    );

    // With no menu link yet, the select is still there and simply unseeded.
    $fresh = $this->nodeFormSkeleton(NULL, 'menu_ui_form_node_form_submit');
    $this->invokeAlter('form_node_form_alter', $fresh, 'article_node_form');
    $this->assertNull($fresh['menu']['link']['options']['attributes']['data-icon']['#default_value']);

    // A form with no menu section is left exactly as it arrived, which is the
    // whole of the guard.
    $other = ['actions' => ['submit' => ['#type' => 'submit', '#submit' => ['menu_ui_form_node_form_submit']]]];
    $untouched = $other;
    $this->invokeAlter('form_node_form_alter', $other, 'page_node_form');
    $this->assertSame($untouched, $other);

    // The current user is live: a user without the permission loses the
    // select, which is what proves the injected account is not a snapshot.
    $this->setCurrentUserPermissions([]);
    $denied = $this->nodeFormSkeleton((string) $link->id(), 'menu_ui_form_node_form_submit');
    $this->invokeAlter('form_node_form_alter', $denied, 'article_node_form');
    $this->assertFalse($denied['menu']['link']['options']['attributes']['data-icon']['#access']);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkFormHooks::class . '::formNodeFormAlter',
      $this->hookImplementations('form_node_form_alter')
    );
  }

  /**
   * The forked handler saves and deletes the node's menu link.
   *
   * Acceptance criterion: it saves and deletes a node's menu link through the
   * moved submit handler and node-save helper, which are static methods named
   * in the form rather than global functions.
   *
   * The handler is called the way the form calls it — as a static, by the
   * string the alter wrote into `#submit` — with a real node behind the form
   * object, so the link it creates points at that node.
   */
  public function testSavesAndDeletesTheNodesMenuLinkThroughTheStaticSubmitHandler(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node = Node::create(['type' => 'article', 'title' => 'About us', 'uid' => 0]);
    $node->save();

    $callable = NeoMenuLinkFormHooks::class . '::menuUiFormNodeFormSubmit';
    $this->assertIsCallable($callable, 'The string the alter writes into #submit is callable.');

    $formState = new FormState();
    $formState->setFormObject($this->nodeFormObject($node));
    $formState->setValue('menu', [
      'enabled' => 1,
      'entity_id' => NULL,
      'title' => '  About us  ',
      'description' => '  Hover me  ',
      'menu_parent' => 'main:menu_link_content:abc',
      'weight' => 5,
      'options' => ['attributes' => ['data-icon' => 'star', 'data-class' => '']],
    ]);
    $form = [];
    $callable($form, $formState);

    $links = $this->container->get('entity_type.manager')
      ->getStorage('menu_link_content')
      ->loadByProperties(['title' => 'About us']);
    $this->assertCount(1, $links, 'The handler created the menu link.');
    /** @var \Drupal\menu_link_content\MenuLinkContentInterface $created */
    $created = reset($links);
    $this->assertSame('About us', $created->getTitle(), 'The title is trimmed.');
    $this->assertSame('Hover me', $created->getDescription(), 'The description is trimmed.');
    $this->assertSame('main', $created->getMenuName(), 'menu_parent was decomposed.');
    $this->assertSame('menu_link_content:abc', $created->getParentId());
    $this->assertSame(5, $created->getWeight());
    $this->assertTrue($created->isEnabled());
    $value = $created->get('link')->first()->getValue();
    $this->assertSame('entity:node/' . $node->id(), $value['uri']);
    $this->assertSame(
      ['attributes' => ['data-icon' => 'star']],
      $value['options'],
      'The empty attribute was filtered out, the icon kept.'
    );

    // Unchecking the menu box deletes the link the node had.
    $id = $created->id();
    $deleteState = new FormState();
    $deleteState->setFormObject($this->nodeFormObject($node));
    $deleteState->setValue('menu', ['enabled' => 0, 'entity_id' => $id]);
    $form = [];
    $callable($form, $deleteState);
    $this->assertNull(MenuLinkContent::load($id), 'The menu link was deleted.');

    // Both are static methods on the class, and neither global survives.
    foreach (['menuUiFormNodeFormSubmit', 'menuUiNodeSave'] as $method) {
      $this->assertTrue(
        (new \ReflectionMethod(NeoMenuLinkFormHooks::class, $method))->isStatic(),
        sprintf('%s() is a static method.', $method)
      );
    }
    $this->assertFalse(function_exists('neo_menu_link_menu_ui_form_node_form_submit'));
    $this->assertFalse(function_exists('neo_menu_link_menu_ui_node_save'));
  }

  /**
   * The menu link content form gains the spacer, and its validator forces it.
   *
   * Acceptance criterion: it adds the spacer checkbox to the menu link content
   * form, relaxes the title and link required flags and prepends its validator,
   * which forces the spacer values on submit.
   *
   * The two halves belong in one test because they only make sense together:
   * the required flags are relaxed precisely so the prepended validator can
   * decide, and a validator that ran after core's would never get the chance.
   */
  public function testAddsTheSpacerCheckboxRelaxesTheRequiredFlagsAndPrependsItsValidator(): void {
    $entity = MenuLinkContent::create([
      'title' => 'A spacer',
      'menu_name' => 'main',
      'link' => [['uri' => 'internal:/', 'options' => ['spacer' => TRUE]]],
    ]);
    $entity->save();

    $form = $this->menuLinkContentFormSkeleton();
    $this->invokeAlter(
      'form_menu_link_content_menu_link_content_form_alter',
      $form,
      'menu_link_content_menu_link_content_form',
      $this->menuLinkContentFormObject($entity)
    );

    $this->assertSame('checkbox', $form['spacer']['#type']);
    $this->assertTrue($form['spacer']['#default_value'], 'Seeded from the link the entity already has.');
    $this->assertSame(-10, $form['spacer']['#weight']);
    $id = $form['spacer']['#id'];
    $this->assertStringStartsWith('neo-menu-link-spacer', $id);
    $this->assertSame($id, $form['spacer']['widget']['value']['#id']);

    // The two required flags are relaxed, because the validator decides.
    $this->assertFalse($form['title']['widget'][0]['value']['#required']);
    $this->assertFalse($form['link']['widget'][0]['uri']['#required']);

    // The four controls hide behind the checkbox.
    foreach (['title', 'link', 'expanded', 'description'] as $key) {
      $this->assertSame(
        ['invisible' => ['#' . $id => ['checked' => TRUE]]],
        $form[$key]['#states'],
        sprintf('%s hides when the spacer is checked.', $key)
      );
    }

    // The validator is prepended, ahead of whatever the form already had.
    $this->assertSame(
      [
        NeoMenuLinkFormHooks::class . '::formMenuLinkContentMenuLinkContentFormValidate',
        '::validateForm',
      ],
      $form['#validate']
    );
    $this->assertFalse(function_exists('neo_menu_link_form_menu_link_content_menu_link_content_form_validate'));

    // Checked, it forces the spacer's title, uri and option.
    $validator = NeoMenuLinkFormHooks::class . '::formMenuLinkContentMenuLinkContentFormValidate';
    $spacerState = new FormState();
    $spacerState->setValue('spacer', TRUE);
    $validator($form, $spacerState);
    $this->assertSame('Spacer', $spacerState->getValue(['title', 0, 'value']));
    $this->assertSame('#', $spacerState->getValue(['link', 0, 'uri']));
    $this->assertTrue($spacerState->getValue(['link', 0, 'options', 'spacer']));
    $this->assertSame([], $spacerState->getErrors());

    // Unchecked, it enforces the two requirements core is no longer enforcing.
    $emptyState = new FormState();
    $emptyState->setValue('spacer', FALSE);
    $validator($form, $emptyState);
    $this->assertSame(
      ['title][0][value', 'link][0][uri'],
      array_keys($emptyState->getErrors()),
      'Both relaxed fields are reported as required.'
    );

    // Unchecked and filled in, it says nothing at all.
    $filledState = new FormState();
    $filledState->setValue('spacer', FALSE);
    $filledState->setValue(['title', 0, 'value'], 'Real link');
    $filledState->setValue(['link', 0, 'uri'], 'internal:/');
    $validator($form, $filledState);
    $this->assertSame([], $filledState->getErrors());

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkFormHooks::class . '::formMenuLinkContentMenuLinkContentFormAlter',
      $this->hookImplementations('form_menu_link_content_menu_link_content_form_alter')
    );
  }

  /**
   * The menu link edit form gains four controls and writes them back.
   *
   * Acceptance criterion: it adds the icon select, target checkbox, class
   * control and description field to the menu link edit form and writes them
   * back through its appended submit handler.
   *
   * The link the form is built for is a real `NeoMenuLinkDefault` carrying real
   * options, because both the alter and the handler read those options out of
   * the form's build info rather than out of the form.
   */
  public function testAddsTheMenuLinkEditControlsAndWritesThemBackOnSubmit(): void {
    $this->setCurrentUserPermissions(['use neo_menu_link']);
    $link = $this->menuLinkPlugin([
      'data-icon' => 'star',
      'data-target' => '_blank',
      'data-class' => 'is-primary',
      'title' => 'Old hover',
    ]);

    $form = ['path' => ['link' => []], '#submit' => ['::submitForm']];
    $formState = new FormState();
    $formState->setBuildInfo(['args' => [$link]]);
    $this->invokeAlter('form_menu_link_edit_alter', $form, 'menu_link_edit', NULL, $formState);

    $controls = $form['path']['link'];
    $this->assertSame('neo_icon_select', $controls['data-icon']['#type']);
    $this->assertSame('star', $controls['data-icon']['#default_value']);
    $this->assertSame(['solid', 'regular'], $controls['data-icon']['#packages']);

    $this->assertSame('checkbox', $controls['data-target']['#type']);
    $this->assertSame('_blank', $controls['data-target']['#return_value']);
    $this->assertSame('_blank', $controls['data-target']['#default_value']);

    // The class list is configured, so the control is a select over it.
    $this->assertSame('select', $controls['data-class']['#type']);
    $this->assertSame(['is-primary' => 'Primary', 'is-ghost' => 'Ghost'], $controls['data-class']['#options']);
    $this->assertSame('is-primary', $controls['data-class']['#default_value']);

    $this->assertSame('textfield', $controls['title']['#type']);
    $this->assertSame('Old hover', $controls['title']['#default_value']);

    foreach (['data-icon', 'data-target', 'data-class', 'title'] as $key) {
      $this->assertTrue($controls[$key]['#access'], sprintf('%s is visible to a permitted user.', $key));
    }

    // The handler is appended, after whatever the form already had.
    $this->assertSame(
      ['::submitForm', NeoMenuLinkFormHooks::class . '::formMenuLinkEditAlterSubmit'],
      $form['#submit']
    );
    $this->assertFalse(function_exists('neo_menu_link_form_menu_link_edit_alter_submit'));

    // And it writes the four values back onto the link's options.
    $captured = [];
    $manager = $this->createMock(MenuLinkManagerInterface::class);
    $manager->method('updateDefinition')->willReturnCallback(
      static function (string $id, array $values) use (&$captured) {
        $captured[] = [$id, $values];
        return NULL;
      }
    );
    $this->container->set('plugin.manager.menu.link', $manager);

    $submitState = new FormState();
    $submitState->setBuildInfo(['args' => [$link]]);
    $submitState->setValues([
      'menu_link_id' => 'neo_menu_link.test',
      'title' => 'New hover',
      'data-icon' => 'bolt',
      'data-target' => 0,
      'data-class' => 'is-ghost',
    ]);
    $submit = NeoMenuLinkFormHooks::class . '::formMenuLinkEditAlterSubmit';
    $submit($form, $submitState);

    $this->assertCount(1, $captured, 'The handler updated the definition once.');
    [$id, $values] = $captured[0];
    $this->assertSame('neo_menu_link.test', $id);
    $this->assertSame(
      [
        'data-icon' => 'bolt',
        'data-target' => 0,
        'data-class' => 'is-ghost',
        'title' => 'New hover',
      ],
      $values['options']['attributes'],
      'The four submitted values replaced the four the link carried.'
    );

    // With no link id in the form there is nothing to write, and nothing is.
    $captured = [];
    $emptyState = new FormState();
    $emptyState->setBuildInfo(['args' => [$link]]);
    $emptyState->setValues(['menu_link_id' => '']);
    $submit($form, $emptyState);
    $this->assertSame([], $captured, 'No link id, no write.');

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkFormHooks::class . '::formMenuLinkEditAlter',
      $this->hookImplementations('form_menu_link_edit_alter')
    );
  }

  /**
   * Each menu overview row gets its icon in the title and its row styles.
   *
   * Acceptance criterion: it puts each menu overview row's icon into the row
   * title and applies the row styles.
   *
   * Both link kinds the body branches on are here — a `NeoMenuLinkDefault`,
   * which answers its options through `getOptions()`, and a menu link content
   * plugin, which carries them in its plugin definition — along with a row
   * whose link has no icon at all, which is the control.
   */
  public function testPutsEachMenuOverviewRowsIconIntoTheTitleAndAppliesTheRowStyles(): void {
    $entity = MenuLinkContent::create([
      'title' => 'Content link',
      'menu_name' => 'main',
      'link' => [
        [
          'uri' => 'internal:/',
          'options' => ['attributes' => ['data-icon' => 'bolt']],
        ],
      ],
    ]);
    $entity->save();
    $contentPlugin = $this->container->get('plugin.manager.menu.link')
      ->createInstance($entity->getPluginId());

    $form = [
      'links' => [
        'links' => [
          'menu_plugin_id:neo_menu_link.test' => [
            '#item' => $this->treeElement($this->menuLinkPlugin(['data-icon' => 'star'])),
            'title' => [['#theme' => 'indentation'], ['#title' => 'Default link']],
            'operations' => ['#type' => 'operations'],
          ],
          'menu_plugin_id:content' => [
            '#item' => $this->treeElement($contentPlugin),
            'title' => [['#theme' => 'indentation'], ['#title' => 'Content link']],
            'operations' => ['#type' => 'operations'],
          ],
          'menu_plugin_id:plain' => [
            '#item' => $this->treeElement($this->menuLinkPlugin([])),
            'title' => [['#theme' => 'indentation'], ['#title' => 'Plain link']],
            'operations' => ['#type' => 'operations'],
          ],
        ],
      ],
    ];
    $this->invokeAlter('form_menu_edit_form_alter', $form, 'menu_edit_form');

    // Every row is styled, icon or no icon.
    foreach (array_keys($form['links']['links']) as $key) {
      $this->assertSame('heading', $form['links']['links'][$key]['title']['#neo_style']);
      $this->assertSame('min', $form['links']['links'][$key]['operations']['#neo_size']);
    }

    // The default link's icon reached the row title.
    $default = $form['links']['links']['menu_plugin_id:neo_menu_link.test']['title'][1]['#title'];
    $this->assertInstanceOf(IconElement::class, $default);
    $this->assertSame('star', $this->iconIdOf($default));
    $this->assertSame('Default link', (string) $default->getText(FALSE));

    // So did the content link's, which the body reads from a different place.
    $content = $form['links']['links']['menu_plugin_id:content']['title'][1]['#title'];
    $this->assertInstanceOf(IconElement::class, $content);
    $this->assertSame('bolt', $this->iconIdOf($content));

    // A link with no icon keeps its plain title.
    $this->assertSame(
      'Plain link',
      $form['links']['links']['menu_plugin_id:plain']['title'][1]['#title']
    );

    // A menu with no rows returns before it reads anything, which is the guard.
    $empty = ['links' => ['links' => []]];
    $untouched = $empty;
    $this->invokeAlter('form_menu_edit_form_alter', $empty, 'menu_edit_form');
    $this->assertSame($untouched, $empty);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_menu_link: ' . NeoMenuLinkFormHooks::class . '::formMenuEditFormAlter',
      $this->hookImplementations('form_menu_edit_form_alter')
    );
  }

  /**
   * Neither class reaches the settings repository or the current user.
   *
   * Acceptance criterion: no body in either class fetches the module's settings
   * repository or the current user statically.
   *
   * Asserted on the constructor, on what the container actually injects, and on
   * the source of both classes, because each catches a different way of getting
   * it wrong: a parameter of the right type wired to the wrong service, or one
   * body out of four that kept its static call.
   */
  public function testTakesTheSettingsRepositoryAndCurrentUserAsConstructorArguments(): void {
    $constructor = (new \ReflectionClass(NeoMenuLinkFormHooks::class))->getConstructor();
    $this->assertNotNull($constructor, 'The class declares a constructor.');
    $types = array_map(
      static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
      $constructor->getParameters()
    );
    $this->assertContains(SettingsRepositoryInterface::class, $types);
    $this->assertContains(AccountInterface::class, $types);

    $instance = $this->container->get(NeoMenuLinkFormHooks::class);
    $injected = [];
    foreach ((new \ReflectionClass($instance))->getProperties() as $property) {
      $injected[] = $property->getValue($instance);
    }
    $this->assertContains(
      $this->container->get('neo_menu_link.settings'),
      $injected,
      'The injected repository is `neo_menu_link.settings`.'
    );
    $this->assertContains(
      $this->container->get('current_user'),
      $injected,
      'The injected account is `current_user`.'
    );

    // And no body in either class reaches for either of them by name. Comments
    // are stripped first, because the docblocks name what they replaced.
    $root = $this->packageRoot() . '/modules/neo_menu_link/src/Hook/';
    foreach (['NeoMenuLinkHooks.php', 'NeoMenuLinkFormHooks.php'] as $name) {
      $this->assertFileExists($root . $name);
      $source = (string) php_strip_whitespace($root . $name);
      $this->assertStringNotContainsString("'neo_menu_link.settings'", $source, $name);
      $this->assertStringNotContainsString('currentUser()', $source, $name);
      $this->assertStringNotContainsString("'current_user'", $source, $name);
    }
  }

  /**
   * The module skips the procedural scan and registers nothing procedural.
   *
   * Acceptance criterion: `neo_menu_link.skip_procedural_hook_scan` is declared
   * and the module registers no procedural hook implementation.
   *
   * The parameter is read from the services file rather than from the
   * container, because core removes every `*.skip_procedural_hook_scan`
   * parameter once the collector has consumed it. The half that matters is the
   * second one anyway: with the scan off, a function left behind in the
   * `.module` would be invisible rather than registered, so the assertion that
   * carries the criterion is that the module's whole hook list is class-based.
   */
  public function testDeclaresTheHookScanSkipAndRegistersNoProceduralImplementation(): void {
    $services = Yaml::parseFile(
      $this->packageRoot() . '/modules/neo_menu_link/neo_menu_link.services.yml'
    );
    $this->assertTrue(
      $services['parameters']['neo_menu_link.skip_procedural_hook_scan'] ?? FALSE,
      'The services file declares the hook scan skip parameter.'
    );

    $hookList = $this->container->get('keyvalue')->get('hook_data')->get('hook_list');
    $this->assertIsArray($hookList);
    $procedural = [];
    $classes = [];
    foreach ($hookList as $hook => $implementations) {
      foreach ($implementations as $identifier => $module) {
        if ($module !== 'neo_menu_link') {
          continue;
        }
        if (str_contains($identifier, '::')) {
          $classes[] = $hook . ' => ' . $identifier;
        }
        else {
          $procedural[] = $hook . ' => ' . $identifier;
        }
      }
    }
    $this->assertSame([], $procedural, 'The module registers nothing procedural.');
    $this->assertCount(8, $classes, 'All eight of the module\'s hooks are class-based.');
  }

  /**
   * The form array `menu_ui` hands the node form alter.
   *
   * @param string|null $entityId
   *   The menu link entity id `menu_ui` writes into the form, if the node has
   *   a menu link already.
   * @param string $coreHandler
   *   The submit handler core registered, which the alter replaces.
   *
   * @return array
   *   The form array.
   */
  protected function nodeFormSkeleton(?string $entityId, string $coreHandler): array {
    return [
      'menu' => [
        '#type' => 'details',
        'link' => [
          'id' => ['#type' => 'value', '#value' => ''],
          'entity_id' => ['#type' => 'value', '#value' => $entityId],
          'title' => ['#type' => 'textfield'],
        ],
      ],
      'actions' => [
        'submit' => [
          '#type' => 'submit',
          '#submit' => ['::submitForm', $coreHandler, '::save'],
        ],
        'preview' => [
          '#type' => 'submit',
          '#submit' => ['menu_ui_form_node_form_submit'],
        ],
        'delete' => ['#type' => 'link'],
      ],
    ];
  }

  /**
   * The form array the menu link content form alter reads.
   *
   * @return array
   *   The form array, with the two required flags core set still on.
   */
  protected function menuLinkContentFormSkeleton(): array {
    return [
      'title' => [
        'widget' => [
          0 => [
            'value' => [
              '#type' => 'textfield',
              '#title' => 'Menu link title',
              '#required' => TRUE,
              '#parents' => ['title', 0, 'value'],
            ],
          ],
        ],
      ],
      'link' => [
        'widget' => [
          0 => [
            'uri' => [
              '#type' => 'entity_autocomplete',
              '#title' => 'Link',
              '#required' => TRUE,
              '#parents' => ['link', 0, 'uri'],
            ],
          ],
        ],
      ],
      'expanded' => ['#type' => 'checkbox'],
      'description' => ['#type' => 'textfield'],
      '#validate' => ['::validateForm'],
    ];
  }

  /**
   * A menu link plugin of Neo's own class, carrying the given attributes.
   *
   * @param array $attributes
   *   The link's `options.attributes`.
   *
   * @return \Drupal\neo_menu_link\NeoMenuLinkDefault
   *   The plugin instance.
   */
  protected function menuLinkPlugin(array $attributes): NeoMenuLinkDefault {
    return new NeoMenuLinkDefault(
      [],
      'neo_menu_link.test',
      [
        'id' => 'neo_menu_link.test',
        'title' => 'Test link',
        'route_name' => '<front>',
        'options' => $attributes ? ['attributes' => $attributes] : [],
      ],
      $this->container->get('menu_link.static.overrides')
    );
  }

  /**
   * A menu tree element wrapping one link, as the overview form builds it.
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $link
   *   The link.
   *
   * @return \Drupal\Core\Menu\MenuLinkTreeElement
   *   The tree element.
   */
  protected function treeElement($link): MenuLinkTreeElement {
    return new MenuLinkTreeElement($link, FALSE, 1, FALSE, []);
  }

  /**
   * A form object answering a node, as the node form does.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return \Drupal\Core\Entity\EntityFormInterface
   *   The form object.
   */
  protected function nodeFormObject($node): EntityFormInterface {
    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->method('getEntity')->willReturn($node);
    return $formObject;
  }

  /**
   * A form object answering a menu link content entity.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\Entity\EntityFormInterface
   *   The form object.
   */
  protected function menuLinkContentFormObject($entity): EntityFormInterface {
    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->method('getEntity')->willReturn($entity);
    return $formObject;
  }

  /**
   * Switches the current user to an account with exactly these permissions.
   *
   * @param string[] $permissions
   *   The permissions the account answers to.
   */
  protected function setCurrentUserPermissions(array $permissions): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(2);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE)
    );
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Invokes this module's implementation of a form alter.
   *
   * @param string $hook
   *   The hook name, without the `hook_` prefix.
   * @param array $form
   *   The form, altered in place.
   * @param string $formId
   *   The form id.
   * @param mixed $formObject
   *   The form object the alter reaches through the form state, if it needs
   *   one.
   * @param \Drupal\Core\Form\FormState|null $formState
   *   The form state, if the alter reads anything but the form out of it.
   */
  protected function invokeAlter(string $hook, array &$form, string $formId, $formObject = NULL, ?FormState $formState = NULL): void {
    $formState = $formState ?: new FormState();
    if ($formObject !== NULL) {
      $formState->setFormObject($formObject);
    }
    $this->container->get('module_handler')
      ->invoke('neo_menu_link', $hook, [&$form, $formState, $formId]);
  }

  /**
   * The icon id an icon element was built with.
   *
   * `IconElement::getIcon()` answers the resolved icon object rather than the
   * id it was handed, and resolving needs an installed library that carries
   * the id. The id itself is what the menu overview alter passes, so that is
   * what is asserted.
   *
   * @param \Drupal\neo_icon\IconElement $element
   *   The icon element.
   *
   * @return string|null
   *   The icon id.
   */
  protected function iconIdOf(IconElement $element): ?string {
    return (new \ReflectionProperty(IconElement::class, 'icon'))->getValue($element);
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
   *   One `module: identifier` string per implementation.
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

  /**
   * The listener list core assembles for one combined alter call, in order.
   *
   * `ModuleHandler::alter()` is handed every hook name a form alter fans out
   * to at once, and it builds one list from all of them — regrouping by module
   * and only then applying the ordering rules. That list is what a real node
   * form runs through, and it is not the list `invokeAllWith()` iterates, so
   * the ordering attribute is asserted against both.
   *
   * @param string[] $hooks
   *   The alter hook names, as `ModuleHandler::alter()` derives them.
   *
   * @return string[]
   *   One identifier per listener, in the order they will be called.
   */
  protected function combinedAlterListeners(array $hooks): array {
    $moduleHandler = $this->container->get('module_handler');
    $listeners = \Closure::bind(
      fn (): array => $this->getCombinedListeners($hooks),
      $moduleHandler,
      ModuleHandler::class
    )();
    return array_map(
      static fn ($listener): string => is_array($listener)
        ? get_class($listener[0]) . '::' . $listener[1]
        : (string) $listener,
      $listeners
    );
  }

}
