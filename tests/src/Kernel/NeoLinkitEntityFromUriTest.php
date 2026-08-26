<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Tests\neo\Fixtures\TestNeoLinkitConsumer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the fork's entity-from-stored-uri step.
 *
 * `NeoLinkitTrait::getLinkitEntityFromUri()` is the method-for-method fork of
 * Linkit's `LinkitHelper::getEntityFromUri()`, and it is where the fork's own
 * fixes live. Everything it does is decided by string shape before a single
 * entity is loaded, so the criteria here are about which strings it agrees to
 * read as "type/id" and which it refuses:
 *
 * - `entity:node/23`, the canonical stored form.
 * - `internal:/node/23` and `base:node/23`, which upstream does not strip and
 *   the fork does. That divergence is pinned on its own in
 *   `NeoLinkitForkDivergenceTest`; here they are simply two more spellings
 *   that resolve.
 * - A uri carrying a **uri tail**, which is cut off before anything else
 *   happens, so `entity:node/23?a=1#b` resolves the same node as the bare uri.
 * - An external URL, whose first path segment keeps the scheme's colon glued
 *   to it — `https:` — and which is refused for that reason. The guard is not
 *   cosmetic: a string containing the plugin derivative separator makes the
 *   entity type manager throw a `LogicException` that `hasDefinition()` never
 *   gets the chance to suppress, so without the check this would be a fatal
 *   rather than a `NULL`.
 * - An entity type that does not exist, and an id that does not.
 *
 * The method is `public static` on the trait and is reached through
 * `TestNeoLinkitConsumer`.
 */
#[Group('neo')]
final class NeoLinkitEntityFromUriTest extends NeoLinkitKernelTestBase {

  /**
   * A stored uri resolves an entity through three schemes, or answers NULL.
   *
   * The three schemes are not three code paths — they are one list the method
   * checks the parsed scheme against, and anything on it is stripped so the
   * remainder is a plain `type/id`. That is why `internal:/node/23` and
   * `base:node/23` answer the same node as `entity:node/23`: after the strip
   * and the `trim('/')` all three are the string `node/23`.
   *
   * The four `NULL`s are four different refusals, and they matter separately.
   * An external URL is refused by the derivative-separator guard, before the
   * entity type manager is asked anything. An unknown entity type is refused
   * by `hasDefinition()`. A missing id is refused by the storage returning
   * nothing. And a uri with no `/` in it at all never reaches the check,
   * because it does not split into two parts.
   *
   * Covers: it resolves an entity from an entity, internal and base uri and
   * returns null for an unknown type, a missing id and an external URL.
   */
  public function testStoredUriResolvesAnEntityOrAnswersNull(): void {
    $node = $this->createPage();
    $id = $node->id();

    // The three on-site schemes all reduce to the same "type/id" string.
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:node/' . $id)?->id()
    );
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('internal:/node/' . $id)?->id()
    );
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('base:node/' . $id)?->id()
    );
    // A bare path with no scheme at all is the fourth spelling that resolves.
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('/node/' . $id)?->id()
    );

    // The uri tail is cut off before anything else is decided.
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:node/' . $id . '?market=1')?->id()
    );
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:node/' . $id . '#leadership')?->id()
    );
    $this->assertSame(
      $id,
      TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:node/' . $id . '?market=1#leadership')?->id()
    );

    // An external URL: the first segment is `https:` once the string is split
    // on `/`, which the derivative-separator guard refuses outright.
    $this->assertNull(
      TestNeoLinkitConsumer::getLinkitEntityFromUri('https://example.com/node/' . $id)
    );

    // An entity type nothing has defined.
    $this->assertNull(TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:no_such_type/1'));

    // A real entity type and an id nothing has been saved under.
    $this->assertNull(TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:node/999999'));

    // A uri that never splits into two parts.
    $this->assertNull(TestNeoLinkitConsumer::getLinkitEntityFromUri('entity:node'));
    $this->assertNull(TestNeoLinkitConsumer::getLinkitEntityFromUri('route:<nolink>'));
    $this->assertNull(TestNeoLinkitConsumer::getLinkitEntityFromUri(''));
  }

}
