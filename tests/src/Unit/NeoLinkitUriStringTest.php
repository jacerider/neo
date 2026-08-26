<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\neo\Fixtures\TestNeoLinkitConsumer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Characterises the pure string work at the front of the Linkit seam.
 *
 * `NeoLinkitTrait::getLinkitUriFromUserInput()` is the link write path: what
 * an editor typed goes in, the uri that gets stored on the link field comes
 * out. Most of it needs a database, a router and an alias manager, and that
 * half lives in `NeoLinkitUserInputTest`. This file covers only the inputs
 * that never reach any of those, because they are answered and returned
 * before the entity lookup begins:
 *
 * - The three **special route tokens**, which return on the second line.
 * - A `mailto:` uri, which returns as soon as its scheme is read.
 * - A genuinely external URL, which returns once it has been established that
 *   it does not point back at this site. That one branch reads
 *   `\Drupal::request()`, so the test supplies a container holding nothing but
 *   a request stack — the whole environment these criteria need.
 *
 * `getLinkitQueryAndFragment()` — the **uri tail** — is pure in every branch
 * and is here for that reason. It is the piece two of this seam's five
 * production fixes were about: the substituted URL drops whatever the stored
 * uri carried after its path, and re-applying it is what those fixes added.
 *
 * The trait is reached through `TestNeoLinkitConsumer` rather than through
 * `NeoLinkWidget`, because the widget is a field plugin and the seam is what
 * is under test. Both methods are `public static`, and PHP will not let a
 * static method be called on a trait name, so a concrete `use`r is required.
 *
 * Two pins here are quirks rather than intentions, characterised as they are
 * today under the ticket's "characterise, do not repair" rule:
 *
 * 1. The guard at the top of `getLinkitUriFromUserInput()` is `empty()`, so
 *    the string `'0'` — a legitimate one-segment path — is answered `NULL`
 *    exactly as the empty string is.
 * 2. `getLinkitQueryAndFragment()` assigns inside `if ()`, so a query of `0`
 *    or a fragment of `0` is falsy and is dropped from the tail entirely.
 */
