<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\menu_ui\Form\MenuLinkEditForm;
use Drupal\neo_taxonomy\Hook\NeoTaxonomyFormHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * The sub-module's three form alters and two callbacks, through the hooks.
 *
 * Everything form-shaped that `neo_taxonomy.module` carried is a method on
 * \Drupal\neo_taxonomy\Hook\NeoTaxonomyFormHooks: the general form alter with
 * its six branches — the vocabulary form, the term form's weights, description
 * format and relations access, the menu form's `taxonomy_menu`-managed rows,
 * the menu link edit form's parent and weight access, and the two
 * `taxonomy_manager` forms — the vocabulary overview alter, the term overview
 * alter, the vocabulary settings helper, the description after-build callback
 * and the vocabulary submit handler. The two hooks that are not form alters are
 * on \Drupal\neo_taxonomy\Hook\NeoTaxonomyHooks, which is core's
 * `…Hooks` / `…FormHooks` split; `TaxonomyHooksTest` owns that half and the
 * registration criterion that spans both classes.
 *
 * The bodies move as they stand, with one substitution: the four reaches for
 * `\Drupal::entityTypeManager()` become a constructor argument. The filter
 * format repository deliberately stays a static call — this sub-module declares
 * no `filter` dependency, and injecting a service from an undeclared module
 * turns a lazy per-request failure into a container-build failure on any site
 * that does not have it — and the two `phpstan-ignore-next-line` suppressions
 * guarding the `taxonomy_manager` `instanceof` tests move unchanged, because
 * `taxonomy_manager` is not installed here and this site cannot observe what
 * happens on a site that has it.
 *
 * The two callbacks are static methods named by their `Class::method` string in
 * the `#after_build` and `#submit` arrays that carry them. No global forwarder
 * is kept for either: nothing outside this file ever named them, and both
 * arrays are rebuilt by the alter on every request. Which is why the tests
 * below assert the string the form carries as well as what running it does —
 * a callback that is correct and is not the one the form names has moved
 * nothing.
 */
