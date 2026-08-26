<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Url;
use Drupal\linkit\Utility\LinkitHelper;
use Drupal\Tests\neo\Fixtures\TestNeoLinkitConsumer;
use Drupal\Tests\neo\Fixtures\TestNeoLinkitFormatterConsumer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the two answers the fork and upstream give to the same input.
 *
 * There is not one Linkit seam in this module, there are two.
 * `NeoLinkitTrait` is a method-for-method fork of Linkit's `LinkitHelper` —
 * the **link write path** — and it has taken fixes upstream never got.
 * `NeoLinkitFormatterTrait` — the **link read path** — does not use the fork
 * at all: `getLinkitUrl()` calls `LinkitHelper::getEntityFromUserInput()`
 * directly. So an editor's input is massaged into a stored uri by Neo's copy
 * and resolved back to an entity by Linkit's copy.
 *
 * For the inputs this seam sees, the two copies differ in exactly two places,
 * and both are in the entity-from-a-stored-uri step:
 *
 * 1. **The `entity:` scheme is detected by parse in the fork and by substring
 *    search upstream.** `NeoLinkitTrait::getLinkitEntityFromUri()` asks
 *    `parse_url()` for the scheme; `LinkitHelper::getEntityFromUri()` asks
 *    whether the string contains `entity:` *anywhere* and, if it does, keeps
 *    whatever sits between the first and second colon. `/entity:node/23` is
 *    the shape that separates them: to upstream it is node 23, to the fork it
 *    is a path whose first segment carries a colon and is refused.
 * 2. **`internal:` and `base:` are stripped by the fork and left in place
 *    upstream.** `internal:/node/23` and `base:node/23` resolve to node 23
 *    through the fork. Upstream reaches neither, because with the prefix still
 *    attached the first segment is `internal:` or `base:node`, and a segment
 *    carrying a colon is refused there too.
 *
 * Which half produces which is named at every assertion below, because that is
 * the whole point of the file: **ticket 03 replaces exactly these pins** when
 * the read path stops calling upstream, and whoever does that has to be able
 * to see which answer is changing.
 *
 * One nuance worth having written down, because it is the reason the switch is
 * argued as a near-no-op. The divergence lives in `getEntityFromUri()`, and
 * the wrapper above it — `getEntityFromUserInput()` — papers over half of it:
 * when the first step misses, both copies fall through to `Url::fromUri()` and
 * the router, and the router resolves `internal:/node/23` perfectly well.
 * `base:node/23` is refused by both, because `base:` produces an unrouted URL
 * that has no route name to read. Only difference 1 survives all the way to
 * the read path's observable answer, and the last criterion here pins that.
 */
#[Group('neo')]
final class NeoLinkitForkDivergenceTest extends NeoLinkitKernelTestBase {

