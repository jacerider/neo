<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Url;
use Drupal\linkit\Entity\Profile;
use Drupal\Tests\neo\Fixtures\TestNeoLinkitFormatterConsumer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the link read path end to end.
 *
 * `NeoLinkitFormatterTrait::getLinkitUrl()` is what turns a stored link item
 * into the URL that is actually rendered. It resolves the item's uri to an
 * entity, asks the named Linkit profile which substitution the entity's type
 * wants, and hands the entity to that substitution plugin.
 *
 * Two of this seam's five production fixes are in the tail of that method, and
 * neither had any cover before this file. The substitution plugin is given an
 * *entity*, not a uri, so whatever the stored uri carried after its path is
 * gone by the time a `Url` comes back: `entity:node/23?market=1` rendered as
 * `/node/23`, and `entity:node/23#leadership` rendered without the anchor. The
 * two blocks at the end put the query and the fragment back on.
 *
 * The third fix is the `is_string($item->uri)` guard, and it is pinned here
 * too — a link item whose uri is not a string answers `NULL` rather than
 * fataling inside the upstream helper.
 *
 * Note which half of the seam this path uses. `getLinkitUrl()` resolves the
 * uri through upstream `LinkitHelper::getEntityFromUserInput()`, not through
 * `NeoLinkitTrait`'s fork — that is the divergence the plan is about, and
 * `NeoLinkitForkDivergenceTest` pins it. Everything here is written against
 * `entity:` uris, which both halves resolve identically, so these pins are the
 * ones that have to still hold after the read path switches.
 *
 * The trait is reached through `TestNeoLinkitFormatterConsumer`, because
 * `getLinkitUrl()` is protected and `NeoLinkFormatter` is a field plugin that
 * would need the whole formatter machinery to construct.
 */
#[Group('neo')]
final class NeoLinkitFormatterUrlTest extends NeoLinkitKernelTestBase {

  /**
   * The trait's consumer.
   */
  protected TestNeoLinkitFormatterConsumer $seam;

  /**
   * {@inheritdoc}
   *
   * A real `LinkItemInterface` is only obtainable from a real link field, so
   * one is attached to the `page` bundle.
   *
   * The `default` Linkit profile is not created here — it is the one Linkit
   * itself ships as optional config, installed because `node` is enabled, and
   * it already carries an `entity:node` matcher set to the canonical
   * substitution. Using the shipped profile rather than a hand-built one is
   * what makes these pins describe the configuration a real site has. Only
   * `no_node_matcher` is built, and it deliberately carries a matcher for a
   * different entity type, which is what the
   * fall-back-to-the-default-substitution criterion needs.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createLinkField();

    // The shipped profile, and the matcher these criteria resolve through.
    $default = Profile::load('default');
    $this->assertNotNull($default);
    $this->assertNotNull($default->getMatcherByEntityType('node'));
    $this->assertSame(
      'canonical',
      $default->getMatcherByEntityType('node')->getConfiguration()['settings']['substitution_type']
    );

    $other = Profile::create(['id' => 'no_node_matcher', 'label' => 'No node matcher']);
    $other->addMatcher(['id' => 'entity:user']);
    $other->save();

    $this->seam = new TestNeoLinkitFormatterConsumer();
  }

  /**
   * A link item becomes a substituted URL that keeps its query and fragment.
   *
   * The bare case first, because it is what the other three are measured
   * against: an `entity:` uri with nothing after it produces exactly the
   * entity's canonical URL, and that URL is a `Url` object rather than a
   * string — `Drupal\linkit\SubstitutionInterface::getUrl()` says so and both
   * plugins Linkit ships return one.
   *
   * Then the three that carry a **uri tail**. What makes them worth pinning is
   * that the tail never reaches the substitution plugin at all: the plugin is
   * handed the entity, and the entity knows nothing about the uri it was
   * looked up from. So `?market=1` and `#leadership` are re-applied
   * afterwards, by two separate blocks reading the raw uri string a second
   * time. Both were written after the loss was found in production, and the
   * both-at-once case is the one that proves the two blocks compose rather
   * than the second overwriting the first.
   *
   * `toString()` is the assertion rather than `getOption()` because it is what
   * the rendered page actually contains, and because it is the level at which
   * a query re-applied in the wrong place would be visible.
   *
   * Covers: it builds a substituted URL for a link item and re-applies a query
   * string, a fragment, and both.
   */
  public function testLinkItemBecomesSubstitutedUrlKeepingQueryAndFragment(): void {
    $node = $this->createPage();
    $id = $node->id();

    // The bare case: the canonical URL of the entity, as a Url object.
    $url = $this->seam->linkitUrl($this->linkItem('entity:node/' . $id));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('entity.node.canonical', $url->getRouteName());
    $this->assertSame('/node/' . $id, $url->toString());

    // A query string on the stored uri survives substitution.
    $url = $this->seam->linkitUrl($this->linkItem('entity:node/' . $id . '?market=1'));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame(['market' => '1'], $url->getOption('query'));
    $this->assertSame('/node/' . $id . '?market=1', $url->toString());

    // A fragment on the stored uri survives substitution.
    $url = $this->seam->linkitUrl($this->linkItem('entity:node/' . $id . '#leadership'));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('leadership', $url->getOption('fragment'));
    $this->assertSame('/node/' . $id . '#leadership', $url->toString());

    // Both at once: the query block stops at the '#', and the fragment block
    // is applied on top of it rather than instead of it.
    $url = $this->seam->linkitUrl(
      $this->linkItem('entity:node/' . $id . '?market=1&sort=name#leadership')
    );
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame(['market' => '1', 'sort' => 'name'], $url->getOption('query'));
    $this->assertSame('leadership', $url->getOption('fragment'));
    $this->assertSame(
      '/node/' . $id . '?market=1&sort=name#leadership',
      $url->toString()
    );
  }

