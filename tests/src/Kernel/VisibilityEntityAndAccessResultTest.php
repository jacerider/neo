<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Condition\ConditionInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_test\Entity\NeoTestVisibility;
use Drupal\system\Plugin\Condition\ResponseStatus;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the ordinary path of Neo's visibility pair.
 *
 * Two of the four traits this plan covers meet here, and both ship to
 * `neo_settings`, `neo_toolbar`, `neo_site_settings` and `neo_image` without a
 * test anywhere:
 *
 * - `VisibilityEntityTrait` is the configuration surface. A config entity that
 *   uses it carries its condition configuration through a lazily built
 *   `ConditionPluginCollection`, so what a caller reads back is a **round trip
 *   through that collection** rather than the raw stored array.
 * - `VisibilityEntityAccessControlTrait` is the answer. For `view` it refuses a
 *   disabled entity outright, resolves the conditions with an `and`
 *   conjunction, and — whatever the outcome — merges every condition's cache
 *   tags, contexts and max-age onto the result before attaching the entity
 *   itself. That merge is the reason the trait exists rather than a plain
 *   permission check, and it is what a `neo_toolbar` or `neo_settings` render
 *   cache depends on.
 *
 * The consumer is `neo_test`'s plain visibility consumer, which deliberately
 * does not implement `VisibilityEntityPluginInterface` and whose handler
 * deliberately does not override `getDefaultVisibilityAccess()`. The conditions
 * are real: core's `user_role`, which needs no route and carries genuine cache
 * contexts and tags, and core's `request_path`, negated so that it passes on
 * any path while still contributing `url.path`.
 *
 * Ticket 09 takes the branches this test leaves alone — the missing-context and
 * missing-value escape hatches, the plugin delegation, and the unoverridden
 * default read as a seam in its own right.
 *
 * **Characterised, not repaired.** Four answers pinned here are quirks rather
 * than intentions, and each is a backlog candidate:
 *
 * 1. **`VisibilityEntityTrait::$visibility` is not vestigial.**
 *    `ConfigEntityBase::get()` is `return $this->{$property_name} ?? NULL`, so
 *    `getVisibilityConditions()`'s `$this->get('visibility')` reads exactly
 *    that property. It is the entity's storage, not a leftover, and writing it
 *    before the collection is built changes what the collection contains.
 *    Ticket 01 found the same thing from the other side. Pinned in
 *    testTheConditionCollectionIsBuiltFromTheTraitsOwnVisibilityProperty.
 * 2. **`setVisibilityConfig()` writes the plugin id in on the add branch and
 *    not on the replace branch**, and the replace branch reaches for that id on
 *    the very next line. Replacing the configuration of a stored condition
 *    whose plugin has not yet been instantiated — the state of every freshly
 *    loaded entity — therefore throws `PluginNotFoundException` unless the
 *    caller happens to have passed an `id` of their own. Pinned in
 *    testSetVisibilityConfigAddsAnInstanceWithItsIdAndReplacesOneAlreadyThere.
 * 3. **`setVisibilityConfig()` does not update the entity's stored value.** It
 *    mutates the collection only; `get('visibility')` still answers the old
 *    array until `ConfigEntityBase::preSave()` copies the collection back over
 *    it. A caller who sets a condition and reads `get('visibility')` without
 *    saving sees nothing. Pinned in the same test.
 * 4. **The conditions are resolved against the *current* user, not the account
 *    being asked about.** `user_role` takes its user from
 *    `@user.current_user_context:current_user`, while the default access the
 *    trait falls through to answers for the `$account` argument. The two can
 *    disagree; every test below keeps them the same account so the disagreement
 *    is not what is being measured, and it is named here rather than asserted.
 *
 * Assertions are against the entity's methods and the access result object,
 * never rendered markup.
 */
#[Group('neo')]
final class VisibilityEntityAndAccessResultTest extends KernelTestBase {

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
    // `system.site` is what `request_path` resolves `<front>` against, and
    // `user`'s roles are what `user_role` answers from.
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
    $this->assertSame(['authenticated'], $this->unprivileged->getRoles());