#[Group('neo')]
final class NeoLinkitUriStringTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   *
   * Only the truly-external branch touches the container, and all it wants is
   * `\Drupal::request()->getSchemeAndHttpHost()` to compare an input's host
   * against. A request stack carrying one request is therefore the entire
   * environment; nothing here has a database, a router or an entity type
   * manager, and nothing here needs one.
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $stack = new RequestStack();
    $stack->push(Request::create('https://neo.example.com/'));
    $container->set('request_stack', $stack);
    \Drupal::setContainer($container);
  }

  /**
   * Route tokens, a mailto and an external URL are answered without a lookup.
   *
   * These are the rows of the input-to-stored-uri table that return early.
   *
   * The **special route tokens** are the "links to nothing" choice. They have
   * to be stored as `route:<nolink>` rather than as a path, because an editor
   * typing `<nolink>` into a url field means the route, and letting the string
   * fall through to the internal-path branch would URL-encode the angle
   * brackets into `/%3Cnolink%3E` — a 404 that looks like a typo.
   *
   * `mailto:` returns the moment its scheme is read, before the host check,
   * the slash-prefixing and the entity lookup. That is what keeps an address
   * an address rather than a path.
   *
   * A genuinely external URL returns from the `else` half of the host check.
   * The check is not "does this have a host" but "does this host resolve back
   * to this site" — the same input pointed at this site's own host is made
   * relative instead, which is the criterion the kernel half pins. Here the
   * request is `https://neo.example.com`, so `https://example.com/...` is
   * really external and comes back byte-for-byte, query and fragment included.
   *
   * Covers: it turns each special route token into its route uri and leaves a
   * mailto and a genuinely external URL untouched.
   */
  public function testSpecialRouteTokensMailtoAndAnExternalUrlPassStraightThrough(): void {
    // The three special route tokens gain the `route:` scheme and nothing
    // else. The token itself is preserved verbatim, brackets and all.
    $this->assertSame('route:<nolink>', TestNeoLinkitConsumer::getLinkitUriFromUserInput('<nolink>'));
    $this->assertSame('route:<none>', TestNeoLinkitConsumer::getLinkitUriFromUserInput('<none>'));
    $this->assertSame('route:<button>', TestNeoLinkitConsumer::getLinkitUriFromUserInput('<button>'));

    // A mailto passes through untouched, address and all.
    $this->assertSame(
      'mailto:editor@example.com',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('mailto:editor@example.com')
    );

    // A genuinely external URL stays exactly as it is.
    $this->assertSame(
      'https://example.com/pricing',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('https://example.com/pricing')
    );
    $this->assertSame(
      'https://example.com/pricing?plan=pro#faq',
      TestNeoLinkitConsumer::getLinkitUriFromUserInput('https://example.com/pricing?plan=pro#faq')
    );

    // Nothing in becomes NULL out — and so does the string '0', because the
    // guard is `empty()` rather than a comparison against the empty string.
    // Pinned as a quirk, not as an intention.
    $this->assertNull(TestNeoLinkitConsumer::getLinkitUriFromUserInput(''));
    $this->assertNull(TestNeoLinkitConsumer::getLinkitUriFromUserInput(NULL));
    $this->assertNull(TestNeoLinkitConsumer::getLinkitUriFromUserInput('0'));
  }

  /**
   * The uri tail is the query, the fragment, both, or nothing at all.
   *
   * `getLinkitQueryAndFragment()` is what re-attaches everything after the
   * path when a uri is rewritten to point at an entity. Without it,
   * `/projects?market=1` typed by an editor would be stored as a bare
   * `entity:node/23` and the parameter would be gone before the page ever
   * rendered. The read path re-applies the same two pieces on the way out,
   * and those are two of the five production fixes this seam has taken.
   *
   * The order is fixed — query first, then fragment — which is what makes the
   * result concatenable onto an entity uri.
   *
   * The last two assertions pin a quirk. Both halves assign inside `if ()`,
   * so a query string of `0` or a fragment of `0` is falsy and silently
   * dropped. `?0` is a real shape (a bare flag parameter) and `#0` is a real
   * anchor id. Characterised as it stands rather than repaired.
   *
   * Covers: it extracts the uri tail as query only, fragment only, both, and
   * empty.
   */
  public function testUriTailIsQueryOnlyFragmentOnlyBothOrNeither(): void {
    // Query only.
    $this->assertSame(
      '?market=1',
      TestNeoLinkitConsumer::getLinkitQueryAndFragment('/projects?market=1')
    );
    // Fragment only.
    $this->assertSame(
      '#leadership',
      TestNeoLinkitConsumer::getLinkitQueryAndFragment('/about#leadership')
    );
    // Both, in that order.
    $this->assertSame(
      '?market=1&sort=name#results',
      TestNeoLinkitConsumer::getLinkitQueryAndFragment('/projects?market=1&sort=name#results')
    );
    // Neither.
    $this->assertSame('', TestNeoLinkitConsumer::getLinkitQueryAndFragment('/projects'));
    // A full uri is treated no differently from a path.
    $this->assertSame(
      '?market=1#results',
      TestNeoLinkitConsumer::getLinkitQueryAndFragment('https://example.com/projects?market=1#results')
    );
    $this->assertSame(
      '?market=1',
      TestNeoLinkitConsumer::getLinkitQueryAndFragment('entity:node/23?market=1')
    );

    // The quirk: a falsy query and a falsy fragment are dropped.
    $this->assertSame('', TestNeoLinkitConsumer::getLinkitQueryAndFragment('/projects?0'));
    $this->assertSame('', TestNeoLinkitConsumer::getLinkitQueryAndFragment('/about#0'));
  }

}
