<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\neo\Fixtures\TestNeoForeignPluginVisibility;
use Drupal\neo_test\Entity\NeoTestPluginVisibility;
use Drupal\neo_test\Entity\NeoTestVisibility;
use Drupal\neo_test\NeoTestVisibilityPlugin;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the four escape hatches in Neo's visibility access trait.
 *
 * Ticket 08 covers the ordinary allowed and forbidden results of
 * `VisibilityEntityAccessControlTrait::checkAccess()`. This class covers what
 * happens around them, which is where the trait's real complexity lives and
 * where a wrong answer is a poisoned render cache rather than a visible bug:
 *
 * - **Missing context.** Applying a condition's context mapping raises a
 *   `ContextException` — the mapped context is not there at all — and the trait
 *   forbids **with max-age zero**, on the reasoning that a missing context
 *   means the cacheable metadata is unknown and the answer must not be cached.
 * - **Missing value.** The mapped context exists but carries no value, which
 *   raises `MissingValueContextException`, and the trait forbids **cacheably**.
 *   The two branches produce the same access answer and differ only in the
 *   max-age, which is exactly why both need a case.
 * - **The plugin delegation.** An entity that also implements
 *   `VisibilityEntityPluginInterface` and whose plugin implements
 *   `VisibilityPluginInterface` hands the decision to that plugin, asked for an
 *   object rather than a boolean. The same two exception branches sit around
 *   that call, and one shape more: a plugin of the wrong type falls back to the
 *   default instead.
 * - **The default nobody reaches.** `getDefaultVisibilityAccess()` is what the
 *   trait does when the conditions pass and there is no plugin to ask.
 *   `neo_settings` and `neo_toolbar` both override it, so the trait's own body
 *   has never run on any site. `neo_test`'s access handler deliberately does
 *   not override it, which is the only way in.
 *
 * The two consumers come from `neo_test`. The plugin-backed one reads its
 * access answer out of its own settings, so every permutation is the test's to
 * choose and two entities of the same plugin stay distinguishable. The plain
 * one is what reaches the unoverridden default. The one shape neither can
 * produce — an entity exposing a plugin of the wrong type — comes from
 * `TestNeoForeignPluginVisibility`, which exists for that arm alone.
 *
 * **Characterised, not repaired.** Five answers pinned here are worth a backlog
 * entry, and none is touched:
 *
 * 1. **`getDefaultVisibilityAccess()` passes a fourth argument to a
 *    three-parameter signature.** It calls
 *    `parent::checkAccess($entity, $operation, $account, TRUE)`, and
 *    `EntityAccessControlHandler::checkAccess()` takes exactly three
 *    parameters. PHP discards the extra argument silently. The `TRUE` reads as
 *    the `$return_as_object` flag of the *public* `access()` method, which is a
 *    different method with a different signature; it decides nothing, and the
 *    parent returns an `AccessResult` object either way. Pinned in
 *    testReturnsTheUnoverriddenDefaultForTheConsumerThatDoesNotOverrideIt.
 * 2. **The unoverridden default is an admin-permission check, so it answers
 *    `neutral` — not `forbidden` and not `allowed` — for everyone who does not
 *    hold the entity type's admin permission.** A consumer that adopts this
 *    trait and forgets to override the default therefore grants `view` to
 *    administrators and expresses no opinion about anybody else, which for a
 *    visibility entity is very unlikely to be what was meant. Both real
 *    consumers override it to `allowed`. Pinned in the same test.
 * 3. **The missing-context forbid carries no cache context at all.** The trait
 *    merges every condition's cacheability onto the result whether or not
 *    applying its mapping threw — but a condition whose mapping threw never
 *    received a context, so it has none to give, and the result comes back with
 *    the entity's config tag and nothing else. A caller who reads cacheability
 *    off this result learns nothing about what the answer depended on. The
 *    max-age of zero is what stops that mattering, and it is the only thing
 *    that does.
 * 4. **The plugin's exception branches are catch-all by plugin, not by
 *    context.** `MissingValueContextException` and `ContextException` thrown
 *    from the plugin's own `access()` — for any reason, including one having
 *    nothing to do with contexts — are turned into a forbid. A plugin that
 *    raises `ContextException` from its business logic silently makes the whole
 *    entity uncacheable.
 * 5. **A plugin of the wrong type is indistinguishable from no plugin at all.**
 *    The `else` arm falls back to the default without a warning, a log line or
 *    an exception, so a consumer whose `getPlugin()` returns the wrong thing —
 *    or NULL — gets the default's answer and no signal that its plugin was
 *    never asked.
 *
 * Assertions are against the access result object, never rendered markup.
 *
 * @see \Drupal\neo\VisibilityEntityAccessControlTrait
 * @see \Drupal\Tests\neo\Kernel\VisibilityEntityAndAccessResultTest
 */
