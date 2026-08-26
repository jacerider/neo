<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Url;
use Drupal\linkit\Entity\Profile;
use Drupal\Tests\neo\Fixtures\TestNeoLinkitFormatterConsumer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the entity guard in the substitution step.
 *
 * The substitution step has two ways of finding the entity it substitutes.
 * The one every other test in this suite exercises is the stored uri. The one
 * covered here is the pair of Linkit attributes an editor's selection leaves
 * on the item — `data-entity-type` and `data-entity-uuid` — which the step
 * prefers, because they are the only thing that resolves a File entity
 * reliably.
 *
 * That branch loads the entity by uuid and then guards the result:
 *
 * @code
 * $entity = $this->entityRepository->loadEntityByUuid($type, $uuid);
 * if ($entity instanceof EntityInterface) {
 *   $entity = $this->entityRepository->getTranslationFromContext($entity);
 * }
 * @endcode
 *
 * Static analysis reported that guard as `instanceof.alwaysFalse` — an
 * `instanceof` between `*NEVER*` and `EntityInterface` — for as long as this
 * seam has been analysed, and the two criteria below are what settle it. The
 * `*NEVER*` was never a statement about the code: `phpstan-drupal`'s
 * entity-repository extension unions the entity classes named by *constant
 * strings* in the first argument and collapses to `never` when there are none,
 * and `$item->options['data-entity-type']` is a stored attribute rather than a
 * literal. Ticket 04 restated the documented return type and the finding went
 * away; this file proves the guard live from the other side, by reaching both
 * of its outcomes.
 *
 * The trait is reached through `TestNeoLinkitFormatterConsumer`, for the same
 * reason `NeoLinkitFormatterUrlTest` uses it: `getLinkitUrl()` is protected and
 * `NeoLinkFormatter` is a field plugin that would need the whole formatter
 * machinery to construct.
 */
#[Group('neo')]
final class NeoLinkitEntityAttributesTest extends NeoLinkitKernelTestBase {

  /**
   * The trait's consumer.
   */
  protected TestNeoLinkitFormatterConsumer $seam;

  /**
   * A uuid of the right shape that nothing has been saved under.
   *
   * It has to be well-formed rather than obviously junk: the point of the
   * second criterion is that a *lookup* misses, not that a malformed value is
   * rejected somewhere earlier.
   */
  protected const MISSING_UUID = '2f9f6b1e-6c4b-4a0f-9f8c-1a2b3c4d5e6f';

  /**
   * {@inheritdoc}
   *
   * The `default` Linkit profile is Linkit's own optional config, installed
   * because `node` is enabled, and it already carries an `entity:node` matcher
   * on the canonical substitution — so the substitution these criteria run
   * through is the one a real site has.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createLinkField();

    $default = Profile::load('default');
    $this->assertNotNull($default);
    $this->assertNotNull($default->getMatcherByEntityType('node'));

    $this->seam = new TestNeoLinkitFormatterConsumer();
  }

  /**
   * The Linkit attributes decide the entity, and a missed uuid answers NULL.
   *
   * Both criteria put a **decoy** in the stored uri: a second node that exists
   * and would resolve perfectly well if the uri were consulted. That is what
   * makes each outcome unambiguous — the attribute branch is taken, the uri is
   * not, and the answer says which entity the guard let through.
   *
   * The true side hands the loaded entity on to be substituted, so the URL is
   * the *attribute's* node rather than the decoy's. It also keeps a **uri
   * tail**: the tail blocks read the raw uri string a second time, after the
   * entity is already decided, so a query and a fragment survive even when the
   * entity did not come from that uri at all.
   *
   * The false side is a uuid nothing has been saved under. The load answers
   * NULL, the guard refuses it, and — because the `else` that reads the uri
   * belongs to the *outer* `if` and was not taken — nothing else ever looks at
   * the decoy. The result is NULL despite the uri naming a real node, which is
   * only true because the guard is where it is.
   *
   * Covers: it resolves the substitution entity from the Linkit attributes in
   * preference to the stored uri, and returns null when the uuid matches
   * nothing.
   */
  public function testLinkitAttributesDecideTheEntityOrAnswerNull(): void {
    $target = $this->createPage('Attribute target');
    $decoy = $this->createPage('Uri decoy');
    $this->assertNotSame($target->id(), $decoy->id());

    // The guard passes: the attributes name a real node, and it is that node
    // which is substituted, not the one the uri names.
    $url = $this->seam->linkitUrl($this->linkItem('entity:node/' . $decoy->id(), [
      'data-entity-type' => 'node',
      'data-entity-uuid' => $target->uuid(),
    ]));
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('entity.node.canonical', $url->getRouteName());
    $this->assertSame('/node/' . $target->id(), $url->toString());

    // The uri tail is still re-applied afterwards, from a uri that had no say
    // in which entity was chosen.
    $url = $this->seam->linkitUrl(
      $this->linkItem('entity:node/' . $decoy->id() . '?market=1#leadership', [
        'data-entity-type' => 'node',
        'data-entity-uuid' => $target->uuid(),
      ])
    );
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame(['market' => '1'], $url->getOption('query'));
    $this->assertSame('leadership', $url->getOption('fragment'));
    $this->assertSame(
      '/node/' . $target->id() . '?market=1#leadership',
      $url->toString()
    );

    // The guard refuses: the uuid resolves to nothing, so there is no entity
    // to translate and no entity to substitute. The decoy in the uri is never
    // consulted, so the answer is NULL rather than the decoy's URL.
    $this->assertNull(
      $this->seam->linkitUrl($this->linkItem('entity:node/' . $decoy->id(), [
        'data-entity-type' => 'node',
        'data-entity-uuid' => self::MISSING_UUID,
      ]))
    );
  }

}