#[Group('neo')]
final class TaxonomyFormHooksTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`. `taxonomy` is what all three alters
   * are about and it brings `node`, `text` and `filter` with it — `filter` is
   * the module whose format repository the vocabulary settings helper reaches
   * statically and whose `text_format` element the after-build strips.
   * `neo_icon` gives the two overview alters the global element helpers they
   * call, and `menu_ui` gives the form alter the two form objects its fourth
   * and fifth branches guard on.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'neo',
    'neo_icon',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'link',
    'menu_link_content',
    'menu_ui',
    'neo_taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'field', 'filter', 'taxonomy']);
    $this->setUpCurrentUser(['uid' => 1], [], TRUE);
  }

  /**
   * The settings fieldset lands after description and the submit writes it.
   *
   * Acceptance criterion: it inserts the settings fieldset into the vocabulary
   * form after the description element, and its submit handler writes the link,
   * order, nest, description-required and description-format settings onto the
   * vocabulary.
   *
   * The position is the load-bearing part and it is the reason the helper
   * splices the array rather than setting a `#weight`: `array_merge` over
   * `array_slice` is what puts the fieldset immediately after `description` in
   * key order, and a `#weight` would not survive a form with no weights on its
   * other elements. Driven through the entity form builder, so the fieldset's
   * position is measured in the real form rather than in a skeleton.
   */
  public function testInsertsTheSettingsFieldsetAfterDescriptionAndWritesTheFiveSettings(): void {
    $vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
    $vocabulary->save();

    $form = $this->container->get('entity.form_builder')->getForm($vocabulary, 'default');

    $keys = array_values(array_filter(
      array_keys($form),
      static fn ($key) => !str_starts_with((string) $key, '#')
    ));
    $this->assertContains('neo_taxonomy', $keys, 'The settings fieldset is in the form.');
    $this->assertSame(
      array_search('description', $keys, TRUE) + 1,
      array_search('neo_taxonomy', $keys, TRUE),
      'It sits immediately after the description element.'
    );

    $this->assertSame('fieldset', $form['neo_taxonomy']['#type']);
    $this->assertTrue($form['neo_taxonomy']['#tree']);
    $this->assertSame(
      ['link', 'order', 'nest', 'description_required', 'description_format'],
      array_values(array_filter(
        array_keys($form['neo_taxonomy']),
        static fn ($key) => !str_starts_with((string) $key, '#')
      )),
      'The five controls, in the order the helper declares them.'
    );
    // An unconfigured vocabulary opts in to the three it defaults on and out of
    // the one it defaults off, which is the `!isset()` half of each default.
    $this->assertTrue((bool) $form['neo_taxonomy']['link']['#default_value']);
    $this->assertTrue((bool) $form['neo_taxonomy']['order']['#default_value']);
    $this->assertTrue((bool) $form['neo_taxonomy']['nest']['#default_value']);
    $this->assertFalse((bool) $form['neo_taxonomy']['description_required']['#default_value']);
    // The format select is built from the filter format repository, which this
    // sub-module reaches statically on purpose.
    $this->assertArrayHasKey('plain_text', $form['neo_taxonomy']['description_format']['#options']);

    // The submit handler the helper appended is the static method's string.
    $handler = NeoTaxonomyFormHooks::class . '::formTaxonomyVocabularyFormSubmit';
    $this->assertContains($handler, $form['actions']['submit']['#submit']);
    $this->assertIsCallable($handler, 'The string the helper writes into #submit is callable.');

    // Driving it writes all five settings onto the vocabulary and saves it.
    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('taxonomy_vocabulary', 'default');
    $formObject->setEntity($vocabulary);
    $formState = new FormState();
    $formState->setFormObject($formObject);
    $formState->setValue('neo_taxonomy', [
      'link' => 0,
      'order' => 1,
      'nest' => 1,
      'description_required' => 1,
      'description_format' => 'plain_text',
    ]);
    call_user_func($handler, $form, $formState);

    $saved = Vocabulary::load('tags');
    $this->assertFalse($saved->getThirdPartySetting('neo_taxonomy', 'link'));
    $this->assertTrue($saved->getThirdPartySetting('neo_taxonomy', 'order'));
    $this->assertTrue($saved->getThirdPartySetting('neo_taxonomy', 'nest'));
    $this->assertTrue($saved->getThirdPartySetting('neo_taxonomy', 'description_required'));
    $this->assertSame('plain_text', $saved->getThirdPartySetting('neo_taxonomy', 'description_format'));

    // Nesting is `order && nest`, not `nest`: with ordering off the nesting
    // setting is written off whatever the checkbox said.
    $formState->setValue('neo_taxonomy', [
      'link' => 1,
      'order' => 0,
      'nest' => 1,
      'description_required' => 0,
      'description_format' => '',
    ]);
    call_user_func($handler, $form, $formState);
    $saved = Vocabulary::load('tags');
    $this->assertTrue($saved->getThirdPartySetting('neo_taxonomy', 'link'));
    $this->assertFalse($saved->getThirdPartySetting('neo_taxonomy', 'order'));
    $this->assertFalse($saved->getThirdPartySetting('neo_taxonomy', 'nest'));
    $this->assertSame('', $saved->getThirdPartySetting('neo_taxonomy', 'description_format'));

    // And it came from the hook class rather than from a function the collector
    // is still reading out of the `.module` file.
    $this->assertContains(
      'neo_taxonomy: ' . NeoTaxonomyFormHooks::class . '::formAlter',
      $this->hookImplementations('form_alter')
    );
  }

  /**
   * The term form takes the configured format and the after-build strips it.
   *
   * Acceptance criterion: it applies the configured description format to a
   * term form and strips the format help through the after-build callback,
   * which is a static method named in the form rather than a global.
   *
   * Three statements, and they fail separately. The format and the allowed
   * formats are what the branch sets; the `#after_build` entry is the
   * `Class::method` string that replaced the global; and running that callback
   * is what removes the help and the guidelines. The real form is built through
   * the entity form builder, so the callback runs where it runs in production —
   * `help` no longer exists on core's `text_format` element and `guidelines`
   * does, which is why the static is also driven directly against an element
   * carrying both.
   */
  public function testAppliesTheDescriptionFormatAndStripsTheFormatHelpThroughTheStaticAfterBuild(): void {
    $vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'description_format', 'plain_text');
    $vocabulary->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'Alpha']);

    $form = $this->container->get('entity.form_builder')->getForm($term, 'default');
    $widget = $form['description']['widget'][0];

    $this->assertSame('plain_text', $widget['#format']);
    $this->assertSame(['plain_text'], $widget['#allowed_formats']);
    $callback = NeoTaxonomyFormHooks::class . '::descriptionAfterBuild';
    $this->assertContains($callback, $widget['#after_build']);
    $this->assertIsCallable($callback, 'The string the alter writes into #after_build is callable.');
    // Having run, the format container has neither of the two children the
    // callback removes.
    $this->assertArrayNotHasKey('guidelines', $widget['format']);
    $this->assertArrayNotHasKey('help', $widget['format']);

    // The three weights the same branch assigns, measured where the branch
    // assigns them. They are not assertable on the built form: the entity
    // form's own `#process` callback rebuilds every widget from the form
    // display afterwards, which resets `status` to its display weight — true
    // before this ticket and true after it, because the body moved unchanged.
    $termFormObject = $this->container->get('entity_type.manager')
      ->getFormObject('taxonomy_term', 'default');
    $termFormObject->setEntity($term);
    $termState = new FormState();
    $termState->setFormObject($termFormObject);
    $skeleton = [
      'status' => ['#type' => 'checkbox'],
      'advanced' => ['#type' => 'container'],
      'actions' => ['#type' => 'actions'],
      'description' => ['widget' => [0 => ['#type' => 'text_format']]],
      'relations' => ['#type' => 'details'],
    ];
    $termFormId = 'taxonomy_term_tags_form';
    $this->container->get('module_handler')->alter('form', $skeleton, $termState, $termFormId);
    $this->assertSame(800, $skeleton['status']['#weight']);
    $this->assertSame(900, $skeleton['advanced']['#weight']);
    $this->assertSame(1000, $skeleton['actions']['#weight']);
    $this->assertSame('plain_text', $skeleton['description']['widget'][0]['#format']);
    $this->assertSame(['plain_text'], $skeleton['description']['widget'][0]['#allowed_formats']);
    $this->assertSame([$callback], $skeleton['description']['widget'][0]['#after_build']);

    // The callback itself, driven against an element carrying both children.
    $this->assertTrue(
      (new \ReflectionMethod(NeoTaxonomyFormHooks::class, 'descriptionAfterBuild'))->isStatic(),
      'The after-build callback is a static method.'
    );
    $element = [
      'value' => ['#type' => 'textarea'],
      'format' => [
        'help' => ['#markup' => 'About text formats'],
        'guidelines' => ['#markup' => 'Guidelines'],
        'format' => ['#type' => 'select'],
      ],
    ];
    $returned = call_user_func($callback, $element, new FormState());
    $this->assertArrayNotHasKey('help', $returned['format']);
    $this->assertArrayNotHasKey('guidelines', $returned['format']);
    $this->assertArrayHasKey('format', $returned['format'], 'The selector survives.');
    $this->assertArrayHasKey('value', $returned, 'So does everything outside the container.');
    // An element with no format container is returned exactly as it arrived.
    $plain = ['value' => ['#type' => 'textarea']];
    $this->assertSame($plain, call_user_func($callback, $plain, new FormState()));

    // A vocabulary with no configured format is left on core's own handling.
    $other = Vocabulary::create(['vid' => 'plain', 'name' => 'Plain']);
    $other->save();
    $otherForm = $this->container->get('entity.form_builder')
      ->getForm(Term::create(['vid' => 'plain', 'name' => 'Beta']), 'default');
    $this->assertArrayNotHasKey('#allowed_formats', $otherForm['description']['widget'][0]);
  }

  /**
   * Nesting and ordering off hide relations and the ordering controls.
   *
   * Acceptance criterion: it hides relations on a term form and the ordering
   * controls on the term overview for a vocabulary with nesting or ordering
   * disabled.
   *
   * The term overview is driven with the form array core's `OverviewTerms`
   * builds rather than through the form builder, because that form needs a
   * request with a pager and a term tree; the shape is what matters, and the
   * shape is what the body reads.
   */
  public function testHidesRelationsOnTheTermFormAndTheOrderingControlsOnTheTermOverview(): void {
    $vocabulary = Vocabulary::create([
      'vid' => 'flat',
      'name' => 'Flat',
      'description' => 'A flat vocabulary.',
    ]);
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'nest', FALSE);
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'order', FALSE);
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'link', FALSE);
    $vocabulary->save();
    $term = Term::create(['vid' => 'flat', 'name' => 'Alpha']);
    $term->save();

    // The term form's relations section is closed off entirely.
    $form = $this->container->get('entity.form_builder')->getForm($term, 'default');
    $this->assertFalse($form['relations']['#access']);

    // A vocabulary that left nesting on keeps it.
    $nested = Vocabulary::create(['vid' => 'nested', 'name' => 'Nested']);
    $nested->setThirdPartySetting('neo_taxonomy', 'nest', TRUE);
    $nested->save();
    $nestedTerm = Term::create(['vid' => 'nested', 'name' => 'Beta']);
    $nestedTerm->save();
    $nestedForm = $this->container->get('entity.form_builder')->getForm($nestedTerm, 'default');
    $this->assertArrayNotHasKey('#access', $nestedForm['relations']);

    // The term overview loses its ordering controls entirely.
    $overview = $this->termOverviewSkeleton($term);
    $formState = new FormState();
    $formState->set(['taxonomy', 'vocabulary'], $vocabulary);
    $formId = 'taxonomy_overview_terms';
    $this->container->get('module_handler')
      ->alter('form_taxonomy_overview_terms', $overview, $formState, $formId);

    $this->assertFalse($overview['actions']['reset_alphabetical']['#access']);
    $this->assertArrayNotHasKey('#tabledrag', $overview['terms']);
    $this->assertArrayNotHasKey('weight', $overview['terms']['#header']);
    $this->assertArrayNotHasKey('weight', $overview['terms']['tid:1:0']);
    // The vocabulary description replaces the help message, which is the other
    // half of the same branch.
    $this->assertSame('A flat vocabulary.', $overview['help']['message']['#markup']);
    $this->assertContains(
      'card bg-base-100 text-xs text-base-700 p-2',
      $overview['help']['#attributes']['class']
    );
    // With linking off the term title stops being a link.
    $this->assertSame('markup', $overview['terms']['tid:1:0']['term']['title']['#type']);
    $this->assertStringContainsString('Alpha', (string) $overview['terms']['tid:1:0']['term']['title']['#markup']);

    // A vocabulary with ordering on keeps every one of them.
    $ordered = Vocabulary::create(['vid' => 'ordered', 'name' => 'Ordered']);
    $ordered->setThirdPartySetting('neo_taxonomy', 'order', TRUE);
    $ordered->setThirdPartySetting('neo_taxonomy', 'nest', TRUE);
    $ordered->setThirdPartySetting('neo_taxonomy', 'link', TRUE);
    $ordered->save();
    $orderedOverview = $this->termOverviewSkeleton($term);
    $orderedState = new FormState();
    $orderedState->set(['taxonomy', 'vocabulary'], $ordered);
    $this->container->get('module_handler')
      ->alter('form_taxonomy_overview_terms', $orderedOverview, $orderedState, $formId);

    $this->assertArrayNotHasKey('#access', $orderedOverview['actions']['reset_alphabetical']);
    $this->assertArrayHasKey('#tabledrag', $orderedOverview['terms']);
    $this->assertArrayHasKey('weight', $orderedOverview['terms']['#header']);
    $this->assertArrayHasKey('weight', $orderedOverview['terms']['tid:1:0']);
    $this->assertSame('link', $orderedOverview['terms']['tid:1:0']['term']['title']['#type']);

    // Both came from the hook class rather than from a global.
    $this->assertContains(
      'neo_taxonomy: ' . NeoTaxonomyFormHooks::class . '::formTaxonomyOverviewTermsAlter',
      $this->hookImplementations('form_taxonomy_overview_terms_alter')
    );
  }

  /**
   * Taxonomy-managed menu rows are marked, and their link form is trimmed.
   *
   * Acceptance criterion: it marks `taxonomy_menu`-managed rows on the menu
   * form with their marker, operation and disabled controls, and hides parent
   * and weight on the menu link edit form for such a link.
   *
   * Both branches guard on the form object rather than on the form id, so both
   * are driven through `hook_form_alter` with a real `MenuForm` and a real
   * `MenuLinkEditForm` in the form state. The row key is the one
   * `taxonomy_menu` produces, because the tid the body marks the row from is
   * parsed out of that key.
   */
  public function testMarksTaxonomyMenuManagedRowsAndTrimsTheMenuLinkEditForm(): void {
    $vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
    $vocabulary->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'Alpha']);
    $term->save();

    $managed = 'menu_plugin_id:taxonomy_menu.menu_link:taxonomy_menu.menu_link.tags.' . $term->id();
    $plain = 'menu_plugin_id:standard.front_page';
    $form = [
      'links' => [
        'links' => [
          $managed => $this->menuOverviewRow('Alpha'),
          $plain => $this->menuOverviewRow('Home'),
        ],
      ],
    ];
    $formState = new FormState();
    $formState->setFormObject(
      $this->container->get('entity_type.manager')->getFormObject('menu', 'edit')
    );
    $menuFormId = 'menu_edit_form';
    $this->container->get('module_handler')->alter('form', $form, $formState, $menuFormId);

    $row = $form['links']['links'][$managed];
    $this->assertStringContainsString(
      'Managed via Taxonomy',
      (string) $row['title'][0]['#markup'],
      'The row carries its marker.'
    );
    $this->assertArrayHasKey('term', $row['operations']['#links']);
    $this->assertInstanceOf(Url::class, $row['operations']['#links']['term']['url']);
    $this->assertSame(
      'entity.taxonomy_vocabulary.overview_form',
      $row['operations']['#links']['term']['url']->getRouteName()
    );
    $this->assertSame(
      ['taxonomy_vocabulary' => 'tags'],
      $row['operations']['#links']['term']['url']->getRouteParameters()
    );
    foreach (['add-child', 'reset', 'delete'] as $operation) {
      $this->assertArrayNotHasKey($operation, $row['operations']['#links']);
    }
    $this->assertArrayHasKey('edit', $row['operations']['#links'], 'Editing is left alone.');
    $this->assertTrue($row['enabled']['#disabled']);
    $this->assertTrue($row['weight']['#disabled']);
    $this->assertContains('draggable-disabled', $row['#attributes']['class']);

    // A row `taxonomy_menu` does not manage is untouched.
    $untouched = $form['links']['links'][$plain];
    $this->assertEquals($this->menuOverviewRow('Home'), $untouched);

    // The menu link edit form for such a link loses its parent and weight.
    $editForm = ['menu_link_id' => ['#value' => 'taxonomy_menu.menu_link:taxonomy_menu.menu_link.tags.' . $term->id()]];
    $editState = new FormState();
    $editState->setFormObject(MenuLinkEditForm::create($this->container));
    $editFormId = 'menu_link_edit';
    $this->container->get('module_handler')->alter('form', $editForm, $editState, $editFormId);
    $this->assertFalse($editForm['menu_parent']['#access']);
    $this->assertFalse($editForm['weight']['#access']);

    // Any other menu link keeps both.
    $otherForm = ['menu_link_id' => ['#value' => 'standard.front_page'], 'menu_parent' => [], 'weight' => []];
    $otherState = new FormState();
    $otherState->setFormObject(MenuLinkEditForm::create($this->container));
    $this->container->get('module_handler')->alter('form', $otherForm, $otherState, $editFormId);
    $this->assertArrayNotHasKey('#access', $otherForm['menu_parent']);
    $this->assertArrayNotHasKey('#access', $otherForm['weight']);

    // Both branches came from the hook class rather than from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_taxonomy: ' . NeoTaxonomyFormHooks::class . '::formAlter',
      $this->hookImplementations('form_alter')
    );
  }

  /**
   * The container collaborators are injected and the filter reach is not.
   *
   * Acceptance criterion: the entity type manager and config factory are
   * constructor dependencies, the filter format repository is still reached
   * statically, and the two surviving `phpstan-ignore-next-line` suppressions
   * are unchanged.
   *
   * The two collaborators are split across the two classes by what each one
   * actually uses — the form class reaches the entity type manager four times
   * and the config factory never, and the sibling class is the other way round
   * — so the criterion is asserted across the sub-module rather than twice over
   * on one file. The static that stays is the point of the third clause: this
   * sub-module declares no `filter` dependency, so injecting that service would
   * turn a lazy per-request failure into a container-build failure on a site
   * without it.
   */
  public function testInjectsTheEntityTypeManagerAndKeepsTheFilterRepositoryStatic(): void {
    $file = $this->packageRoot() . '/modules/neo_taxonomy/src/Hook/NeoTaxonomyFormHooks.php';
    $this->assertFileExists($file, 'The hook class is on disk.');

    $constructor = (new \ReflectionClass(NeoTaxonomyFormHooks::class))->getConstructor();
    $this->assertNotNull($constructor, 'The class declares a constructor.');
    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $types[] = (string) $parameter->getType();
    }
    $this->assertContains(EntityTypeManagerInterface::class, $types);

    // The container hands it the real service rather than some other one.
    $instance = $this->container->get(NeoTaxonomyFormHooks::class);
    $injected = NULL;
    foreach ((new \ReflectionClass($instance))->getProperties() as $property) {
      $value = $property->getValue($instance);
      if ($value instanceof EntityTypeManagerInterface) {
        $injected = $value;
      }
    }
    $this->assertSame($this->container->get('entity_type.manager'), $injected);

    // No body reaches the entity type manager or the config factory statically
    // any more. Comments are stripped first, because the class docblock names
    // the calls it replaced.
    $source = (string) php_strip_whitespace($file);
    $this->assertStringNotContainsString('\Drupal::entityTypeManager(', $source);
    $this->assertStringNotContainsString('\Drupal::config(', $source);
    // The one that stays, exactly as it stood.
    $this->assertStringContainsString(
      '\Drupal::service(FilterFormatRepositoryInterface::class)',
      $source,
      'The filter format repository is still reached statically.'
    );

    // And the two suppressions `neo-gate-level-clean` deliberately left move
    // with the bodies they guard, unchanged and un-added-to.
    $raw = (string) file_get_contents($file);
    $lines = explode("\n", $raw);
    $guarded = [];
    foreach ($lines as $index => $line) {
      if (trim($line) === '// @phpstan-ignore-next-line') {
        $guarded[] = trim($lines[$index + 1]);
      }
    }
    $this->assertCount(
      2,
      $guarded,
      'Exactly the two surviving suppressions, and no new one.'
    );
    $this->assertSame(
      [
        'if ($form_object instanceof TaxonomyManagerForm) {',
        'if ($form_object instanceof TaxonomyManagerTermForm) {',
      ],
      $guarded,
      'Both still guard the two `taxonomy_manager` instanceof tests.'
    );
  }

  /**
   * The hook scan skip is declared and nothing procedural is registered.
   *
   * Acceptance criterion: `neo_taxonomy.skip_procedural_hook_scan` is declared
   * and the module registers no procedural hook implementation.
   *
   * The parameter is read out of the parsed services file rather than the
   * container, because core removes every `*.skip_procedural_hook_scan`
   * parameter once the collector has consumed it. The half that carries the
   * criterion is the second: the module's whole entry in the hook list is five
   * class-based identifiers and nothing else.
   */
  public function testDeclaresTheHookScanSkipAndRegistersNoProceduralImplementation(): void {
    $services = Yaml::parseFile(
      $this->packageRoot() . '/modules/neo_taxonomy/neo_taxonomy.services.yml'
    );
    $this->assertTrue(
      $services['parameters']['neo_taxonomy.skip_procedural_hook_scan'] ?? FALSE,
      'The services file declares the hook scan skip parameter.'
    );

    $hookList = $this->container->get('keyvalue')->get('hook_data')->get('hook_list');
    $this->assertIsArray($hookList);
    $procedural = [];
    $classes = [];
    foreach ($hookList as $hook => $implementations) {
      foreach ($implementations as $identifier => $module) {
        if ($module !== 'neo_taxonomy') {
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
    $this->assertCount(5, $classes, 'All five of the module\'s hooks are class-based.');
  }

  /**
   * A menu overview row in the shape `MenuForm::buildOverviewForm()` builds.
   *
   * @param string $title
   *   The row's link title.
   *
   * @return array
   *   The row.
   */
  protected function menuOverviewRow(string $title): array {
    return [
      '#item' => NULL,
      '#attributes' => ['class' => ['draggable']],
      'title' => [
        '#type' => 'link',
        '#title' => $title,
        '#url' => Url::fromRoute('<front>'),
      ],
      'enabled' => ['#type' => 'checkbox', '#default_value' => TRUE],
      'weight' => ['#type' => 'weight', '#delta' => 50],
      'operations' => [
        '#type' => 'operations',
        '#links' => [
          'edit' => ['title' => 'Edit', 'url' => Url::fromRoute('<front>')],
          'add-child' => ['title' => 'Add child', 'url' => Url::fromRoute('<front>')],
          'reset' => ['title' => 'Reset', 'url' => Url::fromRoute('<front>')],
          'delete' => ['title' => 'Delete', 'url' => Url::fromRoute('<front>')],
        ],
      ],
    ];
  }

  /**
   * A term overview in the shape `OverviewTerms::buildForm()` builds.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The one term the single row is for.
   *
   * @return array
   *   The form array.
   */
  protected function termOverviewSkeleton(Term $term): array {
    return [
      'help' => [
        '#attributes' => ['class' => []],
        'message' => ['#markup' => 'Core help text.'],
      ],
      'terms' => [
        '#type' => 'table',
        '#header' => ['term' => 'Name', 'weight' => 'Weight', 'operations' => 'Operations'],
        '#tabledrag' => [['action' => 'match', 'relationship' => 'parent']],
        'tid:1:0' => [
          '#term' => $term,
          '#attributes' => ['class' => ['draggable']],
          'term' => [
            '#prefix' => '',
            '#type' => 'link',
            '#title' => $term->getName(),
            '#url' => Url::fromRoute('<front>'),
            'tid' => ['#type' => 'hidden', '#value' => $term->id()],
            'parent' => ['#type' => 'hidden', '#default_value' => 0],
            'depth' => ['#type' => 'value', '#value' => 0],
          ],
          'status' => ['#markup' => 'Published'],
          'weight' => ['#type' => 'weight', '#delta' => 50, '#default_value' => 0],
          'operations' => ['#type' => 'operations', '#links' => []],
        ],
      ],
      'actions' => [
        'submit' => ['#type' => 'submit', '#value' => 'Save'],
        'reset_alphabetical' => ['#type' => 'submit', '#value' => 'Reset to alphabetical'],
      ],
    ];
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