#[Group('neo')]
final class VisibilityEscapeHatchesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `KernelTestBase` installs exactly what
   * is named and does not resolve the info file's dependency closure, so
   * `neo_build`, `neo_color` and `neo_icon` stay out.
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
   * The role the fixture conditions and the admin permission both hang off.
   */
  const ROLE = 'neo_test_staff';

  /**
   * A uid no user entity is ever created for.
   *
   * `CurrentUserContext` answers a current user it cannot load with an
   * `EntityContext` carrying no value, which is the cheapest way to reach the
   * missing-value branch without installing `node` for its route context.
   */
  const PHANTOM_UID = 99;

  /**
   * An account holding self::ROLE and the fixture's admin permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $privileged;

  /**
   * An account holding neither.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $unprivileged;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'user']);

    Role::create([
      'id' => self::ROLE,
      'label' => 'Neo test staff',
    ])->grantPermission('administer neo_test')->save();

    // Uids are explicit because core grants uid 1 every permission
    // unconditionally, which would make every permission-sensitive assertion
    // below vacuous.
    $this->privileged = User::create([
      'uid' => 2,
      'name' => 'privileged',
      'status' => 1,
    ]);
    $this->privileged->addRole(self::ROLE);
    $this->privileged->save();

    $this->unprivileged = User::create([
      'uid' => 3,
      'name' => 'unprivileged',
      'status' => 1,
    ]);
    $this->unprivileged->save();

    // The conditions resolve against the *current* user, not the account the
    // handler is asked about. Keeping the two the same is what makes the branch
    // under test, and not that disagreement, what is measured.
    \Drupal::currentUser()->setAccount($this->privileged);
  }

  /**
   * A condition whose mapped context is absent forbids uncacheably.
   *
   * Covers: "it forbids with a zero max-age when applying a condition's context
   * mapping raises a context exception".
   *
   * The instrument is a `user_role` condition mapped to a context id no
   * provider answers. `LazyContextRepository::getRuntimeContexts()` returns
   * nothing for it, so `ContextHandler::applyContextMapping()` finishes with an
   * unsatisfied mapping and throws `ContextException` — the "assigned contexts
   * were not satisfied" arm, which is the more severe of its two and is checked
   * first.
   *
   * The role the condition demands is one the current user actually holds, so a
   * mapping that resolved would have made this entity **allowed**. It is
   * forbidden instead, and the max-age is the point: zero, not
   * `Cache::PERMANENT`, and it survives both the condition cacheability merge
   * and `addCacheableDependency($entity)` afterwards, because
   * `Cache::mergeMaxAges()` takes the smaller of the two every time.
   *
   * Finding 3 in the class docblock is visible here: the condition that could
   * not be applied still contributes its cacheability to the result.
   */
  public function testForbidsWithZeroMaxAgeWhenTheConditionsContextMappingRaisesContextException(): void {
    $entity = $this->createConsumer('absent_context', [
      'user_role' => [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:neo_test_absent'],
        'roles' => [self::ROLE => self::ROLE],
      ],
    ]);

    $result = $this->accessHandler('neo_test_visibility')->access($entity, 'view', $this->privileged, TRUE);

    $this->assertTrue($result->isForbidden());
    $this->assertSame(0, $result->getCacheMaxAge());
    // The entity is still attached, so the forbid is not the disabled-entity
    // shortcut ticket 08 pins — this went the whole way through the trait.
    $this->assertSame(['config:neo_test.neo_test_visibility.absent_context'], $result->getCacheTags());
    // Finding 3: the condition is merged in all the same, and has nothing to
    // give. Applying its mapping threw before the plugin was handed a context,
    // so the `user.roles` a working `user_role` contributes is absent and the
    // forbid records nothing about what it depended on.
    $this->assertSame([], $result->getCacheContexts());

    // The same entity with the mapping the condition actually wants is allowed,
    // which is what makes the mapping — and not the role, the account or the
    // entity — the only variable in the branch above.
    $ok = $this->createConsumer('present_context', $this->userRoleCondition([self::ROLE]));
    $allowed = $this->accessHandler('neo_test_visibility')->access($ok, 'view', $this->privileged, TRUE);
    $this->assertTrue($allowed->isAllowed());
    $this->assertSame(Cache::PERMANENT, $allowed->getCacheMaxAge());
  }

  /**
   * A condition whose mapped context has no value forbids cacheably.
   *
   * Covers: "it forbids while staying cacheable when a condition's context
   * exists but carries no value".
   *
   * The canonical case is core's node-type condition on a non-node route, whose
   * `@node.node_route_context:node` exists and is empty. Reaching it would mean
   * installing `node` and its entity schema into a `neo` Kernel test to assert
   * one `catch`, so the instrument here is the same shape one module down:
   * `CurrentUserContext` hands back `EntityContext::fromEntityTypeId('user')` —
   * a context object with no value — whenever it cannot load the current user.
   * A current user whose account has no user entity therefore produces exactly
   * the state the branch is written for.
   *
   * The mapping is the one `user_role` is configured with everywhere, unchanged
   * from the test above; only the context's value is gone. That is the whole
   * distinction between the two branches, and the result is the whole
   * difference it makes: forbidden either way, cacheable here and not there.
   *
   * The context repository memoises every context id it resolves for the
   * lifetime of the request, so this test cannot also assert the valued case —
   * the first answer would be handed back to the second. The valued case is
   * asserted in the test above, on its own request.
   */
  public function testForbidsCacheablyWhenTheConditionsContextExistsButCarriesNoValue(): void {
    // A current user the user storage cannot load. Set before anything resolves
    // a context, because the repository caches what it resolves.
    \Drupal::currentUser()->setAccount(new UserSession(['uid' => self::PHANTOM_UID]));
    $this->assertNull(User::load(self::PHANTOM_UID));

    $entity = $this->createConsumer('valueless_context', $this->userRoleCondition([self::ROLE]));

    $result = $this->accessHandler('neo_test_visibility')->access($entity, 'view', $this->privileged, TRUE);

    $this->assertTrue($result->isForbidden());
    // The whole point of the branch: this answer is safe to cache, where the
    // missing-context answer above is not.
    $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
    $this->assertSame(['config:neo_test.neo_test_visibility.valueless_context'], $result->getCacheTags());
    // Unlike the missing-context branch, the context object was found and
    // applied before its emptiness was noticed, so the condition does have
    // cacheability to contribute: `UserRole::getCacheContexts()` rewrites the
    // context's own `user` to `user.roles`. No `user:2` tag, though — that one
    // comes from the loaded user entity, and there was none to load.
    $this->assertSame(['user.roles'], $result->getCacheContexts());
  }

  /**
   * The plugin is asked for an object, and its answer is the result.
   *
   * Covers: "it asks the entity's plugin for an access object when the
   * conditions pass and the plugin implements the visibility interface".
   *
   * Three things say the plugin was asked with `$return_as_object = TRUE`
   * rather than for a boolean:
   *
   * - a `neutral` answer arrives as neutral. A boolean could not carry it:
   *   `AccessResultInterface::isAllowed()` is FALSE for neutral and forbidden
   *   alike, so the two would collapse into one.
   * - the plugin's own cache tag is on the result. A boolean carries no
   *   cacheability at all, and the trait adds none of its own.
   * - the tag names the entity, so the answer is provably *this* entity's
   *   plugin. Two entities of the same plugin class declaring different answers
   *   stay distinguishable, which is the whole reason the fixture declares its
   *   answer per entity rather than per plugin.
   *
   * The conditions genuinely pass rather than being absent: the allowed entity
   * carries a `user_role` condition the current user satisfies, so the branch
   * is reached the way the trait means it to be, and the condition's
   * `user.roles` context is merged in beside the plugin's tag.
   */
  public function testAsksThePluginForAnAccessObjectWhenTheConditionsPassAndItImplementsTheInterface(): void {
    $allowed = $this->accessHandler('neo_test_plugin_visibility')->access(
      $this->createPluginConsumer('plugin_allows', NeoTestVisibilityPlugin::ALLOWED, $this->userRoleCondition([self::ROLE])),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($allowed->isAllowed());
    // The plugin's tag first, then the condition's, then the entity's: the
    // order the merge ran in, since neither `Cache::mergeTags()` nor
    // `Cache::mergeContexts()` sorts.
    $this->assertSame([
      NeoTestVisibilityPlugin::cacheTag('plugin_allows'),
      'user:2',
      'config:neo_test.neo_test_plugin_visibility.plugin_allows',
    ], $allowed->getCacheTags());
    $this->assertSame(['user.roles'], $allowed->getCacheContexts());

    // A forbid from the plugin is the plugin's, not the conditions': the same
    // passing condition sits underneath it.
    $forbidden = $this->accessHandler('neo_test_plugin_visibility')->access(
      $this->createPluginConsumer('plugin_forbids', NeoTestVisibilityPlugin::FORBIDDEN, $this->userRoleCondition([self::ROLE])),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($forbidden->isForbidden());
    $this->assertContains(NeoTestVisibilityPlugin::cacheTag('plugin_forbids'), $forbidden->getCacheTags());

    // Neutral is the one a boolean could not have carried.
    $neutral = $this->accessHandler('neo_test_plugin_visibility')->access(
      $this->createPluginConsumer('plugin_neutral', NeoTestVisibilityPlugin::NEUTRAL),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($neutral->isNeutral());
    $this->assertFalse($neutral->isAllowed());
    $this->assertFalse($neutral->isForbidden());
    $this->assertContains(NeoTestVisibilityPlugin::cacheTag('plugin_neutral'), $neutral->getCacheTags());

    // The default is not what answered any of the three: it would have added
    // `user.permissions`, and it cannot produce a forbid at all.
    $this->assertNotContains('user.permissions', $allowed->getCacheContexts());
    $this->assertNotContains('user.permissions', $neutral->getCacheContexts());
  }

  /**
   * The plugin's two exception branches mirror the conditions' two.
   *
   * Covers: "it forbids with a zero max-age on a context exception from the
   * plugin and cacheably on a missing-value exception".
   *
   * Same pair of exceptions, same pair of answers, a second time — thrown from
   * the plugin's own `access()` rather than from applying a context mapping.
   * The catches are ordered the same way for the same reason:
   * `MissingValueContextException` extends `ContextException`, so the specific
   * one has to be caught first or the general one would swallow it and every
   * missing value would become an uncacheable forbid.
   *
   * Neither entity carries a condition, so the max-age on each result is the
   * branch's own and nothing else's; and neither result carries the plugin's
   * cache tag, because the fixture plugin throws before it gets as far as
   * adding one. That absence is the proof the exception path ran rather than
   * the ordinary one.
   *
   * Finding 4 in the class docblock: nothing here checks that the exception has
   * anything to do with a context. Any `ContextException` a plugin raises for
   * any reason becomes a forbid the site may not cache.
   */
  public function testForbidsWithZeroMaxAgeOnPluginContextExceptionAndCacheablyOnMissingValue(): void {
    $missingContext = $this->accessHandler('neo_test_plugin_visibility')->access(
      $this->createPluginConsumer('plugin_context', NeoTestVisibilityPlugin::MISSING_CONTEXT),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($missingContext->isForbidden());
    $this->assertSame(0, $missingContext->getCacheMaxAge());
    $this->assertSame(
      ['config:neo_test.neo_test_plugin_visibility.plugin_context'],
      $missingContext->getCacheTags()
    );
    $this->assertNotContains(NeoTestVisibilityPlugin::cacheTag('plugin_context'), $missingContext->getCacheTags());

    $missingValue = $this->accessHandler('neo_test_plugin_visibility')->access(
      $this->createPluginConsumer('plugin_value', NeoTestVisibilityPlugin::MISSING_VALUE),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($missingValue->isForbidden());
    $this->assertSame(Cache::PERMANENT, $missingValue->getCacheMaxAge());
    $this->assertSame(
      ['config:neo_test.neo_test_plugin_visibility.plugin_value'],
      $missingValue->getCacheTags()
    );
    $this->assertNotContains(NeoTestVisibilityPlugin::cacheTag('plugin_value'), $missingValue->getCacheTags());

    // The two forbids are the same access answer. The max-age is the only thing
    // that tells them apart, which is why both branches exist.
    $this->assertSame($missingContext->isForbidden(), $missingValue->isForbidden());
    $this->assertNotSame($missingContext->getCacheMaxAge(), $missingValue->getCacheMaxAge());
  }

  /**
   * A plugin of the wrong type is not asked at all.
   *
   * Covers: "it falls back to the default when the entity exposes a plugin that
   * does not implement the visibility interface".
   *
   * The entity implements `VisibilityEntityPluginInterface`, so the trait
   * enters the delegation branch and calls `getPlugin()`. What comes back is
   * not a `VisibilityPluginInterface`, so the answer falls through to
   * `getDefaultVisibilityAccess()` — the same place an entity with no plugin
   * interface at all lands. The proof is that the answer tracks the *account's*
   * admin permission rather than anything the plugin could have said: allowed
   * for the privileged account, neutral for the unprivileged one, with
   * `user.permissions` on both.
   *
   * `NULL` is checked as well as an unrelated object, because a `getPlugin()`
   * that has not been configured yet is by far the likeliest way a real
   * consumer reaches this arm, and `NULL instanceof VisibilityPluginInterface`
   * is FALSE exactly like `stdClass` is.
   *
   * Finding 5 in the class docblock: nothing distinguishes this from having no
   * plugin. No warning, no log, no exception.
   */
  public function testFallsBackToTheDefaultWhenThePluginDoesNotImplementTheVisibilityInterface(): void {
    $handler = $this->accessHandler('neo_test_plugin_visibility');

    $foreign = $this->foreignConsumer('foreign_plugin', new \stdClass());
    $allowed = $handler->access($foreign, 'view', $this->privileged, TRUE);
    $this->assertTrue($allowed->isAllowed());
    $this->assertSame(['user.permissions'], $allowed->getCacheContexts());
    $this->assertSame(['config:neo_test.neo_test_plugin_visibility.foreign_plugin'], $allowed->getCacheTags());

    $neutral = $handler->access($this->foreignConsumer('foreign_denied', new \stdClass()), 'view', $this->unprivileged, TRUE);
    $this->assertTrue($neutral->isNeutral());
    $this->assertSame(['user.permissions'], $neutral->getCacheContexts());

    // No plugin at all takes the same arm.
    $none = $handler->access($this->foreignConsumer('no_plugin', NULL), 'view', $this->privileged, TRUE);
    $this->assertTrue($none->isAllowed());
    $this->assertSame(['user.permissions'], $none->getCacheContexts());

    // And the answer is the default's, not a plugin's: an entity of the same
    // shape whose plugin *does* implement the interface answers `forbidden` for
    // the very same privileged account.
    $real = $this->accessHandler('neo_test_plugin_visibility')->access(
      $this->createPluginConsumer('real_plugin', NeoTestVisibilityPlugin::FORBIDDEN),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($real->isForbidden());
  }

  /**
   * The default the whole stack overrides away.
   *
   * Covers: "it returns the unoverridden default for a consumer that does not
   * override it".
   *
   * `getDefaultVisibilityAccess()` is one line —
   * `parent::checkAccess($entity, $operation, $account, TRUE)` — and
   * `neo_settings` and `neo_toolbar` both replace it, so that line has never
   * run on a site. `neo_test`'s handler leaves it alone, which is the only way
   * to say what it does.
   *
   * What it does is core's `EntityAccessControlHandler::checkAccess()`: an
   * admin-permission check against the entity type's `admin_permission`. So the
   * default is `allowed` for an account holding `administer neo_test` and
   * **neutral** for one that does not — finding 2 in the class docblock, and a
   * surprising default for a trait whose job is deciding whether something is
   * visible: a consumer that forgets to override it shows the entity to
   * administrators and expresses no opinion at all about anyone else.
   *
   * Finding 1 is asserted structurally rather than by its effect, because it
   * has none: `EntityAccessControlHandler::checkAccess()` declares three
   * parameters, the trait passes four, and PHP discards the extra one without a
   * notice. The `TRUE` reads as `$return_as_object` — a parameter of the
   * *public* `access()`, which is a different method. Whatever it was meant to
   * do, the parent returns an `AccessResult` object regardless, and it is
   * asserted here that it does.
   */
  public function testReturnsTheUnoverriddenDefaultForTheConsumerThatDoesNotOverrideIt(): void {
    $handler = $this->accessHandler('neo_test_visibility');

    // Finding 1: the argument the trait passes has nowhere to land.
    $parent = new \ReflectionMethod(EntityAccessControlHandler::class, 'checkAccess');
    $this->assertSame(3, $parent->getNumberOfParameters());
    $this->assertSame(
      ['entity', 'operation', 'account'],
      array_map(static fn ($parameter) => $parameter->getName(), $parent->getParameters())
    );
    $this->assertFalse($parent->isVariadic());

    // The trait's own body, called directly, with nothing merged onto it yet.
    $default = new \ReflectionMethod($handler, 'getDefaultVisibilityAccess');
    $entity = $this->createConsumer('default_branch');

    $granted = $default->invoke($handler, $entity, 'view', $this->privileged);
    $this->assertInstanceOf(AccessResult::class, $granted);
    $this->assertTrue($granted->isAllowed());
    $this->assertSame(['user.permissions'], $granted->getCacheContexts());
    $this->assertSame([], $granted->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $granted->getCacheMaxAge());

    // Finding 2: not forbidden. No opinion.
    $withheld = $default->invoke($handler, $entity, 'view', $this->unprivileged);
    $this->assertInstanceOf(AccessResult::class, $withheld);
    $this->assertTrue($withheld->isNeutral());
    $this->assertFalse($withheld->isForbidden());
    $this->assertSame(['user.permissions'], $withheld->getCacheContexts());

    // End to end, the neutral survives: an entity whose conditions all pass is
    // still not viewable by an account without the admin permission, and the
    // caller is told nothing more than "no opinion".
    $result = $handler->access($entity, 'view', $this->unprivileged, TRUE);
    $this->assertTrue($result->isNeutral());
    $this->assertFalse($result->isAllowed());
    $this->assertSame(['user.permissions'], $result->getCacheContexts());
    $this->assertSame(['config:neo_test.neo_test_visibility.default_branch'], $result->getCacheTags());
  }

  /**
   * Returns the access control handler for a fixture entity type.
   *
   * @param string $entity_type_id
   *   The fixture entity type id.
   *
   * @return \Drupal\Core\Entity\EntityAccessControlHandlerInterface
   *   The fixture handler, which is `VisibilityEntityAccessControlTrait` and
   *   nothing else.
   */
  protected function accessHandler(string $entity_type_id) {
    return $this->container->get('entity_type.manager')
      ->getAccessControlHandler($entity_type_id);
  }

  /**
   * Creates and saves a plain visibility consumer.
   *
   * @param string $id
   *   The entity id.
   * @param array $visibility
   *   The condition configuration, keyed by instance id.
   *
   * @return \Drupal\neo_test\Entity\NeoTestVisibility
   *   The saved entity.
   */
  protected function createConsumer(string $id, array $visibility = []): NeoTestVisibility {
    $entity = NeoTestVisibility::create([
      'id' => $id,
      'label' => $id,
      'visibility' => $visibility,
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Creates and saves a plugin-backed visibility consumer.
   *
   * @param string $id
   *   The entity id, which is also what the plugin's cache tag names.
   * @param string $answer
   *   One of `NeoTestVisibilityPlugin`'s five declared answers.
   * @param array $visibility
   *   The condition configuration, keyed by instance id.
   *
   * @return \Drupal\neo_test\Entity\NeoTestPluginVisibility
   *   The saved entity.
   */
  protected function createPluginConsumer(string $id, string $answer, array $visibility = []): NeoTestPluginVisibility {
    $entity = NeoTestPluginVisibility::create([
      'id' => $id,
      'label' => $id,
      'settings' => ['access' => $answer],
      'visibility' => $visibility,
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Builds an unsaved consumer exposing a plugin of the wrong type.
   *
   * @param string $id
   *   The entity id.
   * @param mixed $plugin
   *   The object — or NULL — that `getPlugin()` hands back.
   *
   * @return \Drupal\Tests\neo\Fixtures\TestNeoForeignPluginVisibility
   *   The entity. It is never saved: no storage would accept a class the entity
   *   type does not declare, and nothing on the path into `checkAccess()` reads
   *   it from storage.
   */
  protected function foreignConsumer(string $id, $plugin): TestNeoForeignPluginVisibility {
    $entity = new TestNeoForeignPluginVisibility([
      'id' => $id,
      'label' => $id,
    ], 'neo_test_plugin_visibility');
    return $entity->setPlugin($plugin);
  }

  /**
   * Builds a user_role condition mapped to the current user.
   *
   * @param string[] $roles
   *   The roles the current user must hold for the condition to pass.
   *
   * @return array
   *   A `visibility` fragment keyed by instance id.
   */
  protected function userRoleCondition(array $roles): array {
    return [
      'user_role' => [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => array_combine($roles, $roles),
      ],
    ];
  }

}
