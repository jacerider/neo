<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the smart token dispatcher and its two caches.
 *
 * `neo.tokens.inc` publishes one token type, `neo`, and eight tokens under it,
 * and `neo_tokens()` is the entry point every meta tag on a Neo site passes
 * through. `neo_token_info()` is built from the same `neo_tokens_definitions()`
 * list the dispatcher consults, so a declared token is a token that resolves.
 *
 * The dispatch, in order:
 *
 * - Any `$type` other than `neo` returns an empty replacement set before a
 *   single entity is collected or a single value computed.
 * - Every `ContentEntityInterface` in `$data` becomes a candidate, in the order
 *   `$data` carries them. With no content entity at all a single `NULL`
 *   candidate stands in, so a token still resolves on a route with no entity.
 * - Each requested token name is split on colons into a name and a parameter
 *   list; a name with no definition is `continue`d past, leaving the raw token
 *   in place for whoever asked.
 * - The candidates are tried in order and the **first truthy value wins**; a
 *   candidate producing nothing falls through to the next.
 *
 * The two caches are the part nobody can see:
 *
 * - A **static** cache — `drupal_static('neo_tokens')` — keyed
 *   `neo_tokens:{entity_type}:{id}:{name}[:{params}]`, or
 *   `neo_tokens:no-entity:{name}[:{params}]`. Checked first, written for every
 *   computed value.
 * - A **database** cache under the same key, checked and written **only when
 *   there is an entity**, and tagged with that entity's own invalidation tags,
 *   so saving the entity clears it.
 *
 * **The contrib `token` module is installed deliberately.**
 * `neo_tokens_title()` calls `token_render_array_value()`, a global function
 * from contrib `token`, and `neo.info.yml` declares no such dependency and
 * `composer.json` requires no such package. A site without `token` fatals on
 * that line on any non-front-page route that has a route object. Installing it
 * here compensates for an undeclared dependency; it is a finding for the
 * backlog, not something this test repairs.
 *
 * **Characterised, not repaired.** Four answers pinned below are quirks rather
 * than intentions:
 *
 * 1. **A `NULL` result is recomputed by the static cache and served by the
 *    database cache.** The static cache is written with the `NULL`, but the
 *    existence check that reads it is `isset()`, which treats `NULL` as absent
 *    — so the static cache never short-circuits a `NULL`. The database cache
 *    behind it has no such blind spot: `CacheBackendInterface::get()` returns a
 *    perfectly truthy item object whose `data` is `NULL`, so the second call
 *    with an entity present short-circuits and writes `NULL` into the
 *    replacement set — turning `[neo:title]` into the empty string instead of
 *    leaving the raw token in place, which is what the uncached call does.
 *    Pinned in testRecomputesTheNullResultRatherThanServingItFromEitherCache.
 * 2. **A cached falsy-but-not-null value stops the candidate walk.** The
 *    uncached path falls through an empty string to the next entity; the cached
 *    path does not, because `isset('')` is TRUE. Two identical calls can
 *    therefore return different answers. Pinned in
 *    testResolvesAgainstEachEntityInTurnAndTakesTheFirstTruthyValue.
 * 3. **`$bubbleable_metadata` is never touched.** The dispatcher tags its own
 *    database cache with the entity's invalidation tags and then bubbles none
 *    of them to the caller, so a render array holding a resolved `[neo:*]`
 *    token carries no dependency on the entity the value came from. Pinned in
 *    testWritesTheDatabaseCacheOnlyWithAnEntityAndTagsItWithTheEntitysTags.
 * 4. **Four of the eight declared token keys are never looked up.**
 *    `logo:width`, `logo:height`, `image:width` and `image:height` are declared
 *    as keys carrying a colon, but the dispatcher splits on colons before
 *    testing `isset($definitions[$name])`, so only the four colon-free keys are
 *    ever consulted. The four compound keys exist to populate the token browser
 *    and are otherwise inert. Pinned in
 *    testDeclaresOneTokenTypeCarryingTheEightDefinedTokens.
 */
