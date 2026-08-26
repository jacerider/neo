<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterises the image half of the smart tokens, entry point to file.
 *
 * Six functions in `neo.tokens.inc` stand between `[neo:logo]` / `[neo:image]`
 * and a file on disk, and none of them has ever been asserted. They divide into
 * two entry points, a fetcher, a crawl, a dimension read and a url build.
 *
 * **The two entry points.** `neo_tokens_logo()` never crawls the entity: it
 * asks `neo_tokens_image_fetch()` with `$crawlEntity = FALSE`, so on a site
 * where nothing implements `hook_neo_token_logo_alter()` the logo token is
 * `NULL` on every page. `neo_tokens_image()` short-circuits on the front
 * page by handing its parameters straight to the logo, and everywhere else
 * asks the same fetcher with crawling on and the `neo_token_image` hook. Both
 * accept a leading `width` or `height` parameter, strip it from the list
 * before passing the rest along, and use it to choose between the resolved url
 * and a dimension read off the file.
 *
 * **The fetcher.** `neo_tokens_image_fetch()` memoises in
 * `drupal_static(__FUNCTION__)` under `{entity_type}:{id}:{hook}`, or
 * `no-entity:{hook}`. It crawls only when told to, then invokes the named alter
 * hook, which can supply a uri where the crawl found none or replace one it
 * found. What is memoised is the **uri**, before the url is built.
 *
 * **The crawl.** `neo_tokens_entity_to_media_image_uri()` filters the entity's
 * fields down to entity-reference fields that target `media`, are not empty,
 * and whose `handler_settings.target_bundles` literally contains `image`. It
 * then walks the referenced media, skipping anything that is not an
 * image-bundle media item with a thumbnail file, and `break 2`s out on the
 * first uri it finds.
 *
 * **The dimension read.** `neo_tokens_image_dimension()` guards on
 * `empty($uri) || !file_exists($uri)` — the guard the *"add file_exists check"*
 * fix added, and the reason this ticket exists — then memoises `getimagesize()`
 * per uri, so width and height cost one read between them.
 *
 * **The url build.** `neo_tokens_image_data()` returns the uri as given and its
 * absolute file url. Its other branch, behind `moduleExists('neo_image')`, is
 * **not covered here**: it would pull `neo_settings` and `breakpoint` into a
 * `neo` test to assert one module-existence check, and `neo_image` is its own
 * package with its own suite. That branch is delegated, deliberately.
 *
 * **Characterised, not repaired.** Seven answers pinned below are quirks rather
 * than intentions:
 *
 * 1. **Both entry points are documented `@return string` and return `NULL`.**
 *    They also promise the literal string `'image'` when nothing is found,
 *    which neither has ever returned. This is already candidate 9 in
 *    `docs/improvements/neo.md`; the test pins the `NULL`. Pinned in
 *    testReturnsNullFromBothEntryPointsWhenNothingResolves.
 * 2. **The guard both entry points use is inert, and the `return NULL;`
 *    behind it is dead code.** `if ($data = neo_tokens_image_fetch(...))` tests
 *    an array that always carries two keys, so it is true even when nothing
 *    resolved: the `NULL` every caller sees is `$data['url']`, and the
 *    function's own final `return NULL;` has never executed. Pinned in
 *    testReturnsNullFromBothEntryPointsWhenNothingResolves and in
 *    testMemoisesTheFetchPerEntityAndPerHookAndReturnsAnEmptyPair.
 * 3. **The alter hook runs once per entity and hook for the whole request.** A
 *    hook that reads `$params` only ever sees the first caller's, because the
 *    memo key does not include them — so `[neo:image:crop:100:100]` and
 *    `[neo:image:exact:50:50]` hand the same uri to the url build. Pinned in
 *    the same method.
 * 4. **A media reference field with no bundle restriction is skipped.** The
 *    filter requires `target_bundles` to contain `image`, and a field left
 *    unrestricted has an empty `target_bundles` — the configuration that
 *    accepts *every* bundle is the one the crawl refuses to look at. Pinned in
 *    testTakesTheFirstImageBundleThumbnailAndSkipsEverythingElse.
 * 5. **The dimension memo is keyed on the uri alone**, so a file replaced on
 *    disk mid-request keeps its first size, and a uri that could not be sized
 *    stays unsizeable even once it holds a real image. Pinned in
 *    testReturnsNullForMissingAndUnsizeableFilesAndReadsTheFileOnce.
 * 6. **Any dimension that is not `width` reads the height slot.** The read is
 *    `$dimension === 'width' ? 0 : 1`, so `'depth'` answers with the height.
 *    Pinned in the same method.
 * 7. **`neo_tokens_image_data()` takes a `$params` argument it never uses in
 *    this branch and never documents.** Its docblock has one `@param`, for
 *    `$uri`. Pinned in testBuildsTheAbsoluteFileUrlWhenNeoImageIsNotInstalled.
 * 8. **The `file_exists()` fix no longer changes any answer.** A later refactor
 *    put `@` in front of `getimagesize()` and `?: NULL` behind it, so a missing
 *    file resolves to `NULL` with or without the clause the fix added; today it
 *    buys a skipped stream read rather than a different return. Deleting it
 *    turns nothing in this class red, which is why the red recorded for that
 *    criterion restores the pre-fix reader instead. Pinned in
 *    testReturnsNullForMissingAndUnsizeableFilesAndReadsTheFileOnce.
 *
 * The six functions are called directly rather than through `neo_tokens()`. The
 * dispatcher and its two caches are ticket 11's subject and are pinned in
 * `SmartTokenDispatcherTest`; driving these through it would only put a second
 * memoisation layer between the assertion and the branch it is about.
 */
