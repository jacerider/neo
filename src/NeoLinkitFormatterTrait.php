<?php

namespace Drupal\neo;

use Drupal\link\LinkItemInterface;
use Drupal\neo\Linkit\NeoLinkitResolver;

/**
 * Provides helper to operate on URIs.
 */
trait NeoLinkitFormatterTrait {

  /**
   * Optionally-injected Linkit resolver. Falls back to the container.
   */
  protected ?NeoLinkitResolver $linkitResolver = NULL;

  /**
   * Returns the Linkit resolver.
   *
   * @return \Drupal\neo\Linkit\NeoLinkitResolver
   *   The Linkit resolver.
   */
  protected function linkitResolver(): NeoLinkitResolver {
    if (isset($this->linkitResolver)) {
      return $this->linkitResolver;
    }
    if (\Drupal::hasService('neo.linkit_resolver')) {
      return \Drupal::service('neo.linkit_resolver');
    }
    // A container that has not registered `neo`'s services file still gets a
    // working seam, assembled here out of whatever that container does hold.
    // This is where the `\Drupal::` fallback these methods used to make lives
    // now; the resolver itself never reaches the container.
    return NeoLinkitResolver::fromContainer(\Drupal::getContainer());
  }

  /**
   * Checks if the Linkit module exists.
   *
   * This method uses the Drupal service container to get the module handler
   * and checks if the 'linkit' module is enabled.
   *
   * @return bool
   *   TRUE if the Linkit module exists, FALSE otherwise.
   */
  public function linkitModuleExists(): bool {
    return $this->linkitResolver()->moduleExists();
  }

  /**
   * Get all Linkit profiles.
   *
   * @return \Drupal\linkit\ProfileInterface[]
   *   An array of Linkit profiles.
   */
  public function getLinkitProfiles() {
    return $this->linkitResolver()->getProfiles();
  }

  /**
   * Get all Linkit profiles as options.
   *
   * @return array
   *   An array of Linkit profiles as options.
   */
  public function getLinkitProfilesAsOptions() {
    $profiles = $this->getLinkitProfiles();
    $options = [];
    foreach ($profiles as $profile) {
      $options[$profile->id()] = $profile->label();
    }
    return $options;
  }

  /**
   * Returns a substitution URL for the given linked item.
   *
   * In case the items links to an entity use a substituted/generated URL.
   *
   * @param \Drupal\link\LinkItemInterface $item
   *   The link item.
   * @param string $profileId
   *   The Linkit profile ID.
   *
   * @return \Drupal\Core\Url|\Drupal\Core\GeneratedUrl|null
   *   The substitution URL, or NULL if not able to retrieve it from the item.
   */
  protected function getLinkitUrl(LinkItemInterface $item, $profileId = 'default') {
    return $this->linkitResolver()->getSubstitutedUrl($item, $profileId);
  }

}