#[Group('neo')]
final class SmartTokenDispatcherTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `KernelTestBase` installs exactly what
   * is named and does not resolve the info file's dependency closure, so
   * `neo_build`, `neo_color` and `neo_icon` stay out.
   *
   * `node` supplies the content entities the candidate walk needs — one with a
   * label, one whose label is the empty string, and one with no title value at
   * all, whose `label()` is `NULL`. `token` is here for the undeclared
   * dependency named in the class docblock.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'field',
    'text',
    'filter',
    'node',
    'token',
    'neo',
    'neo_test',
  ];

  /**
   * A node whose label is truthy.
   */
  protected Node $node;

  /**
   * A second node whose label is truthy, for the first-wins walk.
   */
  protected Node $otherNode;

  /**
   * A node with no title value at all, so label() is NULL rather than ''.
   *
   * `ContentEntityBase::label()` reads `$this->get('title')->value` from an
   * empty field item list, which is `NULL`. A node whose title is `''` is a
   * different animal — its label is the empty string — and the difference
   * between the two is exactly what separates quirk 1 from quirk 2.
   */
  protected Node $labelless;

  /**
   * {@inheritdoc}
   *
   * The route object is removed from the harness's request on purpose.
   * `KernelTestBase` builds a request for `/` and hangs core's `/<none>`
   * placeholder route on it — a route with no defaults, which no real
   * replacement ever runs against. Left in place, `neo_tokens_title()` takes
   * its route branch, `title_resolver` answers `NULL`, and
   * `token_render_array_value(NULL)` turns that into the empty string for every
   * candidate alike, so no candidate is distinguishable from any other and the
   * walk this ticket is about cannot be observed at all. Removing it puts the
   * resolver on its entity-label branch, which is the shape a replacement
   * outside a routed context — Drush, cron, a queue worker — really has. The
   * branch that needs `token` is restored and exercised once, at the end of
   * testResolvesAgainstEachEntityInTurnAndTakesTheFirstTruthyValue.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    // Updating a node deletes its access grants, which needs the table.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node']);

    // `neo_tokens()` and its resolvers live in an include the module loads on
    // demand, so nothing has pulled it in by the time a test calls it.
    \Drupal::moduleHandler()->loadInclude('neo', 'tokens.inc');

    \Drupal::request()->attributes->remove(RouteObjectInterface::ROUTE_OBJECT);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->node = Node::create([
      'type' => 'page',
      'title' => 'First node',
    ]);
    $this->node->save();
    $this->otherNode = Node::create([
      'type' => 'page',
      'title' => 'Second node',
    ]);
    $this->otherNode->save();

    // No title value at all, so `label()` is NULL rather than the empty string.
    // `node_field_data.title` is NOT NULL, so the row is written with a title
    // and the field is emptied on the object afterwards; the dispatcher reads
    // `label()` off the object it is handed, never off the row.
    $this->labelless = Node::create(['type' => 'page', 'title' => 'Cleared']);
    $this->labelless->save();
    $this->labelless->set('title', NULL);

    $this->resetCaches();
  }

  /**
   * Declares one token type carrying the eight defined tokens.
   *
   * Covers: "it declares one token type carrying the eight defined tokens".
   *
   * The last block is the quirk: the dispatcher splits a requested name on
   * colons before it looks the definition up, so the four compound keys are
   * never the key it tests. They populate the token browser and nothing else.
   */
  public function testDeclaresOneTokenTypeCarryingTheEightDefinedTokens(): void {
    $info = neo_token_info();

    $this->assertSame(['neo'], array_keys($info['types']));
    $this->assertSame('Neo', (string) $info['types']['neo']['name']);
    $this->assertSame(['neo'], array_keys($info['tokens']));

    $definitions = neo_tokens_definitions();
    $this->assertSame([
      'title',
      'description',
      'logo',
      'logo:width',
      'logo:height',
      'image',
      'image:width',
      'image:height',
    ], array_keys($definitions));
    $this->assertSame(array_keys($definitions), array_keys($info['tokens']['neo']));

    // The info hook is built from the definition list rather than repeating it,
    // so every declared token carries the same three keys under the same type.
    foreach ($definitions as $name => $definition) {
      $this->assertSame(['name', 'description', 'type'], array_keys($definition), $name);
      $this->assertSame('neo', $definition['type'], $name);
      $this->assertNotSame('', (string) $definition['name'], $name);
      $this->assertSame(
        (string) $definition['name'],
        (string) $info['tokens']['neo'][$name]['name'],
        $name
      );
    }

    // Only the four colon-free keys are ever consulted by the dispatcher.
    $consulted = array_filter(
      array_keys($definitions),
      static fn (string $name): bool => !str_contains($name, ':')
    );
    $this->assertSame(['title', 'description', 'logo', 'image'], array_values($consulted));
  }

  /**
   * Returns nothing for a foreign type, and skips an undefined name.
   *
   * Covers: "it returns no replacements for a token type that is not its own
   * and skips a token name it has no definition for".
   *
   * `neo_test`'s title alter hook records every invocation whether or not it is
   * switched on, so an untouched `neo_test.token_title_alter_seen` is proof
   * that no value was computed rather than merely that none was returned. The
   * untouched static cache is the stronger half of the same claim: a name the
   * dispatcher walked past leaves no key behind, where a name it resolved to
   * nothing would leave one holding `NULL`.
   */
  public function testReturnsNothingForForeignTypesAndSkipsAnUndefinedName(): void {
    $this->assertSame([], neo_tokens(
      'node',
      ['title' => '[node:title]'],
      ['node' => $this->node],
      [],
      new BubbleableMetadata()
    ));
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));
    $static = &drupal_static('neo_tokens');
    $this->assertSame([], (array) $static);

    // A name with no definition is skipped entirely: the raw token is left in
    // place for whoever asked, the parameters are never examined, and no cache
    // key is written for it in either cache.
    $this->assertSame([], $this->dispatch([
      'nope' => '[neo:nope]',
      'nope:width' => '[neo:nope:width]',
    ], ['node' => $this->node]));
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));
    $this->assertSame([], (array) $static);

    // `[neo:logo:width]` resolves because `logo` has a definition, not because
    // the compound key `logo:width` does — it computes, and leaves a key.
    $this->assertSame([], $this->dispatch(
      ['logo:width' => '[neo:logo:width]'],
      ['node' => $this->node]
    ));
    $this->assertSame(
      ['neo_tokens:node:' . $this->node->id() . ':logo:width'],
      array_keys((array) $static)
    );

    // The compound definition key is not what makes `[neo:logo:width]`
    // resolvable — `logo` is. `title` proves the same dispatch does answer.
    \Drupal::state()->set('neo_test.token_title_alter', 'Answered');
    $this->assertSame(
      ['[neo:title]' => 'Answered'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );
  }

  /**
   * Splits a name into a name and parameters, and passes them on.
   *
   * Covers: "it splits a token name into a name and its parameters and passes
   * the parameters to the resolver".
   *
   * The parameters reach the resolver, and the resolver hands them to the alter
   * hook unchanged, which is where the fixture records them. They are also part
   * of the cache key, so two different parameter lists are two different
   * cached values rather than one.
   */
  public function testSplitsTheNameIntoTheNameAndItsParametersAndPassesThemOn(): void {
    \Drupal::state()->set('neo_test.token_title_alter', 'With params');

    $this->assertSame(
      ['[neo:title:crop:1200:630]' => 'With params'],
      $this->dispatch(['title:crop:1200:630' => '[neo:title:crop:1200:630]'], ['node' => $this->node])
    );
    $seen = \Drupal::state()->get('neo_test.token_title_alter_seen');
    $this->assertSame(['crop', '1200', '630'], $seen['params']);
    $this->assertSame('node:' . $this->node->id(), $seen['entity']);

    // A bare name carries an empty parameter list.
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $this->assertSame(
      ['[neo:title]' => 'With params'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );
    $this->assertSame([], \Drupal::state()->get('neo_test.token_title_alter_seen')['params']);

    // The parameters are appended to the cache key, so the two calls above
    // cached separately rather than colliding on `title`.
    $static = &drupal_static('neo_tokens');
    $this->assertSame([
      'neo_tokens:node:1:title:crop:1200:630',
      'neo_tokens:node:1:title',
    ], array_keys($static));
  }

  /**
   * Walks the candidates in order and takes the first truthy value.
   *
   * Covers: "it resolves against each content entity in the data in turn, takes
   * the first truthy value, and resolves with no entity present at all".
   *
   * With the alter hook off, `neo_tokens_title()` falls through to the entity's
   * own label, which is what makes each candidate distinguishable: two of the
   * fixture nodes are labelled and one has no title value at all.
   *
   * The last block pins quirk 2 from the class docblock. An empty string falls
   * through on the uncached path and short-circuits on the cached one, because
   * the fall-through test is `if ($value)` and the cache test is `isset()`.
   */
  public function testResolvesAgainstEachEntityInTurnAndTakesTheFirstTruthyValue(): void {
    // Two truthy candidates: the first wins and the second is never consulted.
    $this->assertSame(
      ['[neo:title]' => 'First node'],
      $this->dispatch(['title' => '[neo:title]'], [
        'a' => $this->node,
        'b' => $this->otherNode,
      ])
    );
    $this->assertSame(
      'node:' . $this->node->id(),
      \Drupal::state()->get('neo_test.token_title_alter_seen')['entity']
    );

    // A candidate producing nothing falls through to the next one.
    $this->resetCaches();
    $this->assertSame(
      ['[neo:title]' => 'Second node'],
      $this->dispatch(['title' => '[neo:title]'], [
        'a' => $this->labelless,
        'b' => $this->otherNode,
      ])
    );
    $this->assertSame(
      'node:' . $this->otherNode->id(),
      \Drupal::state()->get('neo_test.token_title_alter_seen')['entity']
    );

    // Non-entity values in the data are not candidates at all.
    $this->resetCaches();
    $this->assertSame(
      ['[neo:title]' => 'First node'],
      $this->dispatch(['title' => '[neo:title]'], [
        'string' => 'not an entity',
        'array' => ['also' => 'not an entity'],
        'node' => $this->node,
      ])
    );

    // With no content entity at all, a single NULL candidate stands in, so the
    // token still resolves — here from the alter hook, which is all a route
    // with no entity has.
    $this->resetCaches();
    \Drupal::state()->set('neo_test.token_title_alter', 'No entity here');
    $this->assertSame(
      ['[neo:title]' => 'No entity here'],
      $this->dispatch(['title' => '[neo:title]'], [])
    );
    $seen = \Drupal::state()->get('neo_test.token_title_alter_seen');
    $this->assertNull($seen['entity']);

    // Quirk 2: an empty string falls through on the uncached path:
    $this->resetCaches();
    \Drupal::state()->delete('neo_test.token_title_alter');
    // The empty string has to be put on the object after the save for the same
    // reason the NULL does: an empty string field item is stored as NULL, and
    // `node_field_data.title` is NOT NULL.
    $empty = Node::create(['type' => 'page', 'title' => 'Blanked']);
    $empty->save();
    $empty->set('title', '');
    $this->assertSame('', $empty->label());
    $this->assertSame(
      ['[neo:title]' => 'Second node'],
      $this->dispatch(['title' => '[neo:title]'], [
        'a' => $empty,
        'b' => $this->otherNode,
      ])
    );
    // ... and short-circuits on the cached one, so the identical second call
    // answers with the empty string the first call walked past.
    $this->assertSame(
      ['[neo:title]' => ''],
      $this->dispatch(['title' => '[neo:title]'], [
        'a' => $empty,
        'b' => $this->otherNode,
      ])
    );

    // Why the resolver is on its entity branch at all, and why contrib `token`
    // is installed. Put the harness's placeholder route back and every
    // candidate answers the same empty string, because `title_resolver` has no
    // `_title` to give and `token_render_array_value()` — the function
    // `neo.info.yml` never declared a dependency for — casts that NULL to ''.
    // On a site without `token` this line is a fatal error, not an empty
    // string.
    $this->resetCaches();
    $this->assertTrue(function_exists('token_render_array_value'));
    \Drupal::request()->attributes->set(
      RouteObjectInterface::ROUTE_OBJECT,
      \Drupal::service('router.route_provider')->getRouteByName('<none>')
    );
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], [
      'a' => $this->node,
      'b' => $this->otherNode,
    ]));
    $static = &drupal_static('neo_tokens');
    $this->assertSame('', $static['neo_tokens:node:' . $this->node->id() . ':title']);
  }

  /**
   * Serves an identical second request from the static cache.
   *
   * Covers: "it serves a second identical request from the static cache without
   * recomputing".
   *
   * The database cache entry is deleted between the two calls so that only the
   * static cache can answer, and the fixture's record of its own invocation is
   * cleared with it — an untouched record after the second call is what says
   * nothing was recomputed. Changing the value the alter hook would contribute
   * is the second half of the same claim: a recomputed answer would be the new
   * one.
   */
  public function testServesTheSecondIdenticalRequestFromTheStaticCache(): void {
    \Drupal::state()->set('neo_test.token_title_alter', 'First value');
    $this->assertSame(
      ['[neo:title]' => 'First value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );

    $key = 'neo_tokens:node:' . $this->node->id() . ':title';
    $static = &drupal_static('neo_tokens');
    $this->assertSame('First value', $static[$key]);

    \Drupal::cache()->delete($key);
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    \Drupal::state()->set('neo_test.token_title_alter', 'Second value');

    $this->assertSame(
      ['[neo:title]' => 'First value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // The key carries the entity type, the id, the name and the parameters, so
    // a different entity, a different name or different parameters all miss.
    $this->assertSame(
      ['[neo:title]' => 'Second value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->otherNode])
    );
    $this->assertSame(
      ['[neo:title:other]' => 'Second value'],
      $this->dispatch(['title:other' => '[neo:title:other]'], ['node' => $this->node])
    );
  }

  /**
   * Writes the database cache only with an entity, tagged with its tags.
   *
   * Covers: "it writes the database cache only when an entity is present and
   * tags it with that entity's invalidation tags".
   *
   * The tagging claim is proved twice: the tags on the item are the entity's
   * own, and saving the entity clears the item. The no-entity claim is proved
   * against the static cache in the same breath — the value is memoised for the
   * request and simply never persisted.
   *
   * The last block pins quirk 3: nothing the dispatcher tags its cache with
   * reaches the `BubbleableMetadata` it was handed.
   */
  public function testWritesTheDatabaseCacheOnlyWithAnEntityAndTagsItWithTheEntitysTags(): void {
    \Drupal::state()->set('neo_test.token_title_alter', 'Persisted');
    $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node]);

    $key = 'neo_tokens:node:' . $this->node->id() . ':title';
    $item = \Drupal::cache()->get($key);
    $this->assertNotFalse($item);
    $this->assertSame('Persisted', $item->data);
    $this->assertSame(['node:' . $this->node->id()], $this->node->getCacheTagsToInvalidate());
    $this->assertSame($this->node->getCacheTagsToInvalidate(), $item->tags);

    // Saving the entity invalidates its own tag, and with it the entry.
    $this->node->setTitle('Renamed')->save();
    $this->assertFalse(\Drupal::cache()->get($key));

    // With no entity there is no database write at all, while the static cache
    // is written exactly as it is for an entity.
    $this->resetCaches();
    \Drupal::state()->set('neo_test.token_title_alter', 'Not persisted');
    $this->dispatch(['title:a:b' => '[neo:title:a:b]'], []);
    $static = &drupal_static('neo_tokens');
    $this->assertSame('Not persisted', $static['neo_tokens:no-entity:title:a:b']);
    $this->assertFalse(\Drupal::cache()->get('neo_tokens:no-entity:title:a:b'));

    // Quirk 3: the entity's tags go into the cache item and nowhere else. A
    // render array holding the resolved value depends on nothing.
    $this->resetCaches();
    $bubbleable = new BubbleableMetadata();
    neo_tokens(
      'neo',
      ['title' => '[neo:title]'],
      ['node' => $this->node],
      [],
      $bubbleable
    );
    $this->assertSame([], $bubbleable->getCacheTags());
    $this->assertSame([], $bubbleable->getCacheContexts());
  }

  /**
   * Recomputes a NULL, except when the database cache answers first.
   *
   * Covers: "it recomputes a null result rather than serving it from either
   * cache".
   *
   * Half of that is true. The static cache is written with the `NULL` and the
   * `isset()` guarding it treats `NULL` as absent, so the static cache never
   * short-circuits one — with no entity, and therefore no database cache, the
   * value really is recomputed on every call and the raw token is left in
   * place every time.
   *
   * With an entity it is not, and that is quirk 1 from the class docblock. The
   * database cache stores the `NULL` too, and `get()` answers with an item
   * object rather than `FALSE`, so the second call short-circuits and assigns
   * `NULL` into the replacement set. The token is then replaced with nothing,
   * where the first call left it standing. Pinned, named, and reported for the
   * backlog rather than repaired.
   */
  public function testRecomputesTheNullResultRatherThanServingItFromEitherCache(): void {
    $key = 'neo_tokens:node:' . $this->labelless->id() . ':title';

    // A candidate that resolves to NULL: no alter hook value, no route object,
    // and an entity whose label() is NULL because it has no title value.
    $this->assertNull($this->labelless->label());
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless]));
    $seen = \Drupal::state()->get('neo_test.token_title_alter_seen');
    $this->assertSame('node:' . $this->labelless->id(), $seen['entity']);

    // The static cache holds the key with a NULL against it, and the isset()
    // that reads it cannot see it.
    $static = &drupal_static('neo_tokens');
    $this->assertArrayHasKey($key, $static);
    $this->assertNull($static[$key]);
    $this->assertFalse(isset($static[$key]));

    // With the database entry out of the way the static cache alone answers,
    // and it recomputes: the fixture records a second invocation.
    \Drupal::cache()->delete($key);
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless]));
    $this->assertNotNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // With no entity at all there is no database entry to begin with, so every
    // call recomputes and every call leaves the raw token in place.
    $this->resetCaches();
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], []));
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], []));
    $this->assertNotNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // Quirk 1: the database cache does short-circuit a NULL, and the value it
    // hands back is written into the replacement set — so the identical call
    // that left the raw token alone now replaces it with nothing.
    $this->resetCaches();
    $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless]);
    $item = \Drupal::cache()->get($key);
    $this->assertNotFalse($item);
    $this->assertNull($item->data);

    drupal_static_reset('neo_tokens');
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $replacements = $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless]);
    $this->assertSame(['[neo:title]' => NULL], $replacements);
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));
  }

  /**
   * Dispatches a `neo` token set through `neo_tokens()`.
   *
   * @param array $tokens
   *   The requested tokens, keyed by name-with-parameters.
   * @param array $data
   *   The token data.
   *
   * @return array
   *   The replacement set, keyed by the raw token.
   */
  private function dispatch(array $tokens, array $data = []): array {
    return neo_tokens('neo', $tokens, $data, [], new BubbleableMetadata());
  }

  /**
   * Empties both caches and the fixture's record of its own invocations.
   */
  private function resetCaches(): void {
    drupal_static_reset('neo_tokens');
    drupal_static_reset('neo_tokens_image_fetch');
    \Drupal::cache()->deleteAll();
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
  }

}