  /**
   * The fork and upstream disagree on two inputs; both answers are pinned.
   *
   * Read this as three blocks, each one input.
   *
   * `/entity:node/N` is difference 1. Upstream's `strpos($uri, 'entity:')`
   * matches in the middle of the string, `explode(':')` then hands back
   * `node/N` from between the colons, and a node comes out. The fork's
   * `parse_url()` reports no scheme at all for a string that begins with a
   * slash, so the string is trimmed and split on `/`, the first segment is
   * `entity:node`, and the derivative-separator guard refuses it. This is the
   * one difference that survives into `getEntityFromUserInput()` as well,
   * because upstream's first step already answered.
   *
   * `internal:/node/N` and `base:node/N` are difference 2, in both directions
   * at once. The fork strips a known on-site scheme and resolves; upstream
   * leaves it attached and refuses. But at the `getEntityFromUserInput()`
   * level the two agree again on both: `internal:` is picked up by the router
   * fallback in either copy, and `base:` is refused by both because
   * `Url::fromUri()` makes it an unrouted URL whose route name cannot be read.
   *
   * Covers: it pins the fork and upstream answers for the two inputs on which
   * they differ, each named in the test as such.
   */
  public function testTheForkAndUpstreamAnswerTwoInputsDifferently(): void {
    $node = $this->createPage();
    $id = $node->id();

    // ---------------------------------------------------------------------
    // Difference 1: `entity:` found by substring search, anywhere.
    // ---------------------------------------------------------------------
    // FORK (\Drupal\neo\NeoLinkitTrait::getLinkitEntityFromUri) answers NULL:
    // parse_url() reports no scheme, so the string is split on '/' and the
    // segment 'entity:node' is refused for carrying a colon.
    $this->assertNull(
      TestNeoLinkitConsumer::getLinkitEntityFromUri('/entity:node/' . $id),
      'The fork refuses /entity:node/N.'
    );
    // UPSTREAM (\Drupal\linkit\Utility\LinkitHelper::getEntityFromUri) answers
    // the node: 'entity:' is found mid-string and everything between the first
    // and second colon is taken as the "type/id" path.
    $this->assertSame(
      $id,
      LinkitHelper::getEntityFromUri('/entity:node/' . $id)?->id(),
      'Upstream resolves /entity:node/N to the node.'
    );

    // The same split one level up, where upstream's first step has already
    // answered and the fork has to fall through to the router — which does not
    // know this path either.
    $this->assertNull(
      TestNeoLinkitConsumer::getLinkitEntityFromUserInput('/entity:node/' . $id),
      'The fork still refuses /entity:node/N through getEntityFromUserInput.'
    );
    $this->assertSame(
      $id,
      LinkitHelper::getEntityFromUserInput('/entity:node/' . $id)?->id(),
      'Upstream still resolves /entity:node/N through getEntityFromUserInput.'
    );

    // ---------------------------------------------------------------------
    // Difference 2: the `internal:` and `base:` prefixes the fork strips.
    // ---------------------------------------------------------------------
    // FORK answers the node for both: the scheme is on the known on-site list
    // and is removed before the "type/id" split.
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('internal:/node/' . $id)?->id(),
      'The fork strips internal: and resolves.'
    );
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('base:node/' . $id)?->id(),
      'The fork strips base: and resolves.'
    );
    // UPSTREAM answers NULL for both: with the prefix still attached the first
    // segment is 'internal:' / 'base:node', and a colon in a segment is
    // refused there for the same reason it is in the fork.
    $this->assertNull(
      LinkitHelper::getEntityFromUri('internal:/node/' . $id),
      'Upstream leaves internal: in place and refuses.'
    );
    $this->assertNull(
      LinkitHelper::getEntityFromUri('base:node/' . $id),
      'Upstream leaves base: in place and refuses.'
    );

    // One level up the two agree again, and this is why the switch is argued
    // as a near-no-op rather than assumed to be one.
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUserInput('internal:/node/' . $id)?->id()
    );
    $this->assertSame(
      $id,
      LinkitHelper::getEntityFromUserInput('internal:/node/' . $id)?->id()
    );
    $this->assertNull(
      TestNeoLinkitConsumer::getLinkitEntityFromUserInput('base:node/' . $id),
      'base: is an unrouted URL, so the fork gets no route name to read.'
    );
    $this->assertNull(
      LinkitHelper::getEntityFromUserInput('base:node/' . $id),
      'base: is an unrouted URL upstream too.'
    );
  }

  /**
   * The read path today answers through upstream, and this pins it doing so.
   *
   * `getLinkitUrl()` is the only place in `neo` that calls upstream
   * `LinkitHelper` directly, and `/entity:node/N` is the input that makes the
   * choice visible: upstream resolves it and the fork does not, all the way
   * up. So a link item storing that uri renders the node's canonical URL
   * today — through Linkit's copy, not Neo's.
   *
   * This assertion is written to be replaced. When ticket 03 switches the read
   * path onto the fork, the same item answers NULL, and this is the pin that
   * has to be flipped to say so. Nothing else in this suite changes, which is
   * the claim that ticket is making.
   *
   * Covers: it pins the fork and upstream answers for the two inputs on which
   * they differ, each named in the test as such.
   */
  public function testTheReadPathResolvesThroughUpstreamToday(): void {
    $this->createLinkField();
    $node = $this->createPage();
    $id = $node->id();
    $seam = new TestNeoLinkitFormatterConsumer();

    // An `entity:` uri is the everyday case and both copies agree on it, so it
    // is the control for the two below it.
    $url = $seam->linkitUrl($this->linkItem('entity:node/' . $id));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('/node/' . $id, $url->toString());

    // UPSTREAM's answer, reached through the read path: `/entity:node/N`
    // resolves. The fork would answer NULL here — see the criterion above —
    // so this is the assertion ticket 03 replaces.
    $url = $seam->linkitUrl($this->linkItem('/entity:node/' . $id));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('/node/' . $id, $url->toString());

    // `internal:` is the difference the router fallback hides, so the read
    // path answers the same either way. Pinned so that ticket 03 can show it
    // did not move.
    $url = $seam->linkitUrl($this->linkItem('internal:/node/' . $id));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('/node/' . $id, $url->toString());

    // `base:` is refused by both copies, so it is NULL before and after.
    $this->assertNull($seam->linkitUrl($this->linkItem('base:node/' . $id)));
  }

}