    // `user_role` resolves its user from the container, not from the account
    // the access handler is asked about. Keeping the two the same is what
    // makes the conjunction, and not the disagreement, what is measured.
    \Drupal::currentUser()->setAccount($this->privileged);
  }

  /**
   * The visibility a caller reads is the collection's configuration.
   *
   * Covers: "it returns the condition collection's configuration as the
   * entity's visibility".
   *
   * `getVisibility()` is `getVisibilityConditions()->getConfiguration()`, so
   * every value has been through a plugin instance and back. The proof is a
   * key the caller never stored: `negate` is part of `ConditionPluginBase`'s
   * default configuration, so the round trip carries it even though the raw
   * stored array does not.
   */
  public function testVisibilityIsTheCollectionsConfigurationNotTheStoredArray(): void {
    $stored = [
      'user_role' => [
        'id' => 'user_role',
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => [self::ROLE => self::ROLE],
      ],
    ];
    $entity = NeoTestVisibility::create([
      'id' => 'round_trip',
      'label' => 'Round trip',
      'visibility' => $stored,
    ]);

    // The stored array is exactly what was handed in.
    $this->assertSame($stored, $entity->get('visibility'));
    $this->assertArrayNotHasKey('negate', $entity->get('visibility')['user_role']);

    $visibility = $entity->getVisibility();
    $this->assertSame($entity->getVisibilityConditions()->getConfiguration(), $visibility);
    $this->assertSame(['user_role'], array_keys($visibility));
    $this->assertSame('user_role', $visibility['user_role']['id']);
    $this->assertSame([self::ROLE => self::ROLE], $visibility['user_role']['roles']);
    // The key the round trip added.
    $this->assertArrayHasKey('negate', $visibility['user_role']);
    $this->assertFalse($visibility['user_role']['negate']);

    // Saving copies the round trip back over the stored array, so from then on
    // the two agree — `ConfigEntityBase::preSave()` does that for every
    // `EntityWithPluginCollectionInterface`.
    $entity->save();
    $reloaded = NeoTestVisibility::load('round_trip');
    $this->assertEquals($visibility, $reloaded->get('visibility'));
    $this->assertEquals($visibility, $reloaded->getVisibility());
  }

  /**
   * Adding a condition writes its id in; replacing one does not.
   *
   * Covers: "it adds a new condition instance with its id written in, and
   * replaces the configuration of one already present".
   *
   * The two branches of `setVisibilityConfig()` are told apart three ways: the
   * add branch grows the collection's instance ids, it writes `id` into the
   * configuration for the caller, and it is the only one that can be handed a
   * configuration without one. The replace branch reconfigures the same plugin
   * object in place — and, because it does **not** write the id in, it throws
   * `PluginNotFoundException` when the plugin has not been built yet, which is
   * the state of every entity that was just loaded. That is finding 2 in the
   * class docblock.
   *
   * Neither branch touches the entity's stored value: that is finding 3.
   */
  public function testSetVisibilityConfigAddsAnInstanceWithItsIdAndReplacesOneAlreadyThere(): void {
    $entity = NeoTestVisibility::create([
      'id' => 'set_config',
      'label' => 'Set config',
    ]);
    $conditions = $entity->getVisibilityConditions();
    $this->assertSame([], $conditions->getInstanceIds());

    // The add branch. The caller omits `id`; the trait writes it in, which is
    // the only reason the collection can build the plugin at all.
    $returned = $entity->setVisibilityConfig('response_status', ['status_codes' => [200]]);
    $this->assertSame($entity, $returned);
    $this->assertSame(['response_status' => 'response_status'], $conditions->getInstanceIds());
    $this->assertSame('response_status', $entity->getVisibility()['response_status']['id']);
    $this->assertSame([200], $entity->getVisibility()['response_status']['status_codes']);
    $instance = $entity->getVisibilityCondition('response_status');
    $this->assertInstanceOf(ResponseStatus::class, $instance);

    // The replace branch. No new instance id, and the very same plugin object
    // carries the new configuration.
    $entity->setVisibilityConfig('response_status', [
      'id' => 'response_status',
      'status_codes' => [404],
    ]);
    $this->assertSame(['response_status' => 'response_status'], $conditions->getInstanceIds());
    $this->assertSame($instance, $entity->getVisibilityCondition('response_status'));
    $this->assertSame([404], $entity->getVisibility()['response_status']['status_codes']);

    // Finding 3: the entity's own stored value has not moved at all.
    $this->assertSame([], $entity->get('visibility'));
    $entity->save();
    $this->assertSame([404], $entity->get('visibility')['response_status']['status_codes']);

    // Finding 2: on a freshly loaded entity nothing has been instantiated, so
    // the replace branch's `$this->configurations[$id] = $configuration` drops
    // the plugin id and the collection cannot find the plugin on the next line.
    $fresh = NeoTestVisibility::load('set_config');
    try {
      $fresh->setVisibilityConfig('response_status', ['status_codes' => [403]]);
      $this->fail('Replacing an uninstantiated condition without an id was expected to throw.');
    }
    catch (PluginNotFoundException $e) {
      $this->assertSame("Plugin ID 'response_status' was not found.", $e->getMessage());
    }
  }

  /**
   * The collection is built from the trait's own visibility property.
   *
   * Covers: "it builds the condition collection from the entity's stored value
   * and ignores the trait's own visibility property" — **refuted**, and pinned
   * as it actually behaves. This is finding 1 in the class docblock.
   *
   * `getVisibilityConditions()` builds its collection from
   * `$this->get('visibility')`, and `ConfigEntityBase::get()` is a plain
   * property read, so "the entity's stored value" and "the trait's own
   * visibility property" are the same thing. The property is not vestigial and
   * writing it is not inert: it changes what the collection contains, right up
   * until the collection is memoised on `$visibilityCollection`. After that,
   * and only after that, writing it does nothing.
   */
  public function testTheConditionCollectionIsBuiltFromTheTraitsOwnVisibilityProperty(): void {
    $stored = $this->userRoleCondition([self::ROLE]);
    $entity = NeoTestVisibility::create([
      'id' => 'property',
      'label' => 'Property',
      'visibility' => $stored,
    ]);
    $property = new \ReflectionProperty($entity, 'visibility');

    // The property is the storage `get('visibility')` reads.
    $this->assertSame($stored, $property->getValue($entity));
    $this->assertSame($entity->get('visibility'), $property->getValue($entity));

    // Written before the collection is built, it decides what the collection
    // contains. Nothing about it is vestigial.
    $property->setValue($entity, $this->requestPathCondition('/nowhere'));
    $this->assertSame(['request_path'], array_keys($entity->getVisibility()));
    $this->assertSame('/nowhere', $entity->getVisibility()['request_path']['pages']);

    // Written after, it is inert — but that is the collection's memo, not the
    // property being unread.
    $property->setValue($entity, $this->userRoleCondition([self::ROLE]));
    $this->assertSame(['request_path'], array_keys($entity->getVisibility()));
    $this->assertSame($this->userRoleCondition([self::ROLE]), $entity->get('visibility'));
  }

  /**
   * Any operation other than view goes straight to the parent handler.
   *
   * Covers: "it falls through to the parent handler for any operation other
   * than view".
   *
   * The entity is rigged so that the visibility path could not possibly answer
   * the way the parent does: it is disabled, which the view path refuses
   * outright, and it carries a condition the current user fails. `update` is
   * allowed anyway for an account holding the entity type's admin permission,
   * and `delete` is neutral for one that does not — the parent handler
   * answering `allowedIfHasPermission()` and nothing else. The cacheability is
   * the second half of the proof: only `user.permissions` is present, so
   * neither the conditions' contexts nor the trait's own
   * `addCacheableDependency($entity)` ran.
   */
  public function testFallsThroughToTheParentHandlerForEveryOperationOtherThanView(): void {
    $entity = $this->createConsumer('non_view', $this->userRoleCondition(['neo_test_absent']));
    $entity->disable();
    $entity->save();

    $update = $this->accessHandler()->access($entity, 'update', $this->privileged, TRUE);
    $this->assertTrue($update->isAllowed());
    $this->assertSame(['user.permissions'], $update->getCacheContexts());
    $this->assertSame([], $update->getCacheTags());

    $delete = $this->accessHandler()->access($entity, 'delete', $this->unprivileged, TRUE);
    $this->assertTrue($delete->isNeutral());
    $this->assertSame(['user.permissions'], $delete->getCacheContexts());
    $this->assertSame([], $delete->getCacheTags());

    // The same entity, the same account, asked for `view`: the visibility path
    // does answer, and it answers differently.
    $view = $this->accessHandler()->access($entity, 'view', $this->privileged, TRUE);
    $this->assertTrue($view->isForbidden());
  }

  /**
   * A disabled config entity is refused before its conditions are read.
   *
   * Covers: "it forbids a disabled config entity and attaches the entity as a
   * cacheable dependency".
   *
   * The refusal is the first thing the view path does, so the conditions are
   * never built: the result carries the entity's own config cache tag and
   * nothing else. `user.roles`, which the entity's `user_role` condition would
   * have contributed, is absent — which is what makes "outright" measurable.
   */
  public function testForbidsDisabledEntityAndAttachesItAsCacheableDependency(): void {
    $entity = $this->createConsumer('disabled', $this->userRoleCondition([self::ROLE]));
    $entity->disable();
    $entity->save();

    $result = $this->accessHandler()->access($entity, 'view', $this->privileged, TRUE);

    $this->assertTrue($result->isForbidden());
    $this->assertSame(['config:neo_test.neo_test_visibility.disabled'], $result->getCacheTags());
    $this->assertSame([], $result->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
  }

  /**
   * Conditions resolve with an "and" conjunction.
   *
   * Covers: "it allows when every condition allows, forbids when one refuses,
   * and allows an entity carrying no conditions".
   *
   * Every entity here carries the same two conditions except for the role the
   * first one demands, so the only variable is whether one condition refuses.
   * `resolveConditions($conditions, 'and')` short-circuits on the first refusal
   * — which is why the cacheability merge is driven from the full condition
   * array built before it, not from the conditions it actually executed.
   *
   * An entity with no conditions at all takes the allowed path too:
   * `resolveConditions([], 'and')` returns TRUE, and the trait falls through to
   * the default, which for this handler is the parent's admin-permission check.
   */
  public function testAllowsWhenEveryConditionAllowsForbidsWhenOneRefusesAndAllowsWithNone(): void {
    $both = $this->createConsumer(
      'both_allow',
      $this->userRoleCondition([self::ROLE]) + $this->requestPathCondition('/nowhere', TRUE)
    );
    $allowed = $this->accessHandler()->access($both, 'view', $this->privileged, TRUE);
    $this->assertTrue($allowed->isAllowed());

    $one = $this->createConsumer(
      'one_refuses',
      $this->userRoleCondition(['neo_test_absent']) + $this->requestPathCondition('/nowhere', TRUE)
    );
    $forbidden = $this->accessHandler()->access($one, 'view', $this->privileged, TRUE);
    $this->assertTrue($forbidden->isForbidden());

    $none = $this->createConsumer('no_conditions');
    $this->assertSame([], $none->getVisibility());
    $empty = $this->accessHandler()->access($none, 'view', $this->privileged, TRUE);
    $this->assertTrue($empty->isAllowed());
    // Nothing but the default's own permission context: no condition ran.
    $this->assertSame(['user.permissions'], $empty->getCacheContexts());
    $this->assertSame(['config:neo_test.neo_test_visibility.no_conditions'], $empty->getCacheTags());
  }

  /**
   * Every condition's cacheability lands on the result.
   *
   * Covers: "it merges each condition's cache tags, contexts and max-age onto
   * the access result".
   *
   * Two halves. End to end first, because that is what a render cache actually
   * sees: `user_role` contributes `user.roles` and — through the current-user
   * context object it was handed — the `user:2` tag of the account it resolved
   * against, `request_path` contributes `url.path`, and the entity contributes
   * its own config tag. The merge happens whatever the outcome, so the refusing
   * entity carries exactly the same condition cacheability as the allowing one.
   *
   * Then the merge itself, driven directly with condition doubles, because no
   * condition core ships declares a finite max-age and the max-age arm would
   * otherwise never be exercised. Two doubles, so that "merge" is distinguished
   * from "overwrite", and `Cache::mergeMaxAges()` takes the smaller of the two.
   */
  public function testMergesEachConditionsCacheTagsContextsAndMaxAgeOntoTheResult(): void {
    $visibility = $this->userRoleCondition([self::ROLE]) + $this->requestPathCondition('/nowhere', TRUE);

    $allowed = $this->accessHandler()->access(
      $this->createConsumer('merged_allowed', $visibility),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($allowed->isAllowed());
    // Neither list is sorted: `Cache::mergeContexts()` and `Cache::mergeTags()`
    // only de-duplicate, so the order is the order the merge ran in — the
    // default's own context, then each condition, then the entity.
    $this->assertSame(['user.permissions', 'user.roles', 'url.path'], $allowed->getCacheContexts());
    $this->assertSame([
      'user:2',
      'config:neo_test.neo_test_visibility.merged_allowed',
    ], $allowed->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $allowed->getCacheMaxAge());

    // The refusal never reaches the default, so `user.permissions` is gone —
    // but every condition's own cacheability is merged just the same.
    $refused = $this->accessHandler()->access(
      $this->createConsumer('merged_refused', $this->userRoleCondition(['neo_test_absent']) + $this->requestPathCondition('/nowhere', TRUE)),
      'view',
      $this->privileged,
      TRUE
    );
    $this->assertTrue($refused->isForbidden());
    $this->assertSame(['user.roles', 'url.path'], $refused->getCacheContexts());
    $this->assertSame([
      'user:2',
      'config:neo_test.neo_test_visibility.merged_refused',
    ], $refused->getCacheTags());

    // The merge itself, with the max-age arm no core condition can reach.
    $access = AccessResult::allowed()->addCacheTags(['neo_test:already_there']);
    $merge = new \ReflectionMethod($this->accessHandler(), 'mergeCacheabilityFromConditions');
    $merge->invoke($this->accessHandler(), $access, [
      'first' => $this->conditionDouble(['neo_test:first'], ['languages:language_interface'], 60),
      'second' => $this->conditionDouble(['neo_test:second'], ['theme'], 30),
    ]);

    $this->assertSame([
      'neo_test:already_there',
      'neo_test:first',
      'neo_test:second',
    ], $access->getCacheTags());
    $this->assertSame(['languages:language_interface', 'theme'], $access->getCacheContexts());
    $this->assertSame(30, $access->getCacheMaxAge());
  }

  /**
   * Returns the access control handler for the plain visibility consumer.
   *
   * @return \Drupal\Core\Entity\EntityAccessControlHandlerInterface
   *   The fixture handler, which is `VisibilityEntityAccessControlTrait` and
   *   nothing else.
   */
  protected function accessHandler() {
    return $this->container->get('entity_type.manager')
      ->getAccessControlHandler('neo_test_visibility');
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

  /**
   * Builds a request_path condition.
   *
   * @param string $pages
   *   The path pattern.
   * @param bool $negate
   *   Whether to negate it. Negated against a pattern nothing matches, the
   *   condition passes on every path while still contributing `url.path`,
   *   which is what makes it usable as the second half of a conjunction.
   *
   * @return array
   *   A `visibility` fragment keyed by instance id.
   */
  protected function requestPathCondition(string $pages, bool $negate = FALSE): array {
    return [
      'request_path' => [
        'id' => 'request_path',
        'negate' => $negate,
        'pages' => $pages,
      ],
    ];
  }

  /**
   * Builds a condition carrying declared cacheability and nothing else.
   *
   * @param string[] $tags
   *   The cache tags it reports.
   * @param string[] $contexts
   *   The cache contexts it reports.
   * @param int $max_age
   *   The max-age it reports.
   *
   * @return \Drupal\Core\Condition\ConditionInterface
   *   A double. `ConditionInterface` already extends
   *   `CacheableDependencyInterface`, which is the only thing
   *   `mergeCacheabilityFromConditions()` asks of what it is given.
   */
  protected function conditionDouble(array $tags, array $contexts, int $max_age): ConditionInterface {
    $condition = $this->createMock(ConditionInterface::class);
    $condition->method('getCacheTags')->willReturn($tags);
    $condition->method('getCacheContexts')->willReturn($contexts);
    $condition->method('getCacheMaxAge')->willReturn($max_age);
    return $condition;
  }

}
