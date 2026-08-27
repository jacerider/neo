<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo\Hook\NeoTokensHooks;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

/**
 * The smart tokens, now that they are a class rather than a group include.
 *
 * `neo.tokens.inc` was 476 lines: two hooks and the nine-function resolution
 * chain behind them, over two `drupal_static()` caches and one database cache.
 * All of it is now `Drupal\neo\Hook\NeoTokensHooks` — the two hooks as public
 * methods, the nine helpers as private ones — and the file is deleted, which is
 * the only way core stops registering it as a `tokens` **group include** and
 * including it behind a Drupal 12 removal on every token replacement.
 *
 * What the class must not have changed is anything the chain decides, so the
 * behavioural assertions here are the same statements the three `SmartToken…`
 * characterisation classes already make — restated against the class, and
 * deliberately not repeated in full.
 * What is new, and what this class exists for, is the shape of the move: that
 * the hooks are registered from the class, that the file is gone, that the two
 * per-request caches are instance state a second instance does not inherit, and
 * that every container service the bodies reached is a constructor argument.
 *
 * The two quirks the characterisation suite pinned are pinned again rather than
 * repaired, because "the same values the deleted functions produced" includes
 * the values nobody wanted: a computed `NULL` goes into the instance cache and
 * the `isset()` reading it treats it as absent, so a `NULL` is recomputed; the
 * database cache behind it has no such blind spot and does short-circuit one.
 */
