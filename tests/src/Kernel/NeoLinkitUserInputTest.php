<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Tests\neo\Fixtures\TestNeoLinkitConsumer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the rest of the link write path's input-to-uri walk.
 *
 * `NeoLinkitUriStringTest` covers the rows of the table that return before the
 * seam reaches the container. These are the rows that do not: every one of
 * them falls through the string massaging into the entity lookup, which asks
 * the entity type manager, the alias manager and the router in turn,
 * and then — if none of them answered — through the public stream wrapper and
 * out as an `internal:` uri.
 *
 * The three shapes the massaging produces are all pinned here:
 *
 * - **An external URL that points back at this site** is made relative before
 *   the lookup, which is the whole reason the branch exists: an editor who
 *   pastes the full address of a page on their own site should get the same
 *   stored uri as one who typed the path. With a node behind the path that is
 *   an `entity:` uri; with nothing behind it, an `internal:` one.
 * - **A bare path** gains a leading slash, because `Url`'s factory methods
 *   throw on a relative one.
 * - **`<front>`** becomes `/`, and `<front>#foo` becomes `/#foo` — the token is
 *   replaced, the **uri tail** after it is not.
 *
 * One more row from the table lands here rather than in the unit half:
 * an input that already carries a scheme but no host — `tel:` is the everyday
 * one — reaches the very last branch of the method and is returned unchanged,
 * because by then the entity lookup has already failed and the scheme is read
 * a second time.
 *
 * The request's host matters to the first of those and is asserted rather than
 * assumed, since "is this URL local" is decided against
 * `\Drupal::request()->getSchemeAndHttpHost()` and a kernel test's request is
 * synthesised rather than real.
 */
#[Group('neo')]
final class NeoLinkitUserInputTest extends NeoLinkitKernelTestBase {

  /**
   * A local external URL, a bare path and the front token become stored uris.
   *
   * Four things are being pinned at once, and they are four different exits
   * from the same method.
   *
   * The local-looking external URL is asserted twice on purpose. Pointed at a
   * path with a node behind it, it comes back as an `entity:` uri — which is
   * only possible if the host really was stripped before the lookup, because
   * `getLinkitEntityFromUri()` would never have matched the full URL. Pointed
   * at a path with nothing behind it, it comes back as `internal:` with the
   * host gone, which is the same evidence without the entity.
   *
   * The bare path is the same input as the second of those with the scheme and
   * host removed, and answers identically. That is the point: the two spellings
   * an editor might use converge on one stored uri.
   *
   * `<front>` is the token core's own url field tells editors to type. It is
   * stored as `internal:/` rather than as a route, and its **uri tail**
   * survives the substitution — `<front>#leadership` keeps the fragment.
   *
   * Covers: it makes a local-looking external URL relative, gives a bare path a
   * leading slash, and turns front into a slash with its fragment intact.
   */
  public function testLocalExternalUrlBarePathAndFrontTokenBecomeStoredUris(): void {
    // What "local" is measured against here. Pinned, because the branch under
    // test is a comparison against this exact string.
    $this->assertSame('http://localhost', \Drupal::request()->getSchemeAndHttpHost());

    $node = $this->createPage();

    // An external URL that resolves to this site is made relative first, so
    // the entity lookup can see the path underneath it.
    $this->assertSame(
      'entity:node/' . $node->id(),
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('http://localhost/node/' . $node->id())
    );
    // With nothing behind the path, the host is still stripped — the result is
    // an internal uri rather than the external URL that went in.
    $this->assertSame(
      'internal:/about-us',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('http://localhost/about-us')
    );

    // A bare path gains its leading slash and lands on the same answer.
    $this->assertSame(
      'internal:/about-us',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('about-us')
    );
    // A path that already has its slash is not given a second one.
    $this->assertSame(
      'internal:/about-us',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('/about-us')
    );

    // The front token becomes a bare slash, and keeps whatever trails it.
    $this->assertSame('internal:/', TestNeoLinkitConsumer::getLinkitUriFromUserInput('<front>'));
    $this->assertSame(
      'internal:/#leadership',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('<front>#leadership')
    );

    // An input already carrying a scheme is left alone by the last branch.
    $this->assertSame(
      'tel:+15555550123',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('tel:+15555550123')
    );
  }

}
