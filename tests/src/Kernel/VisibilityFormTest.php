<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\neo_test\Entity\NeoTestVisibility;
use Drupal\system\Plugin\Condition\RequestPath;
use Drupal\user\Entity\Role;
use Drupal\user\Plugin\Condition\UserRole;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the visibility form trait through a consumer.
 *
 * `VisibilityFormTrait` is the largest of the four traits this plan covers and
 * the only one that is pure user interface: two hundred lines that turn every
 * condition plugin filtered for the `neo` consumer into a vertical-tabs group,
 * reshape four of them by hand, cast what comes back and write it onto the
 * entity's condition collection. It ships to `neo_settings` and `neo_toolbar`
 * — every visibility form on every Neo site is this code — and it has no
 * consumer inside `neo`, so neither phpstan nor any test has ever seen it.
 *
 * The consumer is `neo_test`'s entity form over the plain visibility consumer.
 * The three trait methods are driven directly with a form state rather than
 * through `FormBuilder`: the thing under test is the trait, not core's form
 * builder, and a full submission would put `EntityForm::buildEntity()`'s clone
 * between the trait and every assertion.
 *
 * Two consequences of driving them directly are worth knowing before extending
 * this:
 *
 * - `#parents` and `#array_parents` are what `FormBuilder::doBuildForm()` would
 *   have written, and `SubformState::createForSubform()` throws without them.
 *   `attachParents()` writes exactly what the builder would.
 * - `ConditionPluginCollection::getConfiguration()` drops any instance whose
 *   configuration equals its own defaults, so a condition submitted with
 *   nothing but defaults vanishes from `getVisibility()` while staying in
 *   `getInstanceIds()`. That is core's behaviour, not the trait's, and it is
 *   pinned here because a reader of these assertions will otherwise think the
 *   trait dropped it.
 *
 * `language` is installed on top of the module set ticket 01 verified, because
 * the language condition is the one rule with a case on each side of a check —
 * dropped while the site is monolingual, kept once it is not — and the plugin
 * does not exist without its module.
 *
 * **Characterised, not repaired.** Five answers pinned here are quirks rather
 * than intentions, and each belongs on the backlog:
 *
 * 1. **`buildVisibility()` passes a variable to `t()`.** The section title is
 *    `$this->t($title)` on a parameter, which is why the line carries a phpcs
 *    ignore. No caller in the stack passes anything but the default, so the
 *    string that reaches the translation system is always the literal
 *    `Visibility` — but nothing enforces that, and a caller passing a built
 *    string would put an untranslatable value into the UI.
 * 2. **The negate control is disabled for a fixed list of four plugin ids.**
 *    `entity_bundle:node`, `language`, `response_status`, `user_role` are
 *    hard-coded, so a site whose visibility conditions come from anywhere else
 *    — a contrib condition, an `entity_bundle` derivative for any other entity
 *    type — keeps a negate checkbox its neighbours do not have. Only the
 *    `isset()` guard stops the missing `entity_bundle:node` being an error
 *    here, where `node` is not installed.
 * 3. **`validateVisibility()` reads `getValue('visibility')` with no default**
 *    where `submitVisibility()` reads `getValue('visibility', [])`. A form
 *    whose visibility group produced no values at all — every condition
 *    filtered away — therefore warns and fails in validate but is silently
 *    fine in submit. The asymmetry is in two adjacent methods that are always
 *    called as a pair.
 * 4. **Neither validate nor submit consults the definitions it built from.**
 *    Both iterate the submitted values, so a value whose key is not a
 *    condition the form built reaches `$form_state->get(['conditions', $id])`
 *    as NULL and fatals on the next line. The form state is the only guard.
 * 5. **The negate cast happens in validate, not in submit.** A consumer that
 *    calls `submitVisibility()` without `validateVisibility()` stores the raw
 *    form value — integer `1`, not boolean `TRUE` — against a schema that
 *    declares boolean. Pinned in
 *    testWritesEachConditionsSubmittedConfigurationBackOntoTheEntityByInstanceId.
 *
 * Assertions are against the built arrays, the form state and the entity, never
 * rendered markup.
 */