#[Group('neo')]
final class TokensHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`.
   *
   * `node` and `taxonomy` are the two entity types the description resolver
   * distinguishes between. `token` is installed for the undeclared dependency
   * the characterisation suite recorded: the title resolver calls
   * `token_render_array_value()`, which contrib `token` provides and
   * `neo.info.yml` never declared. `neo_test` supplies the four alter hooks the
   * chain consults.
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
    'taxonomy',
    'token',
    'neo',
    'neo_test',
  ];

  /**
   * A node with a label.
   */
  protected Node $node;

  /**
   * A second node, so a cache key can be missed on the entity id.
   */
  protected Node $otherNode;

  /**
   * A node with no title value at all, so its label() is NULL.
   */
  protected Node $labelless;

  /**
   * A taxonomy term carrying a description.
   */
  protected Term $term;

  /**
   * The uri of a 120x45 image, the logo hook's contribution.
   */
  protected string $logoUri = 'public://neo-hook-logo.png';

  /**
   * The uri of a 60x30 image, the image hook's contribution.
   */
  protected string $imageUri = 'public://neo-hook-image.png';

  /**
   * The instance under test, rebuilt whenever the request context changes.
   */
  protected ?NeoTokensHooks $hooks = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    // Updating a node deletes its access grants, which needs the table.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);
    // The front-page comparison turns the current route into a path through the
    // URL generator, so the fixture routes have to be in the route provider.
    $this->container->get('router.builder')->rebuild();

    $this->config('system.site')
      ->set('name', 'Fixture site name')
      ->set('slogan', 'Fixture site slogan')
      ->save();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'The node label']);
    $this->node->save();
    $this->otherNode = Node::create(['type' => 'page', 'title' => 'The other label']);
    $this->otherNode->save();
    // `node_field_data.title` is NOT NULL, so the row is written with a title
    // and the field is emptied on the object afterwards.
    $this->labelless = Node::create(['type' => 'page', 'title' => 'Cleared']);
    $this->labelless->save();
    $this->labelless->set('title', NULL);

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->term = Term::create([
      'vid' => 'tags',
      'name' => 'The term label',
      'description' => ['value' => 'The term description', 'format' => 'plain_text'],
    ]);
    $this->term->save();

    $this->writeImage($this->logoUri, 120, 45);
    $this->writeImage($this->imageUri, 60, 30);
  }

  /**
   * The token type and its eight tokens are declared from the class.
   *
   * Acceptance criterion: it declares the one token type and its eight tokens
   * from the class, and `neo.tokens.inc` no longer exists.
   *
   * Three separate claims, and the third is the point of the ticket. The hooks
   * answer the same arrays; they answer them from a class the hook system
   * resolved rather than from a global; and the file they used to live in is
   * gone, so core's collector no longer registers it against the `tokens` hook
   * group and no longer includes it behind a deprecation on every replacement.
   * An emptied file would keep that registration, which is why the group
   * include map is asserted rather than the file's contents.
   */
  public function testDeclaresTheTokenTypeAndItsEightTokensFromTheClass(): void {
    $this->assertContains(
      'neo: ' . NeoTokensHooks::class . '::tokenInfo',
      $this->hookImplementations('token_info'),
      'hook_token_info is implemented by the class.'
    );
    $this->assertContains(
      'neo: ' . NeoTokensHooks::class . '::tokens',
      $this->hookImplementations('tokens'),
      'hook_tokens is implemented by the class.'
    );
    $this->assertFalse(function_exists('neo_token_info'), 'neo_token_info() is gone.');
    $this->assertFalse(function_exists('neo_tokens'), 'neo_tokens() is gone.');

    $info = $this->container->get('module_handler')->invoke('neo', 'token_info');
    $this->assertSame(['neo'], array_keys($info['types']));
    $this->assertSame('Neo', (string) $info['types']['neo']['name']);
    $this->assertSame('Tokens provided by Neo.', (string) $info['types']['neo']['description']);
    $this->assertSame(['neo'], array_keys($info['tokens']));
    $this->assertSame([
      'title',
      'description',
      'logo',
      'logo:width',
      'logo:height',
      'image',
      'image:width',
      'image:height',
    ], array_keys($info['tokens']['neo']));
    foreach ($info['tokens']['neo'] as $name => $definition) {
      $this->assertSame(['name', 'description', 'type'], array_keys($definition), $name);
      $this->assertSame('neo', $definition['type'], $name);
      $this->assertNotSame('', (string) $definition['name'], $name);
    }

    // The file is deleted, not emptied.
    $this->assertFileDoesNotExist($this->packageRoot() . '/neo.tokens.inc');

    // And with it the group include registration, which is what core acts on.
    $groupIncludes = $this->container->get('keyvalue')->get('hook_data')->get('group_includes') ?? [];
    $registered = array_merge(...array_values($groupIncludes) ?: [[]]);
    foreach ($registered as $include) {
      $this->assertStringNotContainsString('neo.tokens.inc', $include);
    }
  }

  /**
   * The four resolvers answer exactly what the deleted functions answered.
   *
   * Acceptance criterion: it resolves title, description, logo and image tokens
   * to the same values the deleted functions produced, including the front-page
   * branches and the width and height parameters.
   *
   * Every branch that decides a value is driven once: the title's alter hook,
   * its route and its entity label; the description's term arm and its slogan
   * fallback; the logo's hook-supplied uri and both of its dimension
   * parameters; the image's own hook and dimensions; and the two front-page
   * short-circuits, one of which turns the image token into the logo. The
   * dimensions are the numbers written into the fixture files, so a passing
   * assertion is about the read rather than about a file someone else picked.
   */
  public function testResolvesTitleDescriptionLogoAndImageToTheSameValues(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse($this->container->get('path.matcher')->isFrontPage());

    // The title: the alter hook first.
    \Drupal::state()->set('neo_test.token_title_alter', 'The hook title');
    $this->assertSame(
      ['[neo:title]' => 'The hook title'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );

    // Then the route the request carries.
    $this->resetCaches();
    \Drupal::state()->delete('neo_test.token_title_alter');
    $this->assertSame(
      ['[neo:title]' => 'Neo test open'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );

    // Then, with no route object at all, the entity's own label.
    $this->resetCaches();
    $this->enterRequest(NULL);
    $this->assertSame(
      ['[neo:title]' => 'The node label'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );

    // The description: a taxonomy term's own value, and the slogan for an
    // entity type the match has no arm for.
    $this->resetCaches();
    $this->assertSame(
      ['[neo:description]' => 'The term description'],
      $this->dispatch(['description' => '[neo:description]'], ['term' => $this->term])
    );
    $this->resetCaches();
    $this->assertSame(
      ['[neo:description]' => 'Fixture site slogan'],
      $this->dispatch(['description' => '[neo:description]'], ['node' => $this->node])
    );

    // The logo: the uri its own hook supplies, as a url and as both dimensions.
    $this->resetCaches();
    \Drupal::state()->set('neo_test.token_logo_alter', $this->logoUri);
    $this->assertSame(
      ['[neo:logo]' => $this->absoluteUrl($this->logoUri)],
      $this->dispatch(['logo' => '[neo:logo]'], ['node' => $this->node])
    );
    $this->assertSame(
      ['[neo:logo:width]' => '120'],
      $this->dispatch(['logo:width' => '[neo:logo:width]'], ['node' => $this->node])
    );
    $this->assertSame(
      ['[neo:logo:height]' => '45'],
      $this->dispatch(['logo:height' => '[neo:logo:height]'], ['node' => $this->node])
    );

    // The image: a different hook, a different file, the same two parameters.
    $this->resetCaches();
    \Drupal::state()->set('neo_test.token_image_alter', $this->imageUri);
    $this->assertSame(
      ['[neo:image]' => $this->absoluteUrl($this->imageUri)],
      $this->dispatch(['image' => '[neo:image]'], ['node' => $this->node])
    );
    $this->assertSame(
      ['[neo:image:width]' => '60'],
      $this->dispatch(['image:width' => '[neo:image:width]'], ['node' => $this->node])
    );
    $this->assertSame(
      ['[neo:image:height]' => '30'],
      $this->dispatch(['image:height' => '[neo:image:height]'], ['node' => $this->node])
    );

    // The front page: the site name and the slogan, ahead of an alter hook that
    // is armed and never consulted, and the image token handing its parameters
    // to the logo — so the file the image answers with is the logo's.
    $this->config('system.site')->set('page.front', '/neo-test/open')->save();
    $this->resetCaches();
    $this->enterRequest('neo_test.open');
    $this->assertTrue($this->container->get('path.matcher')->isFrontPage());
    \Drupal::state()->set('neo_test.token_title_alter', 'Never reached');

    $this->assertSame(
      ['[neo:title]' => 'Fixture site name'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));
    $this->assertSame(
      ['[neo:description]' => 'Fixture site slogan'],
      $this->dispatch(['description' => '[neo:description]'], ['node' => $this->node])
    );
    $this->assertSame(
      ['[neo:image]' => $this->absoluteUrl($this->logoUri)],
      $this->dispatch(['image' => '[neo:image]'], ['node' => $this->node])
    );
    $this->assertSame(
      ['[neo:image:height]' => '45'],
      $this->dispatch(['image:height' => '[neo:image:height]'], ['node' => $this->node])
    );
  }

  /**
   * Both per-request caches are instance state, cold on a new instance.
   *
   * Acceptance criterion: it serves a repeated identical request from the
   * instance cache and starts cold on a separately constructed instance.
   *
   * Both of the caches that were `drupal_static()` are asserted, because they
   * are keyed differently and answer different questions: the dispatcher's is
   * keyed by entity type, entity id, token name and parameters, and the image
   * fetch's by entity and hook name alone. In each case the database entry is
   * taken out of the way and the alter hook is re-armed, so the only thing that
   * can produce the first answer a second time is the instance — and the only
   * thing that can produce the second is a different one.
   *
   * The last pair is what makes these caches testable at all: two instances
   * disagree, in the same process, with nothing reset between them.
   */
  public function testServesTheRepeatedRequestFromTheInstanceCacheAndStartsColdOnAnotherInstance(): void {
    $this->enterRequest(NULL);
    \Drupal::state()->set('neo_test.token_title_alter', 'First value');
    $this->assertSame(
      ['[neo:title]' => 'First value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );

    // Only the instance can answer now: the database entry is gone and the hook
    // is armed with something else. An untouched record is what says nothing
    // was recomputed.
    $key = 'neo_tokens:node:' . $this->node->id() . ':title';
    $this->container->get('cache.default')->delete($key);
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    \Drupal::state()->set('neo_test.token_title_alter', 'Second value');
    $this->assertSame(
      ['[neo:title]' => 'First value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // The key carries the entity type, the id, the name and the parameters, so
    // a different entity and a different parameter list both miss.
    $this->assertSame(
      ['[neo:title]' => 'Second value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->otherNode])
    );
    $this->assertSame(
      ['[neo:title:other]' => 'Second value'],
      $this->dispatch(['title:other' => '[neo:title:other]'], ['node' => $this->node])
    );

    // A separately constructed instance starts cold, and the first one goes on
    // answering from its own memo — two answers, one process, nothing reset.
    $this->container->get('cache.default')->delete($key);
    $fresh = $this->newTokensHooks();
    $this->assertSame(
      ['[neo:title]' => 'Second value'],
      $fresh->tokens('neo', ['title' => '[neo:title]'], ['node' => $this->node], [], new BubbleableMetadata())
    );
    $this->assertSame(
      ['[neo:title]' => 'First value'],
      $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node])
    );

    // The image fetch's memo is the second cache, keyed by entity and hook. A
    // different token name misses the dispatcher's cache and still finds the
    // uri, so the hook does not run twice on one instance.
    $this->resetCaches();
    \Drupal::state()->set('neo_test.token_logo_alter', $this->logoUri);
    $this->assertSame(
      ['[neo:logo]' => $this->absoluteUrl($this->logoUri)],
      $this->dispatch(['logo' => '[neo:logo]'], ['node' => $this->node])
    );
    \Drupal::state()->delete('neo_test.token_logo_alter');
    \Drupal::state()->delete('neo_test.token_logo_alter_seen');
    $this->assertSame(
      ['[neo:logo:width]' => '120'],
      $this->dispatch(['logo:width' => '[neo:logo:width]'], ['node' => $this->node])
    );
    $this->assertNull(\Drupal::state()->get('neo_test.token_logo_alter_seen'));

    // And a new instance asks the hook again, which now declines.
    $fresh = $this->newTokensHooks();
    $this->assertSame(
      [],
      $fresh->tokens('neo', ['logo:height' => '[neo:logo:height]'], ['node' => $this->node], [], new BubbleableMetadata())
    );
    $this->assertNotNull(\Drupal::state()->get('neo_test.token_logo_alter_seen'));
  }

  /**
   * A NULL is recomputed by one cache and served by the other, as before.
   *
   * Acceptance criterion: it recomputes a null result rather than serving it
   * from either cache, exactly as before.
   *
   * Half of that criterion is true and the other half is the bug the ticket
   * pinned rather than fixed. The instance cache is written with the `NULL`,
   * and the `isset()` guarding it treats `NULL` as absent, so it never
   * short-circuits one — with no entity, and therefore no database cache, the
   * value really is recomputed on every call and the raw token is left standing
   * every time.
   *
   * With an entity it is not. The database cache stores the `NULL` too, and
   * `get()` answers with an item object rather than `FALSE`, so the second call
   * short-circuits and assigns `NULL` into the replacement set — replacing the
   * token with nothing where the first call left it alone. Moving the caches
   * onto the object changed neither half, which is what "exactly as before"
   * means here.
   */
  public function testRecomputesTheNullResultRatherThanServingItFromEitherCache(): void {
    $this->enterRequest(NULL);
    \Drupal::state()->delete('neo_test.token_title_alter');

    // A candidate that resolves to NULL: no hook value, no route object, and an
    // entity whose label() is NULL because it has no title value.
    $this->assertNull($this->labelless->label());
    $this->assertSame(
      [],
      $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless])
    );

    // The instance cache holds the key with a NULL against it, and the isset()
    // that reads it cannot see it.
    $key = 'neo_tokens:node:' . $this->labelless->id() . ':title';
    $cache = $this->dispatchCache();
    $this->assertArrayHasKey($key, $cache);
    $this->assertNull($cache[$key]);
    $this->assertFalse(isset($cache[$key]));

    // With the database entry out of the way the instance alone answers, and it
    // recomputes: the fixture records a second invocation.
    $this->container->get('cache.default')->delete($key);
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $this->assertSame(
      [],
      $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless])
    );
    $this->assertNotNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // With no entity at all there is no database entry to begin with, so every
    // call recomputes and every call leaves the raw token in place.
    $this->resetCaches();
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], []));
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $this->assertSame([], $this->dispatch(['title' => '[neo:title]'], []));
    $this->assertNotNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // And exactly as before, the database cache does short-circuit a NULL: the
    // identical call that left the raw token alone now replaces it with
    // nothing, on an instance whose own cache is empty.
    $this->resetCaches();
    $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless]);
    $item = $this->container->get('cache.default')->get($key);
    $this->assertNotFalse($item);
    $this->assertNull($item->data);

    $this->hooks = NULL;
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    $this->assertSame(
      ['[neo:title]' => NULL],
      $this->dispatch(['title' => '[neo:title]'], ['alias' => $this->labelless])
    );
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));
  }

  /**
   * The database cache is written only with an entity, tagged with its tags.
   *
   * Acceptance criterion: it writes the database cache only when an entity is
   * present and tags it with that entity's invalidation tags.
   *
   * The database cache is the one thing this ticket did not move, so the claim
   * is that nothing about it changed: the same key the instance cache uses, the
   * permanent expiry, and the entity's own invalidation tags. The tagging is
   * proved twice — the tags on the item are the entity's, and saving the entity
   * clears the item — because a tag written but never honoured would satisfy
   * only the first.
   *
   * The no-entity half is proved against the instance cache in the same breath:
   * the value is memoised for the request and simply never persisted.
   */
  public function testWritesTheDatabaseCacheOnlyWithAnEntityAndTagsItWithItsTags(): void {
    $this->enterRequest(NULL);
    \Drupal::state()->set('neo_test.token_title_alter', 'Persisted');
    $this->dispatch(['title' => '[neo:title]'], ['node' => $this->node]);

    $key = 'neo_tokens:node:' . $this->node->id() . ':title';
    $item = $this->container->get('cache.default')->get($key);
    $this->assertNotFalse($item);
    $this->assertSame('Persisted', $item->data);
    $this->assertSame(CacheBackendInterface::CACHE_PERMANENT, $item->expire);
    $this->assertSame(['node:' . $this->node->id()], $this->node->getCacheTagsToInvalidate());
    $this->assertSame($this->node->getCacheTagsToInvalidate(), $item->tags);

    // Saving the entity invalidates its own tag, and with it the entry.
    $this->node->setTitle('Renamed')->save();
    $this->assertFalse($this->container->get('cache.default')->get($key));

    // With no entity there is no database write at all, while the instance
    // cache is written under the no-entity key exactly as it is for one.
    $this->resetCaches();
    \Drupal::state()->set('neo_test.token_title_alter', 'Not persisted');
    $this->dispatch(['title:a:b' => '[neo:title:a:b]'], []);
    $this->assertSame(
      'Not persisted',
      $this->dispatchCache()['neo_tokens:no-entity:title:a:b']
    );
    $this->assertFalse(
      $this->container->get('cache.default')->get('neo_tokens:no-entity:title:a:b')
    );
  }

  /**
   * Every container service the bodies reached is a constructor argument.
   *
   * Acceptance criterion: every container service these bodies reached is a
   * constructor dependency, except the contrib `token` global and the
   * `neo_image` check, which stay as they are.
   *
   * The seven the chain reached are asserted twice — once as declared parameter
   * types, so the signature is the contract, and once as the objects the
   * container actually handed over, so a parameter typed against an interface
   * nothing binds would not pass. The absence assertion is the other half: a
   * body that kept a `\Drupal::` call would satisfy both lists and still be the
   * thing this ticket set out to remove, and a surviving `drupal_static()`
   * would mean a second instance was not the cold resolver the criterion above
   * claims it is.
   *
   * The two calls that stay are the two that reach a module `neo.info.yml` does
   * not declare. Injecting either would turn a lazy per-request failure on a
   * site without it into a container-build failure, which is a worse failure in
   * a worse place.
   */
  public function testTakesEveryContainerServiceItReachedThroughTheConstructor(): void {
    $hooks = $this->tokensHooks();

    $constructor = (new \ReflectionClass($hooks))->getConstructor();
    $this->assertNotNull($constructor, 'The class declares a constructor.');
    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $types[$parameter->getName()] = ltrim((string) $parameter->getType(), '?');
    }
    $this->assertSame([
      'cache' => CacheBackendInterface::class,
      'moduleHandler' => ModuleHandlerInterface::class,
      'pathMatcher' => PathMatcherInterface::class,
      'configFactory' => ConfigFactoryInterface::class,
      'requestStack' => RequestStack::class,
      'titleResolver' => TitleResolverInterface::class,
      'fileUrlGenerator' => FileUrlGeneratorInterface::class,
    ], $types);

    // Each one is the container's own service, not something constructed here.
    foreach ([
      'cache' => 'cache.default',
      'moduleHandler' => 'module_handler',
      'pathMatcher' => 'path.matcher',
      'configFactory' => 'config.factory',
      'requestStack' => 'request_stack',
      'titleResolver' => 'title_resolver',
      'fileUrlGenerator' => 'file_url_generator',
    ] as $property => $service) {
      $this->assertSame(
        $this->container->get($service),
        (new \ReflectionProperty($hooks, $property))->getValue($hooks),
        $property . ' holds ' . $service . '.'
      );
    }

    // The two that stay, and nothing else reaching the container or a static.
    // The comments are stripped first, because this class's own docblock talks
    // about both of the things being asserted absent.
    $code = $this->classCode();
    $this->assertStringContainsString('token_render_array_value(', $code);
    $this->assertStringContainsString("moduleExists('neo_image')", $code);
    $this->assertStringNotContainsString('\\Drupal::', $code);
    $this->assertStringNotContainsString('drupal_static(', $code);
  }

  /**
   * The hook scan skip is declared, and nothing procedural is registered.
   *
   * Acceptance criterion: `neo.skip_procedural_hook_scan` is declared and `neo`
   * registers no procedural hook implementation, while the table props shim and
   * both debug helpers are still defined.
   *
   * The parameter is read out of `neo.services.yml` rather than out of the
   * container, because core removes every `*.skip_procedural_hook_scan`
   * parameter from the parameter bag once its collector has read them — a
   * container assertion would be asserting the absence of what it was looking
   * for. What the parameter buys is asserted against the collector's own
   * output: every implementation `neo` registers, for every hook, is a
   * `Class::method` rather than a function name.
   *
   * The last block is what the parameter must not have cost. Skipping the scan
   * does not stop `neo.module` being loaded, and the three globals that are
   * deliberately global are still there: the deprecated **table props shim**
   * another repository calls, and the two debug helpers the package's own
   * pre-commit hook pins to that file by path.
   */
  public function testDeclaresTheHookScanSkipAndRegistersNoProceduralImplementation(): void {
    $services = Yaml::parseFile($this->packageRoot() . '/neo.services.yml');
    $this->assertTrue(
      $services['parameters']['neo.skip_procedural_hook_scan'] ?? NULL,
      'neo.services.yml declares the hook scan skip.'
    );

    $hookList = $this->container->get('keyvalue')->get('hook_data')->get('hook_list') ?? [];
    $this->assertNotEmpty($hookList, 'The collector wrote a hook list.');
    $implementations = [];
    foreach ($hookList as $hook => $hookImplementations) {
      foreach ($hookImplementations as $identifier => $module) {
        if ($module === 'neo') {
          $implementations[] = $hook . ': ' . $identifier;
        }
      }
    }
    $this->assertNotEmpty($implementations, 'neo implements hooks at all.');
    $this->assertSame([], array_values(array_filter(
      $implementations,
      static fn (string $entry): bool => !str_contains($entry, '::')
    )), 'No implementation neo registers is a function.');

    // The three globals that are deliberately global survive the skip. What
    // the shim answers is `TablePropsShimTest`'s subject and is not restated
    // here; that it is still a global at all is this criterion's half.
    $this->assertTrue(function_exists('neo_table_props'), 'The table props shim is defined.');
    $module = file_get_contents($this->packageRoot() . '/neo.module');
    $this->assertStringContainsString('function ksm(', $module);
    $this->assertStringContainsString('function kint(', $module);
    $this->assertSame(class_exists('Kint'), function_exists('ksm'), 'ksm() tracks Kint.');
    $this->assertSame(class_exists('Kint'), function_exists('kint'), 'kint() tracks Kint.');
  }

  /**
   * Dispatches a `neo` token set through the class's own `hook_tokens()`.
   *
   * @param array $tokens
   *   The requested tokens, keyed by name-with-parameters.
   * @param array $data
   *   The token data.
   *
   * @return array
   *   The replacement set, keyed by the raw token.
   */
  protected function dispatch(array $tokens, array $data = []): array {
    return $this->tokensHooks()->tokens('neo', $tokens, $data, [], new BubbleableMetadata());
  }

  /**
   * The instance under test, constructed from the container's own services.
   *
   * The instance is built rather than fetched because core registers a hook
   * class as a private autowired service, and because constructing one is the
   * whole point of the two caches being instance state: a second instance is a
   * cold cache with no static to reset.
   *
   * @return \Drupal\neo\Hook\NeoTokensHooks
   *   The instance, memoised until something invalidates it.
   */
  protected function tokensHooks(): NeoTokensHooks {
    if ($this->hooks === NULL) {
      $this->assertTrue(
        class_exists(NeoTokensHooks::class),
        NeoTokensHooks::class . ' exists.'
      );
      $this->hooks = $this->newTokensHooks();
    }
    return $this->hooks;
  }

  /**
   * Builds a brand-new instance, whose two caches are empty by construction.
   *
   * @return \Drupal\neo\Hook\NeoTokensHooks
   *   A fresh instance.
   */
  protected function newTokensHooks(): NeoTokensHooks {
    return new NeoTokensHooks(
      $this->container->get('cache.default'),
      $this->container->get('module_handler'),
      $this->container->get('path.matcher'),
      $this->container->get('config.factory'),
      $this->container->get('request_stack'),
      $this->container->get('title_resolver'),
      $this->container->get('file_url_generator'),
    );
  }

  /**
   * Drops the instance and empties the database cache.
   *
   * Dropping the instance is what empties the two per-request caches: they are
   * private properties rather than statics, so there is nothing to reset.
   */
  protected function resetCaches(): void {
    $this->hooks = NULL;
    $this->container->get('cache.default')->deleteAll();
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
  }

  /**
   * Enters a fresh request carrying the named route, or no route at all.
   *
   * A new request object each time is what makes the front-page answer
   * observable more than once in a process: `CurrentRouteMatch` memoises its
   * match per request object and `PathMatcher` memoises its own answer on
   * itself, so both are replaced rather than mutated. The instance is dropped
   * with them, because it holds the path matcher it was constructed with.
   *
   * @param string|null $routeName
   *   The route to put on the request, or NULL for a request with no route
   *   object — the shape a replacement outside a routed context really has.
   */
  protected function enterRequest(?string $routeName): void {
    $request = Request::create('/');
    if ($routeName !== NULL) {
      $request->attributes->set(
        RouteObjectInterface::ROUTE_OBJECT,
        $this->container->get('router.route_provider')->getRouteByName($routeName)
      );
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $routeName);
    }
    // The harness's own teardown reads a session off whatever request is
    // current, so the mock session `DrupalKernel::preHandle()` started has to
    // travel with the replacement — which is also what a real request has.
    $current = $this->container->get('request_stack')->getCurrentRequest();
    if ($current !== NULL && $current->hasSession()) {
      $request->setSession($current->getSession());
    }
    $this->container->get('request_stack')->push($request);
    $this->container->set('path.matcher', new PathMatcher(
      $this->container->get('config.factory'),
      $this->container->get('current_route_match')
    ));
    $this->hooks = NULL;
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
  }

  /**
   * The dispatcher's own per-request cache, read off the instance.
   *
   * It is a private property rather than a `drupal_static()`, so a test reads
   * it by reflection and empties it by constructing another instance instead of
   * resetting every static in the process.
   *
   * @return array
   *   The cache, keyed as the dispatcher keys it.
   */
  protected function dispatchCache(): array {
    $hooks = $this->tokensHooks();
    return (array) (new \ReflectionProperty($hooks, 'dispatchCache'))->getValue($hooks);
  }

  /**
   * The hook class's own source file.
   *
   * @return string
   *   The absolute path.
   */
  protected function classFile(): string {
    return $this->packageRoot() . '/src/Hook/NeoTokensHooks.php';
  }

  /**
   * The hook class's source with every comment removed.
   *
   * @return string
   *   The class's code, docblocks and comments stripped.
   */
  protected function classCode(): string {
    $code = '';
    foreach (token_get_all((string) file_get_contents($this->classFile())) as $token) {
      if (is_array($token)) {
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], TRUE)) {
          continue;
        }
        $code .= $token[1];
        continue;
      }
      $code .= $token;
    }
    return $code;
  }

  /**
   * Builds the absolute file url for a uri, the way the url build does.
   *
   * @param string $uri
   *   The file uri.
   *
   * @return string
   *   The absolute url.
   */
  protected function absoluteUrl(string $uri): string {
    return $this->container->get('file_url_generator')->generateAbsoluteString($uri);
  }

  /**
   * The hook implementations the hook system resolved, as `module: identifier`.
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
   * Writes a PNG of exactly the given size to a uri.
   *
   * @param string $uri
   *   The uri to write to.
   * @param int $width
   *   The image width.
   * @param int $height
   *   The image height.
   */
  protected function writeImage(string $uri, int $width, int $height): void {
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    imagedestroy($image);
    file_put_contents($uri, $png);
    clearstatcache();
  }

}