#[Group('neo')]
final class SmartTokenLogoAndImageTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`.
   *
   * `media`, `image` and `file` are what the crawl walks and what the dimension
   * read reads; `node` supplies the entities that carry the reference fields.
   * `token` is installed for the same undeclared-dependency reason as ticket 12
   * — `neo_tokens_title()` calls `token_render_array_value()` from a module
   * `neo.info.yml` does not declare — so that this class boots the same include
   * a real site boots, not a subset of it.
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
    'file',
    'image',
    'media',
    'token',
    'neo',
    'neo_test',
  ];

  /**
   * The source field name on the image media type.
   *
   * @var string
   */
  protected string $imageSourceField;

  /**
   * The source field name on the document media type.
   *
   * @var string
   */
  protected string $documentSourceField;

  /**
   * The uri of the 120x45 image the crawl is meant to find.
   *
   * @var string
   */
  protected string $uriA;

  /**
   * The uri of the 60x30 image sitting behind it in the same field.
   *
   * @var string
   */
  protected string $uriB;

  /**
   * The uri of a third image, used as an alter hook's contribution.
   *
   * @var string
   */
  protected string $uriC;

  /**
   * The image-bundle media item whose thumbnail was emptied in memory.
   *
   * @var \Drupal\media\Entity\Media
   */
  protected Media $thumblessMedia;

  /**
   * A node carrying every skip the crawl knows, then image A, then image B.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $node;

  /**
   * A node of a bundle with no fields at all.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $plainNode;

  /**
   * A node whose only media field is empty.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $emptyFieldNode;

  /**
   * A node whose only populated reference field targets nodes.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $nodeRefNode;

  /**
   * A node holding image A in a field restricted to the document bundle.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $otherMediaNode;

  /**
   * A node holding image A in a media field with no bundle restriction.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $anyMediaNode;

  /**
   * A node holding only a document-bundle media item.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $documentNode;

  /**
   * A node holding only the image media whose thumbnail is empty.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $thumblessNode;

  /**
   * A node holding only the image media whose thumbnail file was deleted.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $danglingNode;

  /**
   * A node holding only image B, so that image B is provably viable.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $onlyBNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    // Updating a node deletes its access grants, which needs the table.
    $this->installSchema('node', 'node_access');
    $this->installConfig([
      'system',
      'field',
      'filter',
      'node',
      'file',
      'image',
      'media',
    ]);

    // The six functions under test live in an include the module loads on
    // demand, so nothing has pulled it in yet.
    \Drupal::moduleHandler()->loadInclude('neo', 'tokens.inc');
    // The front-page comparison turns the current route into a path through the
    // URL generator, so the fixture routes have to be in the route provider.
    \Drupal::service('router.builder')->rebuild();

    $this->config('system.site')->set('name', 'Fixture site name')->save();

    $imageType = $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Image',
    ]);
    $this->imageSourceField = $imageType->getSource()
      ->getConfiguration()['source_field'];
    $documentType = $this->createMediaType('file', [
      'id' => 'document',
      'label' => 'Document',
    ]);
    $this->documentSourceField = $documentType->getSource()
      ->getConfiguration()['source_field'];

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();

    // One field per branch of the crawl's field filter, plus the multi-valued
    // one the media walk runs down.
    $this->addReferenceField('field_empty_media', 'media', ['image']);
    $this->addReferenceField('field_node_ref', 'node', ['page']);
    $this->addReferenceField('field_other_media', 'media', ['document']);
    $this->addReferenceField('field_any_media', 'media', []);
    $this->addReferenceField(
      'field_media',
      'media',
      ['image', 'document'],
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    );

    $this->uriA = 'public://neo-token-a.png';
    $this->uriB = 'public://neo-token-b.png';
    $this->uriC = 'public://neo-token-c.png';
    $mediaA = $this->createImageMedia('Image A', $this->uriA, 120, 45);
    $mediaB = $this->createImageMedia('Image B', $this->uriB, 60, 30);
    $this->createImageFile($this->uriC, 24, 12);

    $documentMedia = $this->createDocumentMedia();
    $this->thumblessMedia = $this->createImageMedia(
      'Thumbnail emptied',
      'public://neo-token-thumbless.png',
      10,
      10
    );
    $danglingMedia = $this->createImageMedia(
      'Thumbnail file deleted',
      'public://neo-token-dangling.png',
      10,
      10
    );
    // Deleting the file the thumbnail points at leaves the reference behind:
    // the field is not empty, but its `entity` is no longer a file.
    $danglingMedia->get('thumbnail')->entity->delete();

    $this->plainNode = $this->createNode('plain');
    $this->emptyFieldNode = $this->createNode('page');
    $this->nodeRefNode = $this->createNode('page', [
      'field_node_ref' => [['target_id' => $this->plainNode->id()]],
    ]);
    $this->otherMediaNode = $this->createNode('page', [
      'field_other_media' => [['target_id' => $mediaA->id()]],
    ]);
    $this->anyMediaNode = $this->createNode('page', [
      'field_any_media' => [['target_id' => $mediaA->id()]],
    ]);
    $this->documentNode = $this->createNode('page', [
      'field_media' => [['target_id' => $documentMedia->id()]],
    ]);
    $this->thumblessNode = $this->createNode('page', [
      'field_media' => [['target_id' => $this->thumblessMedia->id()]],
    ]);
    $this->danglingNode = $this->createNode('page', [
      'field_media' => [['target_id' => $danglingMedia->id()]],
    ]);
    $this->onlyBNode = $this->createNode('page', [
      'field_media' => [['target_id' => $mediaB->id()]],
    ]);
    $this->node = $this->createNode('page', [
      'field_node_ref' => [['target_id' => $this->plainNode->id()]],
      'field_other_media' => [['target_id' => $mediaA->id()]],
      'field_any_media' => [['target_id' => $mediaA->id()]],
      'field_media' => [
        ['target_id' => $documentMedia->id()],
        ['target_id' => $this->thumblessMedia->id()],
        ['target_id' => $danglingMedia->id()],
        ['target_id' => $mediaA->id()],
        ['target_id' => $mediaB->id()],
      ],
    ]);

    // A saved image media always has a thumbnail — `Media::preSave()` fills an
    // empty one with the generic icon, and `prepareSave()` refreshes it from
    // the source — so the only way to hand the crawl a media item with no
    // thumbnail is to empty one that is already loaded. The entity memory cache
    // is what makes the crawl see this same object, which the test asserts.
    $storage = \Drupal::entityTypeManager()->getStorage('media');
    $storage->resetCache();
    $this->thumblessMedia = $storage->load($this->thumblessMedia->id());
    $this->thumblessMedia->set('thumbnail', []);
  }

  /**
   * Resolves the logo from the hook alone, and delegates on the front page.
   *
   * Covers: "it resolves the logo from the alter hook without crawling the
   * entity, and delegates the image token to the logo on the front page".
   *
   * The entity in scope carries a media image the crawl finds on demand, which
   * is what makes the logo's `NULL` a statement about crawling rather than
   * about the fixture. The hook records every invocation whether or not it is
   * switched on, so the `NULL` it was handed is direct evidence that nothing
   * crawled before it ran.
   *
   * The front-page half arms the image hook with a third uri that would win
   * everywhere else; the logo's uri coming back, and the image hook's record
   * staying untouched, is the delegation.
   */
  public function testResolvesTheLogoFromTheAlterHookWithoutCrawlingAndDelegatesTheImageTokenOnTheFrontPage(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());

    // The crawl would find image A on this entity, on demand.
    $this->assertSame($this->uriA, neo_tokens_entity_to_media_image_uri($this->node));

    // The logo does not ask it. With the hook contributing nothing there is
    // nothing left for the logo to resolve.
    \Drupal::state()->delete('neo_test.token_logo_alter');
    $this->assertNull(neo_tokens_logo([], $this->node));
    $seen = \Drupal::state()->get('neo_test.token_logo_alter_seen');
    $this->assertNull($seen['uri']);
    $this->assertSame('node:' . $this->node->id(), $seen['entity']);

    // Switched on, the hook is the whole of the logo's answer.
    $this->resetTokenStatics();
    \Drupal::state()->set('neo_test.token_logo_alter', $this->uriB);
    $this->assertSame($this->absoluteUrl($this->uriB), neo_tokens_logo([], $this->node));

    // Off the front page the image token crawls and answers with image A, so
    // the two entry points genuinely differ on the same entity.
    $this->resetTokenStatics();
    \Drupal::state()->delete('neo_test.token_image_alter');
    $this->assertSame($this->absoluteUrl($this->uriA), neo_tokens_image([], $this->node));

    // On the front page the image token is the logo. The image hook is armed
    // with a uri that would beat the crawl, and is never invoked.
    $this->resetTokenStatics();
    \Drupal::state()->set('neo_test.token_image_alter', $this->uriC);
    $this->config('system.site')->set('page.front', '/neo-test/open')->save();
    $this->enterRequest('neo_test.open');
    $this->assertTrue(\Drupal::service('path.matcher')->isFrontPage());

    $this->assertSame($this->absoluteUrl($this->uriB), neo_tokens_image([], $this->node));
    $this->assertNull(\Drupal::state()->get('neo_test.token_image_alter_seen'));
    $this->assertSame(
      'node:' . $this->node->id(),
      \Drupal::state()->get('neo_test.token_logo_alter_seen')['entity']
    );

    // The parameters travel into the delegate intact, so a dimension asked of
    // the image token on the front page is a dimension of the logo's file.
    $this->resetTokenStatics();
    $this->assertSame('30', neo_tokens_image(['height'], $this->node));

    // And with no entity at all the front page still answers, because the logo
    // never needed one.
    $this->resetTokenStatics();
    $this->assertSame($this->absoluteUrl($this->uriB), neo_tokens_image([], NULL));
  }

  /**
   * Strips a leading width or height and answers with that dimension.
   *
   * Covers: "it strips a leading width or height parameter and returns that
   * dimension instead of the url".
   *
   * The stripping happens in both entry points, before the fetch, so the alter
   * hook never sees the parameter that decided what the token returns — which
   * the middle block asserts directly off the hook's own record. Only a
   * **leading** parameter counts: `width` in any other position is an ordinary
   * parameter and the url comes back.
   *
   * The dimension is returned as a string, because the read casts it — a
   * token replacement is text, and an int here would render the same but
   * compare differently.
   */
  public function testStripsTheLeadingWidthOrHeightParameterAndReturnsTheDimension(): void {
    $this->enterRequest('neo_test.open');
    \Drupal::state()->set('neo_test.token_logo_alter', $this->uriA);

    $this->resetTokenStatics();
    $width = neo_tokens_logo(['width'], $this->node);
    $this->assertSame('120', $width);
    $this->assertIsString($width);

    $this->resetTokenStatics();
    $this->assertSame('45', neo_tokens_logo(['height'], $this->node));

    // With no leading dimension the same call is the url.
    $this->resetTokenStatics();
    $this->assertSame($this->absoluteUrl($this->uriA), neo_tokens_logo([], $this->node));

    // The stripped parameter does not reach the hook; the rest do.
    $this->resetTokenStatics();
    $this->assertSame('120', neo_tokens_logo(['width', 'crop', '10'], $this->node));
    $this->assertSame(
      ['crop', '10'],
      \Drupal::state()->get('neo_test.token_logo_alter_seen')['params']
    );

    // `width` anywhere but the front of the list is an ordinary parameter.
    $this->resetTokenStatics();
    $this->assertSame(
      $this->absoluteUrl($this->uriA),
      neo_tokens_logo(['crop', 'width'], $this->node)
    );
    $this->assertSame(
      ['crop', 'width'],
      \Drupal::state()->get('neo_test.token_logo_alter_seen')['params']
    );

    // The image token strips identically, against a uri the crawl produced
    // rather than one a hook handed it.
    $this->resetTokenStatics();
    \Drupal::state()->delete('neo_test.token_image_alter');
    $this->assertSame('120', neo_tokens_image(['width'], $this->node));

    $this->resetTokenStatics();
    $this->assertSame('45', neo_tokens_image(['height'], $this->node));

    $this->resetTokenStatics();
    $this->assertSame('45', neo_tokens_image(['height', 'exact'], $this->node));
    $this->assertSame(
      ['exact'],
      \Drupal::state()->get('neo_test.token_image_alter_seen')['params']
    );

    $this->resetTokenStatics();
    $this->assertSame(
      $this->absoluteUrl($this->uriA),
      neo_tokens_image(['exact', 'height'], $this->node)
    );
  }

  /**
   * Answers NULL from both entry points, against a documented string return.
   *
   * Covers: "it returns null from both entry points when nothing resolves,
   * against their documented string return".
   *
   * Quirk 1. Both functions declare no return type in code and `@return string`
   * in prose, and both promise the literal `'image'` for the not-found case,
   * which neither has ever returned. This is candidate 9 in
   * `docs/improvements/neo.md` and is pinned here rather than repaired: the
   * plan's value is having no production diff, and the fix is a visible one
   * later.
   *
   * Every shape of "nothing" is exercised — no hook and no crawl, no entity at
   * all, and a dimension asked of a file that was never resolved. All of them
   * leave through the *same* `return`, which is quirk 2's second half: the
   * fetcher hands back a two-key array of `NULL`s, the guard in front of it is
   * therefore always true, and `$data['url']` is the `NULL`. Each function's
   * own closing `return NULL;` is unreachable and has never run.
   */
  public function testReturnsNullFromBothEntryPointsWhenNothingResolves(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());
    \Drupal::state()->delete('neo_test.token_logo_alter');
    \Drupal::state()->delete('neo_test.token_image_alter');

    // The logo, with an entity that has an image and a hook that declines.
    $this->assertNull(neo_tokens_logo([], $this->node));
    $this->resetTokenStatics();
    $this->assertNull(neo_tokens_logo([], NULL));
    $this->resetTokenStatics();
    $this->assertNull(neo_tokens_logo(['width'], $this->node));
    $this->resetTokenStatics();
    $this->assertNull(neo_tokens_logo(['height'], NULL));

    // The image, with an entity there is nothing to crawl on.
    $this->resetTokenStatics();
    $this->assertNull(neo_tokens_image([], $this->plainNode));
    $this->resetTokenStatics();
    $this->assertNull(neo_tokens_image(['width'], $this->plainNode));
    $this->resetTokenStatics();
    $this->assertNull(neo_tokens_image([], NULL));

    // And on the front page, where the image token's NULL is the logo's.
    $this->resetTokenStatics();
    $this->config('system.site')->set('page.front', '/neo-test/open')->save();
    $this->enterRequest('neo_test.open');
    $this->assertTrue(\Drupal::service('path.matcher')->isFrontPage());
    $this->assertNull(neo_tokens_image([], $this->node));

    // Quirk 2, second half: every NULL above came through `$data['url']`,
    // because the guard in front of it is an array that is never empty. The
    // `return NULL;` closing each function is dead code.
    $this->resetTokenStatics();
    $this->assertNotEmpty(
      neo_tokens_image_fetch([], 'neo_token_logo', $this->node, FALSE)
    );

    // Quirk 1: no declared return type, `@return string` in prose, and a
    // promise of the string `'image'` that no branch can keep.
    foreach (['neo_tokens_logo', 'neo_tokens_image'] as $function) {
      $reflection = new \ReflectionFunction($function);
      $this->assertNull($reflection->getReturnType());
      $docblock = (string) $reflection->getDocComment();
      $this->assertStringContainsString('@return string', $docblock);
      $this->assertStringNotContainsString('@return string|null', $docblock);
      $this->assertStringContainsString(
        "or 'image' if no suitable image is found",
        $docblock
      );
    }
  }

  /**
   * Memoises per entity and per hook, and hands back an empty pair.
   *
   * Covers: "it memoises the fetch per entity and per hook and returns an empty
   * uri and url pair when the uri stays empty".
   *
   * The memo key is `{entity_type}:{id}:{hook}`, so the two things that vary it
   * are asserted separately: the same entity under the other hook re-runs, and
   * a different entity under the same hook re-runs. Everything else — the
   * parameters, and even `$crawlEntity` itself — is outside the key, which is
   * quirk 3 and is what the armed-but-unreached hook proves.
   *
   * The last block is quirk 2: nothing resolved is a two-key array of `NULL`s,
   * not an empty array and not an exception, so the `if ($data = ...)` guard in
   * both entry points is true either way.
   */
  public function testMemoisesTheFetchPerEntityAndPerHookAndReturnsAnEmptyPair(): void {
    $this->enterRequest('neo_test.open');
    \Drupal::state()->delete('neo_test.token_image_alter');
    \Drupal::state()->delete('neo_test.token_logo_alter');
    $this->resetTokenStatics();

    $first = neo_tokens_image_fetch([], 'neo_token_image', $this->node, TRUE);
    $this->assertSame($this->uriA, $first['uri']);
    $this->assertSame($this->absoluteUrl($this->uriA), $first['url']);
    $this->assertSame(
      'node:' . $this->node->id(),
      \Drupal::state()->get('neo_test.token_image_alter_seen')['entity']
    );

    // Quirk 3: a second call for the same entity and hook neither crawls nor
    // invokes the hook, however differently it was asked. The hook is armed
    // with another uri and its record stays untouched.
    \Drupal::state()->delete('neo_test.token_image_alter_seen');
    \Drupal::state()->set('neo_test.token_image_alter', $this->uriC);
    $second = neo_tokens_image_fetch(['crop', '100'], 'neo_token_image', $this->node, TRUE);
    $this->assertSame($first, $second);
    $this->assertNull(\Drupal::state()->get('neo_test.token_image_alter_seen'));

    // Even switching crawling off is outside the key.
    $third = neo_tokens_image_fetch([], 'neo_token_image', $this->node, FALSE);
    $this->assertSame($first, $third);

    // Per hook: the same entity under `neo_token_logo` is a different key, and
    // it is the logo hook that runs.
    $logo = neo_tokens_image_fetch([], 'neo_token_logo', $this->node, FALSE);
    $this->assertSame(['uri' => NULL, 'url' => NULL], $logo);
    $this->assertSame(
      'node:' . $this->node->id(),
      \Drupal::state()->get('neo_test.token_logo_alter_seen')['entity']
    );

    // Per entity: a different entity is a different key, and the armed image
    // hook finally gets to answer.
    $other = neo_tokens_image_fetch([], 'neo_token_image', $this->plainNode, TRUE);
    $this->assertSame($this->uriC, $other['uri']);
    $this->assertSame(
      'node:' . $this->plainNode->id(),
      \Drupal::state()->get('neo_test.token_image_alter_seen')['entity']
    );

    // And no entity at all is a third key of its own.
    $none = neo_tokens_image_fetch([], 'neo_token_image', NULL, TRUE);
    $this->assertSame($this->uriC, $none['uri']);
    $this->assertNull(\Drupal::state()->get('neo_test.token_image_alter_seen')['entity']);

    // Quirk 2: nothing resolved is an empty pair, not an empty array — so the
    // guard both entry points put in front of it is always true.
    \Drupal::state()->delete('neo_test.token_image_alter');
    $this->resetTokenStatics();
    $empty = neo_tokens_image_fetch([], 'neo_token_image', $this->plainNode, TRUE);
    $this->assertSame(['uri' => NULL, 'url' => NULL], $empty);
    $this->assertNotEmpty($empty);
    $this->assertCount(2, $empty);
  }

  /**
   * Takes the first image-bundle thumbnail and skips everything else.
   *
   * Covers: "it takes the first image-bundle media thumbnail from the entity
   * and skips empty fields, non-media references, non-image bundles and empty
   * thumbnails".
   *
   * Each skip is asserted on a node that carries nothing but that skip, so a
   * `NULL` names one branch rather than a combination. The two thumbnail skips
   * are different shapes of the same idea: one field is genuinely empty, the
   * other still holds a reference whose file has been deleted.
   *
   * Quirk 4 is the `field_any_media` line: a media reference field with no
   * bundle restriction — the configuration that accepts image media along with
   * everything else — has an empty `target_bundles`, and the filter requires
   * `image` to be *in* it, so the crawl never looks.
   *
   * The last block is the `break 2`: image B is provably resolvable on its own,
   * and is not what the multi-valued field answers with.
   */
  public function testTakesTheFirstImageBundleThumbnailAndSkipsEverythingElse(): void {
    // The crawl's own answer, against a node carrying every skip in front of a
    // match and one more match behind it.
    $this->assertSame($this->uriA, neo_tokens_entity_to_media_image_uri($this->node));

    // Nothing to look at.
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->plainNode));
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->emptyFieldNode));

    // A reference field that does not target media.
    $this->assertSame(
      'node',
      $this->nodeRefNode->get('field_node_ref')
        ->getFieldDefinition()->getSetting('target_type')
    );
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->nodeRefNode));

    // A media field whose handler settings do not name the image bundle — the
    // media it holds is image A itself, so only the field is disqualifying.
    $this->assertSame(
      $this->uriA,
      $this->otherMediaNode->get('field_other_media')
        ->referencedEntities()[0]->get('thumbnail')->entity->getFileUri()
    );
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->otherMediaNode));

    // Quirk 4: and neither does a field with no bundle restriction at all.
    $this->assertSame(
      [],
      $this->anyMediaNode->get('field_any_media')
        ->getFieldDefinition()->getSetting('handler_settings')
    );
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->anyMediaNode));

    // A media item of the wrong bundle inside a field that does name `image`.
    $this->assertSame(
      'document',
      $this->documentNode->get('field_media')->referencedEntities()[0]->bundle()
    );
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->documentNode));

    // An image-bundle media item with no thumbnail at all. The crawl sees the
    // object this test emptied, which is what the identity assertion is for.
    $referenced = $this->thumblessNode->get('field_media')->referencedEntities();
    $this->assertSame($this->thumblessMedia, $referenced[0]);
    $this->assertSame('image', $referenced[0]->bundle());
    $this->assertTrue($referenced[0]->get('thumbnail')->isEmpty());
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->thumblessNode));

    // And one whose thumbnail still points at a file that no longer exists.
    $dangling = $this->danglingNode->get('field_media')->referencedEntities()[0];
    $this->assertFalse($dangling->get('thumbnail')->isEmpty());
    $this->assertNull($dangling->get('thumbnail')->entity);
    $this->assertNull(neo_tokens_entity_to_media_image_uri($this->danglingNode));

    // The first match wins and the walk stops: image B sits one delta behind
    // image A on `$this->node`, and resolves perfectly well on its own.
    $this->assertNotSame($this->uriA, $this->uriB);
    $this->assertSame($this->uriB, neo_tokens_entity_to_media_image_uri($this->onlyBNode));
    $this->assertSame($this->uriA, neo_tokens_entity_to_media_image_uri($this->node));
  }

  /**
   * Refuses a missing or unsizeable file, and reads a real one once.
   *
   * Covers: "it returns null for a missing file and for a file it cannot size,
   * and reads the file once for both dimensions".
   *
   * The `!file_exists($uri)` half of the guard is the *"add file_exists check"*
   * fix this ticket exists for: before it, a uri naming no file reached
   * `getimagesize()` and warned. The empty-uri half in front of it is what
   * makes the entry points safe to call when nothing resolved.
   *
   * Quirk 8 is the shape that guard is in today, asserted directly below it.
   *
   * Quirk 5 is the memo, which is keyed on the uri and nothing else. It is
   * asserted in both directions — a real image replaced on disk keeps its first
   * size, and a uri that could not be sized stays unsizeable even once it holds
   * an image — because the memo stores `NULL` for a failed read and
   * `array_key_exists()` is what reads it back, so the failure sticks too.
   *
   * The last line is quirk 6.
   */
  public function testReturnsNullForMissingAndUnsizeableFilesAndReadsTheFileOnce(): void {
    // The empty half of the guard.
    $this->assertNull(neo_tokens_image_dimension(NULL, 'width'));
    $this->assertNull(neo_tokens_image_dimension('', 'height'));

    // The file-existence half — the fix.
    $missing = 'public://neo-token-missing.png';
    $this->assertFalse(file_exists($missing));
    $this->assertNull(neo_tokens_image_dimension($missing, 'width'));
    $this->assertNull(neo_tokens_image_dimension($missing, 'height'));

    // Quirk 8: the read behind the guard would answer NULL for that uri on its
    // own, because `@` swallows the warning and `?: NULL` turns FALSE into
    // NULL. The clause the fix added stops the stream read; it is no longer
    // what decides the answer.
    $this->assertFalse(@getimagesize($missing));

    // A file that exists and cannot be sized.
    $text = 'public://neo-token-not-an-image.txt';
    file_put_contents($text, 'not an image');
    clearstatcache();
    $this->assertTrue(file_exists($text));
    $this->assertNull(neo_tokens_image_dimension($text, 'width'));

    // Quirk 5, the failed read: that answer is memoised, so the same uri now
    // holding a real image still answers NULL until the static is reset.
    $this->writeImage($text, 8, 9);
    $this->assertSame([8, 9], array_slice((array) getimagesize($text), 0, 2));
    $this->assertNull(neo_tokens_image_dimension($text, 'width'));
    drupal_static_reset('neo_tokens_image_dimension');
    $this->assertSame('8', neo_tokens_image_dimension($text, 'width'));

    // A real image, both dimensions, as strings.
    drupal_static_reset('neo_tokens_image_dimension');
    $width = neo_tokens_image_dimension($this->uriA, 'width');
    $this->assertSame('120', $width);
    $this->assertIsString($width);

    // Quirk 5, the successful read: one `getimagesize()` serves both
    // dimensions. Replacing the file on disk between the two calls changes
    // what a second read would say and changes nothing about the answer.
    $this->writeImage($this->uriA, 33, 77);
    $this->assertSame([33, 77], array_slice((array) getimagesize($this->uriA), 0, 2));
    $this->assertSame('45', neo_tokens_image_dimension($this->uriA, 'height'));

    drupal_static_reset('neo_tokens_image_dimension');
    $this->assertSame('77', neo_tokens_image_dimension($this->uriA, 'height'));
    $this->assertSame('33', neo_tokens_image_dimension($this->uriA, 'width'));

    // Quirk 6: the read is `$dimension === 'width' ? 0 : 1`, so anything that
    // is not `width` is the height.
    $this->assertSame('77', neo_tokens_image_dimension($this->uriA, 'depth'));
    $this->assertSame('77', neo_tokens_image_dimension($this->uriA, ''));
  }

  /**
   * Builds the uri as given and its absolute file url.
   *
   * Covers: "it builds the absolute file url when neo_image is not installed".
   *
   * This is the fallback branch, and the only one this class covers. The
   * `neo_image` branch behind `moduleExists('neo_image')` derives a style uri
   * and a style url from the same input and is **delegated**: reaching it means
   * installing `neo_image`, which depends on `neo_settings` and `breakpoint`,
   * to assert one module-existence check inside a package with its own suite.
   *
   * The url is absolute because that is what a meta tag needs — a social
   * network reading `og:image` has no base to resolve a relative path against —
   * and the assertion is written as scheme-and-host plus the relative form
   * rather than against the generator's own output, so it says something.
   *
   * The middle block is quirk 7: `$params` is accepted, undocumented, and
   * unreachable in this branch.
   */
  public function testBuildsTheAbsoluteFileUrlWhenNeoImageIsNotInstalled(): void {
    $this->enterRequest(NULL);
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('neo_image'));

    $data = neo_tokens_image_data($this->uriA);
    $this->assertSame(['uri', 'url'], array_keys($data));

    // The uri travels through untouched — it is the style-derived value that
    // the `neo_image` branch would replace it with.
    $this->assertSame($this->uriA, $data['uri']);

    $url = $data['url'];
    $this->assertStringStartsWith('http://', $url);
    $this->assertStringEndsWith('/neo-token-a.png', $url);
    $this->assertStringNotContainsString('public://', $url);

    $relative = \Drupal::service('file_url_generator')->generateString($this->uriA);
    $this->assertStringStartsWith('/', $relative);
    $this->assertSame(
      \Drupal::request()->getSchemeAndHttpHost() . $relative,
      $url
    );

    // Quirk 7: the parameters this branch is handed are never read, and the
    // function's own docblock never mentions them.
    $this->assertSame($data, neo_tokens_image_data($this->uriA, ['exact', '1200', '630']));
    $this->assertSame($data, neo_tokens_image_data($this->uriA, ['nonsense']));
    $docblock = (string) (new \ReflectionFunction('neo_tokens_image_data'))->getDocComment();
    $this->assertStringContainsString('@param string $uri', $docblock);
    $this->assertStringNotContainsString('$params', $docblock);

    // The url build never touches the filesystem, so a uri naming no file
    // still produces one — the dimension read is where that is caught.
    $missing = neo_tokens_image_data('public://neo-token-missing.png');
    $this->assertFalse(file_exists('public://neo-token-missing.png'));
    $this->assertStringEndsWith('/neo-token-missing.png', $missing['url']);
  }

  /**
   * Builds the absolute file url for a uri, the way the fallback branch does.
   *
   * @param string $uri
   *   The file uri.
   *
   * @return string
   *   The absolute url.
   */
  private function absoluteUrl(string $uri): string {
    return \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
  }

  /**
   * Resets the two statics the image chain memoises in.
   *
   * Both are `drupal_static()` caches named after their own function, and both
   * live for the whole request. A test method exercising more than one arm of
   * the same entry point has to clear them between arms or it is asserting the
   * first arm twice.
   */
  private function resetTokenStatics(): void {
    drupal_static_reset('neo_tokens_image_fetch');
    drupal_static_reset('neo_tokens_image_dimension');
  }

  /**
   * Adds an entity reference field to the `page` node type.
   *
   * @param string $fieldName
   *   The field name.
   * @param string $targetType
   *   The entity type the field references.
   * @param string[] $targetBundles
   *   The bundles the handler settings name. An empty array is the shape a
   *   field with no bundle restriction really has.
   * @param int $cardinality
   *   The storage cardinality.
   */
  private function addReferenceField(string $fieldName, string $targetType, array $targetBundles, int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => $cardinality,
      'settings' => ['target_type' => $targetType],
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'bundle' => 'page',
      'settings' => [
        'handler' => 'default:' . $targetType,
        'handler_settings' => $targetBundles
          ? ['target_bundles' => array_combine($targetBundles, $targetBundles)]
          : [],
      ],
    ])->save();
  }

  /**
   * Writes a PNG of exactly the given size to a uri.
   *
   * The sizes matter: every dimension this class asserts is one written here,
   * so a passing assertion is about `getimagesize()` rather than about a
   * fixture file someone else picked.
   *
   * @param string $uri
   *   The uri to write to.
   * @param int $width
   *   The image width.
   * @param int $height
   *   The image height.
   */
  private function writeImage(string $uri, int $width, int $height): void {
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    imagedestroy($image);
    file_put_contents($uri, $png);
    clearstatcache();
  }

  /**
   * Creates a permanent file entity for a PNG of the given size.
   *
   * @param string $uri
   *   The uri to write to.
   * @param int $width
   *   The image width.
   * @param int $height
   *   The image height.
   *
   * @return \Drupal\file\Entity\File
   *   The saved file.
   */
  private function createImageFile(string $uri, int $width, int $height): File {
    $this->writeImage($uri, $width, $height);
    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Creates an image-bundle media item wrapping a PNG of the given size.
   *
   * @param string $name
   *   The media item's name.
   * @param string $uri
   *   The uri to write the image to.
   * @param int $width
   *   The image width.
   * @param int $height
   *   The image height.
   *
   * @return \Drupal\media\Entity\Media
   *   The saved media item, whose thumbnail is the file just written.
   */
  private function createImageMedia(string $name, string $uri, int $width, int $height): Media {
    $file = $this->createImageFile($uri, $width, $height);
    $media = Media::create([
      'bundle' => 'image',
      'name' => $name,
      $this->imageSourceField => ['target_id' => $file->id()],
    ]);
    $media->save();
    $this->assertSame($uri, $media->get('thumbnail')->entity->getFileUri());
    return $media;
  }

  /**
   * Creates a document-bundle media item, which the crawl must skip.
   *
   * @return \Drupal\media\Entity\Media
   *   The saved media item.
   */
  private function createDocumentMedia(): Media {
    $uri = 'public://neo-token-doc.txt';
    file_put_contents($uri, 'a document');
    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'document',
      'name' => 'A document',
      $this->documentSourceField => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Creates and saves a node.
   *
   * @param string $type
   *   The node type.
   * @param array $values
   *   Field values.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved node.
   */
  private function createNode(string $type, array $values = []): Node {
    $node = Node::create($values + [
      'type' => $type,
      'title' => 'Node ' . $type,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Enters a fresh request carrying the named route, or no route at all.
   *
   * A new request object each time is what makes the front-page answer
   * observable more than once in a process: `CurrentRouteMatch` memoises its
   * match per request object and `PathMatcher` memoises its own answer on
   * itself, so both are replaced rather than mutated.
   *
   * @param string|null $routeName
   *   The route to put on the request, or NULL for a request with no route
   *   object — the shape a replacement outside a routed context really has.
   */
  private function enterRequest(?string $routeName): void {
    $request = Request::create('/');
    if ($routeName !== NULL) {
      $request->attributes->set(
        RouteObjectInterface::ROUTE_OBJECT,
        \Drupal::service('router.route_provider')->getRouteByName($routeName)
      );
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $routeName);
    }
    // The harness's own teardown reads a session off whatever request is
    // current, so the mock session `DrupalKernel::preHandle()` started has to
    // travel with the replacement — which is also what a real request has.
    $current = \Drupal::requestStack()->getCurrentRequest();
    if ($current !== NULL && $current->hasSession()) {
      $request->setSession($current->getSession());
    }
    \Drupal::requestStack()->push($request);
    $this->container->set('path.matcher', new PathMatcher(
      $this->container->get('config.factory'),
      $this->container->get('current_route_match')
    ));
    \Drupal::state()->delete('neo_test.token_logo_alter_seen');
    \Drupal::state()->delete('neo_test.token_image_alter_seen');
  }

}