#[Group('neo')]
final class VisibilityFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `language` is this test's own addition
   * and is the module that supplies the language condition.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'language',
    'neo',
    'neo_test',
  ];

  /**
   * A role the user_role condition can be seeded and submitted with.
   */
  const ROLE = 'neo_test_staff';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'user', 'language']);

    Role::create([
      'id' => self::ROLE,
      'label' => 'Neo test staff',
    ])->save();
  }

  /**
   * The contexts are stored first and the tabs carry the visibility library.
   *
   * Covers: "it stores the available contexts in the form state and adds the
   * vertical tabs with the visibility library".
   *
   * `prepareVisibility()` is a method of its own and is asserted as one: it
   * writes the context repository's answer into the form state's *temporary*
   * values under `gathered_contexts`, which is the key
   * `ConditionPluginBase::buildConfigurationForm()` reads to build its context
   * assignment select. Nothing else about the form has happened yet.
   *
   * The tabs are then asserted down to the library name, because the name is a
   * string with no compile-time check behind it — `library.discovery` is asked
   * whether `neo/visibility` actually resolves, so a rename in
   * `neo.libraries.yml` fails here rather than silently shipping a form with no
   * behaviour. The section title is the third parameter and is pinned both
   * ways: this is finding 1 in the class docblock.
   */
  public function testStoresTheAvailableContextsAndAddsTheVerticalTabsWithTheLibrary(): void {
    $formObject = $this->formObjectFor($this->consumer('contexts'));
    $formState = new FormState();

    $this->assertNull($formState->getTemporaryValue('gathered_contexts'));
    $this->invoke($formObject, 'prepareVisibility', [[], $formState]);

    $gathered = $formState->getTemporaryValue('gathered_contexts');
    $this->assertIsArray($gathered);
    $this->assertSame(
      array_keys($this->container->get('context.repository')->getAvailableContexts()),
      array_keys($gathered)
    );
    $this->assertContainsOnlyInstancesOf(ContextInterface::class, $gathered);
    // The temporary values are where it goes; the ordinary values are empty.
    $this->assertSame([], $formState->getValues());

    $group = $this->invoke($formObject, 'buildVisibility', [[], $formState]);

    $this->assertTrue($group['#tree']);
    $this->assertSame('vertical_tabs', $group['visibility_tabs']['#type']);
    $this->assertSame(['visibility_tabs'], $group['visibility_tabs']['#parents']);
    $this->assertSame(
      ['library' => ['neo/visibility']],
      $group['visibility_tabs']['#attached']
    );
    $this->assertSame('Visibility', (string) $group['visibility_tabs']['#title']);

    // The library the tabs attach is a real one.
    $library = $this->container->get('library.discovery')
      ->getLibraryByName('neo', 'visibility');
    $this->assertIsArray($library);

    // Finding 1: the title is a parameter handed straight to t().
    $named = $this->invoke($formObject, 'buildVisibility', [[], $formState, 'Where it shows']);
    $this->assertSame('Where it shows', (string) $named['visibility_tabs']['#title']);
  }

  /**
   * Every surviving condition becomes a details element under the tabs.
   *
   * Covers: "it builds one grouped details element per condition, seeded from
   * what the entity already stores".
   *
   * Three things are asserted per condition and none of them is the plugin's
   * own form: that it is a `details`, that it is grouped into
   * `visibility_tabs`, and that its title is the plugin definition's label.
   * The seeding is the fourth: each plugin instance is created with the
   * entity's stored configuration for that id, so the values the plugin puts
   * in its own `#default_value`s are the entity's, and an id the entity stores
   * nothing for gets the plugin's defaults instead.
   *
   * The instance itself is kept in the form state under
   * `['conditions', $id]`, which is the only place validate and submit later
   * find it — a condition dropped from the build is absent there too.
   */
  public function testBuildsOneGroupedDetailsElementPerConditionSeededFromTheEntity(): void {
    $entity = $this->consumer('seeded', [
      'user_role' => [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => [self::ROLE => self::ROLE],
      ],
      'request_path' => [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => '/seeded',
      ],
      'response_status' => [
        'id' => 'response_status',
        'negate' => FALSE,
        'status_codes' => [404],
      ],
    ]);
    $formState = new FormState();
    $group = $this->buildGroup($this->formObjectFor($entity), $formState);

    // One element per surviving condition, in the order the definitions came
    // back in, behind the two keys the trait writes itself.
    $this->assertSame(
      ['#tree', 'visibility_tabs', 'request_path', 'response_status', 'user_role'],
      array_keys($group)
    );

    foreach (['request_path', 'response_status', 'user_role'] as $conditionId) {
      $this->assertSame('details', $group[$conditionId]['#type'], $conditionId);
      $this->assertSame('visibility_tabs', $group[$conditionId]['#group'], $conditionId);
    }

    // The title is the plugin definition's label. `request_path` and
    // `user_role` are retitled afterwards, which is a rule of its own.
    $this->assertSame('Response status', (string) $group['response_status']['#title']);

    // Seeded from what the entity stores, plugin by plugin.
    $this->assertSame('/seeded', $group['request_path']['pages']['#default_value']);
    $this->assertSame(
      [self::ROLE => self::ROLE],
      $group['user_role']['roles']['#default_value']
    );
    $this->assertSame([404], $group['response_status']['status_codes']['#default_value']);

    // The instances are kept in the form state, seeded the same way.
    $userRole = $formState->get(['conditions', 'user_role']);
    $this->assertInstanceOf(UserRole::class, $userRole);
    $this->assertSame(
      [self::ROLE => self::ROLE],
      $userRole->getConfiguration()['roles']
    );
    $this->assertInstanceOf(
      RequestPath::class,
      $formState->get(['conditions', 'request_path'])
    );
    // A condition the build dropped is not there either.
    $this->assertNull($formState->get(['conditions', 'current_theme']));

    // An entity storing nothing gets the plugins' own defaults.
    $bare = $this->buildGroup($this->formObjectFor($this->consumer('bare')), new FormState());
    $this->assertSame('', $bare['request_path']['pages']['#default_value']);
    $this->assertSame([], $bare['user_role']['roles']['#default_value']);
    $this->assertSame([], $bare['response_status']['status_codes']['#default_value']);
  }

  /**
   * Current theme is always dropped; language only while monolingual.
   *
   * Covers: "it drops the current-theme condition always and the language
   * condition only when the site is monolingual".
   *
   * Both conditions are in the definitions the trait filters — the drop is the
   * trait's, not the context filter's, and that is asserted before anything
   * else so the two cannot be confused. `current_theme` then never appears,
   * whatever the site looks like. `language` appears the moment a second
   * configurable language exists, which is the only rule in the trait with a
   * case on each side of a check.
   */
  public function testDropsCurrentThemeAlwaysAndLanguageOnlyWhileMonolingual(): void {
    $definitions = $this->filteredDefinitions();
    $this->assertArrayHasKey('current_theme', $definitions);
    $this->assertArrayHasKey('language', $definitions);

    $this->assertFalse($this->container->get('language_manager')->isMultilingual());
    $monoState = new FormState();
    $mono = $this->buildGroup($this->formObjectFor($this->consumer('mono')), $monoState);
    $this->assertArrayNotHasKey('current_theme', $mono);
    $this->assertArrayNotHasKey('language', $mono);
    $this->assertNull($monoState->get(['conditions', 'language']));

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->assertTrue($this->container->get('language_manager')->isMultilingual());

    $multiState = new FormState();
    $multi = $this->buildGroup($this->formObjectFor($this->consumer('multi')), $multiState);
    $this->assertArrayHasKey('language', $multi);
    $this->assertSame('details', $multi['language']['#type']);
    $this->assertSame('visibility_tabs', $multi['language']['#group']);
    $this->assertNotNull($multiState->get(['conditions', 'language']));

    // The theme condition is unaffected by any of that.
    $this->assertArrayNotHasKey('current_theme', $multi);
    $this->assertNull($multiState->get(['conditions', 'current_theme']));
  }

  /**
   * Four named conditions lose their negate control; the others keep it.
   *
   * Covers: "it converts the negate control to a value element for the four
   * named conditions and leaves it alone for the others".
   *
   * The conversion carries whatever `#default_value` the plugin put there, so
   * an editor cannot flip the control but the stored answer is submitted back
   * unchanged. Two of the three conditions on the list that exist here are
   * seeded negated and one is not, which is what tells "carries the default"
   * apart from "writes a constant".
   *
   * `request_path` is the only condition in this module set that is *not* on
   * the list, and the proof that the loop passed it by is the absence of
   * `#value` together with a `#default_value` that is still there. What its
   * negate control does become is a separate rule, asserted in
   * testRetitlesUserRoleAndRequestPathAndMakesRequestPathNegateRadios.
   *
   * The fourth name on the list, `entity_bundle:node`, needs `node` and does
   * not exist here. That is finding 2 in the class docblock: the loop's
   * `isset()` guard is all that stops the missing id being an error, and the
   * list is hard-coded either way.
   */
  public function testConvertsNegateToValueElementForTheFourNamedConditionsOnly(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $entity = $this->consumer('negation', [
      'user_role' => [
        'id' => 'user_role',
        'negate' => TRUE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => [self::ROLE => self::ROLE],
      ],
      'language' => [
        'id' => 'language',
        'negate' => TRUE,
        'langcodes' => ['fr' => 'fr'],
      ],
      'response_status' => [
        'id' => 'response_status',
        'negate' => FALSE,
        'status_codes' => [404],
      ],
      'request_path' => [
        'id' => 'request_path',
        'negate' => TRUE,
        'pages' => '/negated',
      ],
    ]);
    $group = $this->buildGroup($this->formObjectFor($entity), new FormState());

    foreach (['user_role' => TRUE, 'language' => TRUE, 'response_status' => FALSE] as $conditionId => $negated) {
      $negate = $group[$conditionId]['negate'];
      $this->assertSame('value', $negate['#type'], $conditionId);
      // The value is the default that was there, not a constant.
      $this->assertSame($negated, $negate['#value'], $conditionId);
      $this->assertSame($negated, $negate['#default_value'], $conditionId);
    }

    // Finding 2: the fourth name on the list is not here, and the isset()
    // guard is the only thing that makes that harmless.
    $this->assertArrayNotHasKey('entity_bundle:node', $this->filteredDefinitions());
    $this->assertArrayNotHasKey('entity_bundle:node', $group);

    // The one condition that is not on the list keeps its control: no #value
    // was written over it and its #default_value is still what it was seeded
    // with.
    $requestPath = $group['request_path']['negate'];
    $this->assertNotSame('value', $requestPath['#type']);
    $this->assertArrayNotHasKey('#value', $requestPath);
    $this->assertArrayHasKey('#default_value', $requestPath);
  }

  /**
   * User role and request path are retitled; request path gets radios.
   *
   * Covers: "it retitles the user-role and request-path conditions and turns
   * the request-path negate into an integer-keyed radio set".
   *
   * Both retitles replace the plugin definition's own label, which is asserted
   * first so the change is visible rather than assumed, and the user-role rule
   * additionally deletes the description core writes under its roles
   * checkboxes — asserted against a bare instance of the same plugin, so the
   * description provably existed before the trait removed it.
   *
   * The radio set is the one place in the trait where a type cast is load
   * bearing: `#default_value` is cast to an integer before the options are
   * attached, because the stored value is a boolean and a boolean does not
   * match an integer radio key. `assertSame(1, ...)` is the assertion that
   * says so — `assertEquals` would pass on `TRUE` and pin nothing.
   */
  public function testRetitlesUserRoleAndRequestPathAndMakesRequestPathNegateRadios(): void {
    $definitions = $this->filteredDefinitions();
    $this->assertSame('User Role', (string) $definitions['user_role']['label']);
    $this->assertSame('Request Path', (string) $definitions['request_path']['label']);

    $entity = $this->consumer('titles', [
      'request_path' => [
        'id' => 'request_path',
        'negate' => TRUE,
        'pages' => '/titled',
      ],
    ]);
    $group = $this->buildGroup($this->formObjectFor($entity), new FormState());

    $this->assertSame('Roles', (string) $group['user_role']['#title']);
    $this->assertSame('Pages', (string) $group['request_path']['#title']);

    // The description core writes is gone — and it was there to begin with.
    $bare = $this->container->get('plugin.manager.condition')
      ->createInstance('user_role')
      ->buildConfigurationForm([], new FormState());
    $this->assertArrayHasKey('#description', $bare['roles']);
    $this->assertArrayNotHasKey('#description', $group['user_role']['roles']);

    $negate = $group['request_path']['negate'];
    $this->assertSame('radios', $negate['#type']);
    // The cast: an integer key, not the boolean the entity stores.
    $this->assertSame(1, $negate['#default_value']);
    $this->assertSame('invisible', $negate['#title_display']);
    $this->assertSame([0, 1], array_keys($negate['#options']));
    $this->assertSame(
      ['Show for the listed pages', 'Hide for the listed pages'],
      array_map('strval', $negate['#options'])
    );
    // The checkbox title survives the reshaping; only its display is hidden.
    $this->assertSame('Negate the condition', (string) $negate['#title']);

    // The other side of the cast, so zero is pinned as an integer too.
    $off = $this->consumer('titles_off', [
      'request_path' => ['id' => 'request_path', 'negate' => FALSE, 'pages' => '/x'],
    ]);
    $offGroup = $this->buildGroup($this->formObjectFor($off), new FormState());
    $this->assertSame(0, $offGroup['request_path']['negate']['#default_value']);
  }

  /**
   * Validation empties the entity, casts negate and delegates.
   *
   * Covers: "it clears the entity's visibility and casts each submitted negate
   * value to a boolean before delegating validation".
   *
   * The clear is not only the stored property: `ConfigEntityBase::set()`
   * forwards to the plugin collection for a property that is one, so the
   * memoised collection is emptied with it and `getVisibility()` answers
   * nothing at all. That is what makes submit's write the only source of the
   * entity's new conditions.
   *
   * The cast is guarded by `array_key_exists`, so a condition that submitted no
   * negate at all is left without one rather than given `FALSE`; all three
   * cases are here. `assertTrue()`/`assertFalse()` are strict — PHPUnit's
   * `IsTrue` constraint compares with `===` — which is what matters, because
   * the values going in are the integer and the string a real form hands back
   * and the schema wants booleans.
   *
   * Delegation is proved by a failure only the condition's own validator can
   * produce: `RequestPath::validateConfigurationForm()` reads `pages` from the
   * subform state, so an error named for the subform's parents is proof both
   * that the plugin ran and that it was handed a form state scoped to its own
   * slice of the values.
   */
  public function testClearsTheEntitysVisibilityAndCastsEachNegateBeforeDelegating(): void {
    $entity = $this->consumer('validated', [
      'user_role' => [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => [self::ROLE => self::ROLE],
      ],
      'request_path' => [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => '/stored',
      ],
    ]);
    $formObject = $this->formObjectFor($entity);
    $formState = new FormState();
    $form = $this->buildCompleteForm($formObject, $formState);

    $this->assertSame(
      ['user_role', 'request_path'],
      array_keys($entity->getVisibility())
    );

    $formState->setValue('visibility', [
      'user_role' => [
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => [self::ROLE => self::ROLE],
        'negate' => 1,
      ],
      'request_path' => [
        'pages' => '/submitted',
        'negate' => '0',
      ],
      // No negate key at all: the array_key_exists guard leaves it alone.
      'response_status' => [
        'status_codes' => [200 => 200, 403 => 0, 404 => 0],
      ],
    ]);
    $this->invoke($formObject, 'validateVisibility', [$form, $formState]);

    // Cleared outright — the stored property and the collection behind it.
    $this->assertSame([], $entity->get('visibility'));
    $this->assertSame([], $entity->getVisibility());
    $this->assertSame([], $entity->getVisibilityConditions()->getInstanceIds());

    // Cast to real booleans, from the integer and the string a form hands back.
    $this->assertTrue($formState->getValue(['visibility', 'user_role', 'negate']));
    $this->assertFalse($formState->getValue(['visibility', 'request_path', 'negate']));
    $this->assertArrayNotHasKey(
      'negate',
      $formState->getValue(['visibility', 'response_status'])
    );

    // Nothing the conditions objected to.
    $this->assertSame([], $formState->getErrors());

    // Delegation: only RequestPath's own validator produces this, and only a
    // form state scoped to the subform can find the value it read.
    $badObject = $this->formObjectFor($this->consumer('validated_bad'));
    $badState = new FormState();
    $badForm = $this->buildCompleteForm($badObject, $badState);
    $badState->setValue('visibility', [
      'request_path' => ['pages' => 'missing-slash', 'negate' => 0],
    ]);
    $this->invoke($badObject, 'validateVisibility', [$badForm, $badState]);

    $errors = $badState->getErrors();
    $this->assertSame(['visibility][request_path][pages'], array_keys($errors));
    $this->assertStringContainsString(
      'requires a leading forward slash',
      (string) $errors['visibility][request_path][pages']
    );
  }

  /**
   * Submission writes each condition's configuration onto the entity.
   *
   * Covers: "it writes each condition's submitted configuration back onto the
   * entity by instance id".
   *
   * Each condition's own submit handler runs first, through the same subform
   * state validation used, and the proof that it ran is a value only the
   * plugin produces: `UserRole` filters its roles and `ResponseStatus` turns
   * its checkboxes into a list of the keys that were checked. What the trait
   * then does is `addInstanceId($id, $configuration)`, so an id the entity
   * never stored becomes a new instance and one it did is reconfigured.
   *
   * Two things are pinned around the edges. The entity's stored property does
   * not move — the collection is the truth until `preSave()` copies it back,
   * which is why the save at the end is what makes the two agree. And a
   * condition submitted with nothing but its defaults keeps its instance id
   * but disappears from `getVisibility()`, because
   * `ConditionPluginCollection::getConfiguration()` drops anything equal to its
   * own defaults.
   *
   * Finding 5 closes the test: submit alone does not cast negate. Run without
   * validate, the integer a radio set hands back is stored against a schema
   * that declares boolean.
   */
  public function testWritesEachConditionsSubmittedConfigurationBackOntoTheEntityByInstanceId(): void {
    $entity = $this->consumer('submitted', [
      'user_role' => [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => ['anonymous' => 'anonymous'],
      ],
    ]);
    $formObject = $this->formObjectFor($entity);
    $formState = new FormState();
    $form = $this->buildCompleteForm($formObject, $formState);

    $formState->setValue('visibility', [
      'user_role' => [
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => [self::ROLE => self::ROLE, 'anonymous' => 0],
        'negate' => 1,
      ],
      'request_path' => [
        'pages' => '/written',
        'negate' => '0',
      ],
      'response_status' => [
        'status_codes' => [200 => 200, 403 => 0, 404 => 404],
        'negate' => 0,
      ],
    ]);
    $this->invoke($formObject, 'validateVisibility', [$form, $formState]);
    $this->invoke($formObject, 'submitVisibility', [$form, $formState]);

    // Every submitted id is an instance, including the two the entity had
    // never stored.
    $this->assertSame(
      [
        'user_role' => 'user_role',
        'request_path' => 'request_path',
        'response_status' => 'response_status',
      ],
      $entity->getVisibilityConditions()->getInstanceIds()
    );

    $visibility = $entity->getVisibility();
    $this->assertSame(
      ['user_role', 'request_path', 'response_status'],
      array_keys($visibility)
    );

    // UserRole::submitConfigurationForm() filtered the unchecked role out.
    $this->assertSame([self::ROLE => self::ROLE], $visibility['user_role']['roles']);
    $this->assertSame('user_role', $visibility['user_role']['id']);
    $this->assertTrue($visibility['user_role']['negate']);
    $this->assertSame(
      ['user' => '@user.current_user_context:current_user'],
      $visibility['user_role']['context_mapping']
    );

    $this->assertSame('/written', $visibility['request_path']['pages']);
    $this->assertFalse($visibility['request_path']['negate']);

    // ResponseStatus::submitConfigurationForm() kept the checked keys only.
    $this->assertSame([200, 404], $visibility['response_status']['status_codes']);

    // The entity's own stored value has not moved; preSave() is what copies
    // the collection back over it.
    $this->assertSame([], $entity->get('visibility'));
    $entity->save();
    // `assertEquals`, because the round trip through `preSave()` reorders the
    // keys inside each condition without changing any of them.
    $this->assertEquals($visibility, $entity->get('visibility'));

    // A condition submitted with nothing but its defaults keeps its instance
    // id and vanishes from the configuration.
    $defaults = $this->consumer('submitted_defaults');
    $defaultsObject = $this->formObjectFor($defaults);
    $defaultsState = new FormState();
    $defaultsForm = $this->buildCompleteForm($defaultsObject, $defaultsState);
    $defaultsState->setValue('visibility', [
      'request_path' => ['pages' => '', 'negate' => 0],
    ]);
    $this->invoke($defaultsObject, 'validateVisibility', [$defaultsForm, $defaultsState]);
    $this->invoke($defaultsObject, 'submitVisibility', [$defaultsForm, $defaultsState]);
    $this->assertSame(
      ['request_path' => 'request_path'],
      $defaults->getVisibilityConditions()->getInstanceIds()
    );
    $this->assertSame([], $defaults->getVisibility());

    // Finding 5: submit without validate stores the raw form value.
    $raw = $this->consumer('submitted_raw');
    $rawObject = $this->formObjectFor($raw);
    $rawState = new FormState();
    $rawForm = $this->buildCompleteForm($rawObject, $rawState);
    $rawState->setValue('visibility', [
      'request_path' => ['pages' => '/raw', 'negate' => 1],
    ]);
    $this->invoke($rawObject, 'submitVisibility', [$rawForm, $rawState]);
    $this->assertSame(1, $raw->getVisibility()['request_path']['negate']);
  }

  /**
   * Creates an unsaved plain visibility consumer.
   *
   * @param string $id
   *   The entity id.
   * @param array $visibility
   *   The condition configuration, keyed by instance id.
   *
   * @return \Drupal\neo_test\Entity\NeoTestVisibility
   *   The entity. It is deliberately not saved: the form trait reads the
   *   entity's own configuration and never its storage.
   */
  protected function consumer(string $id, array $visibility = []): NeoTestVisibility {
    return NeoTestVisibility::create([
      'id' => $id,
      'label' => $id,
      'visibility' => $visibility,
    ]);
  }

  /**
   * Returns the fixture entity form, bound to an entity.
   *
   * @param \Drupal\neo_test\Entity\NeoTestVisibility $entity
   *   The entity to edit.
   *
   * @return \Drupal\Core\Entity\EntityFormInterface
   *   The form object, which is `VisibilityFormTrait` over a four-element
   *   entity form.
   */
  protected function formObjectFor(NeoTestVisibility $entity): EntityFormInterface {
    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('neo_test_visibility', 'default');
    $formObject->setEntity($entity);
    return $formObject;
  }

  /**
   * Builds the consumer's complete form, with the parents a builder writes.
   *
   * @param \Drupal\Core\Entity\EntityFormInterface $formObject
   *   The form object.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state to build against.
   *
   * @return array
   *   The complete form array.
   */
  protected function buildCompleteForm(EntityFormInterface $formObject, FormStateInterface $formState): array {
    $form = $formObject->form([], $formState);
    $this->attachParents($form);
    return $form;
  }

  /**
   * Builds the consumer's form and returns only the visibility group.
   *
   * @param \Drupal\Core\Entity\EntityFormInterface $formObject
   *   The form object.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state to build against.
   *
   * @return array
   *   Everything `buildVisibility()` produced.
   */
  protected function buildGroup(EntityFormInterface $formObject, FormStateInterface $formState): array {
    return $this->buildCompleteForm($formObject, $formState)['visibility'];
  }

  /**
   * Writes the parents `FormBuilder::doBuildForm()` would have written.
   *
   * `SubformState::createForSubform()` throws without `#parents` on both the
   * subform and the parent form, and `SubformState::setErrorByName()` reads
   * `#array_parents`. Driving the trait's methods directly means no builder has
   * run, so the test supplies exactly what one would have.
   *
   * @param array $form
   *   The complete form, by reference.
   */
  protected function attachParents(array &$form): void {
    $form['#parents'] = [];
    $form['#array_parents'] = [];
    foreach (array_keys($form['visibility']) as $key) {
      if (str_starts_with((string) $key, '#') || $key === 'visibility_tabs') {
        continue;
      }
      $form['visibility'][$key]['#parents'] = ['visibility', $key];
      $form['visibility'][$key]['#array_parents'] = ['visibility', $key];
    }
  }

  /**
   * Returns the condition definitions the trait filters from.
   *
   * @return array
   *   The definitions `getFilteredDefinitions('neo', ...)` answers with, before
   *   the trait drops anything from them.
   */
  protected function filteredDefinitions(): array {
    return $this->container->get('plugin.manager.condition')->getFilteredDefinitions(
      'neo',
      $this->container->get('context.repository')->getAvailableContexts(),
      []
    );
  }

  /**
   * Calls a protected method of the form object.
   *
   * @param object $formObject
   *   The form object.
   * @param string $method
   *   The method name.
   * @param array $arguments
   *   The arguments to pass.
   *
   * @return mixed
   *   Whatever the method returned.
   */
  protected function invoke(object $formObject, string $method, array $arguments) {
    return (new \ReflectionMethod($formObject, $method))->invokeArgs($formObject, $arguments);
  }

}