  /**
   * A non-string uri, a missing profile, and no matcher for the entity type.
   *
   * Three exits, and they fail at three different depths.
   *
   * The non-string uri never gets as far as resolving anything. It is the
   * third of this seam's five production fixes: `$item->uri` is a typed-data
   * property and nothing stops it holding an array, and handing an array to
   * the upstream helper is a fatal rather than a miss. The guard turns it into
   * a `NULL`, and a `NULL` is what the formatter is built to handle.
   *
   * The missing profile fails one step later — the entity resolved, but the
   * profile the caller named does not exist, so there is no matcher list to
   * consult and no way to know which substitution the site wanted.
   *
   * The third is not a failure at all, and that is the point. A profile that
   * exists but carries no matcher for this entity type falls back to
   * `SubstitutionManagerInterface::DEFAULT_SUBSTITUTION`, so a node still
   * renders its canonical URL. This is the branch phpstan reports as
   * `ternary.alwaysTrue`, on the strength of an inline `@var` sitting directly
   * above it that declares the matcher non-null — and Linkit's own interface
   * documents that method as returning NULL when nothing matches. The
   * annotation is the defect; the branch is live, and this assertion is what
   * says so.
   *
   * Covers: it returns null for a non-string uri, for a profile that does not
   * exist, and falls back to the default substitution when no matcher matches
   * the entity type.
   */
  public function testNonStringUriMissingProfileAndUnmatchedEntityType(): void {
    $node = $this->createPage();
    $id = $node->id();

    // A uri that is not a string is refused before it is resolved.
    $item = $this->linkItem(['entity:node/' . $id]);
    $this->assertIsNotString($item->uri);
    $this->assertNull($this->seam->linkitUrl($item));

    // A profile id nothing has been saved under.
    $this->assertNull(
      $this->seam->linkitUrl($this->linkItem('entity:node/' . $id), 'no_such_profile')
    );

    // A profile that exists but has no matcher for nodes still substitutes,
    // through the default. The matcher lookup really does answer NULL here.
    $profile = Profile::load('no_node_matcher');
    $this->assertNotNull($profile);
    $this->assertNull($profile->getMatcherByEntityType('node'));

    $url = $this->seam->linkitUrl($this->linkItem('entity:node/' . $id), 'no_node_matcher');
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('/node/' . $id, $url->toString());
  }

}
