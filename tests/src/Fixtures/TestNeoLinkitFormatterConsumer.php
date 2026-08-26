<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Fixtures;

use Drupal\link\LinkItemInterface;
use Drupal\neo\NeoLinkitFormatterTrait;

/**
 * A concrete consumer of the link read path's half of the Linkit seam.
 *
 * `NeoLinkitFormatterTrait` is reached in production through
 * `NeoLinkFormatter` and through `neo_alchemist`'s `UrlShapeTrait`. Its one
 * interesting member, `getLinkitUrl()`, is `protected`, so this class forwards
 * to it.
 *
 * The trait cannot be combined with `NeoLinkitTrait` in one class — they both
 * declare `linkitModuleExists()`, `getLinkitProfiles()` and
 * `getLinkitProfilesAsOptions()` — which is itself the shape of the problem
 * this plan is about, and the reason there are two consumer classes here
 * rather than one.
 */
final class TestNeoLinkitFormatterConsumer {

  use NeoLinkitFormatterTrait;

  /**
   * Forwards to the trait's protected substitution step.
   *
   * @param \Drupal\link\LinkItemInterface $item
   *   The link item to resolve.
   * @param string $profileId
   *   The Linkit profile ID.
   *
   * @return \Drupal\Core\Url|\Drupal\Core\GeneratedUrl|null
   *   Whatever the trait answered.
   */
  public function linkitUrl(LinkItemInterface $item, $profileId = 'default') {
    return $this->getLinkitUrl($item, $profileId);
  }

}
