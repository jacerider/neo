<?php

namespace Drupal\neo;

use Drupal\Core\Entity\EntityInterface;
use Drupal\link\LinkItemInterface;
use Drupal\linkit\ProfileInterface;
use Drupal\linkit\SubstitutionManagerInterface;
use Drupal\linkit\Utility\LinkitHelper;

/**
 * Provides helper to operate on URIs.
 */
trait NeoLinkitFormatterTrait {

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
    return \Drupal::service('module_handler')->moduleExists('linkit');
  }

  /**
   * Returns the entity repository service.
   *
   * @return \Drupal\Core\Entity\EntityRepositoryInterface
   *   The entity repository service.
   */
  protected function entityRepository() {
    if (isset($this->entityRepository)) {
      return $this->entityRepository;
    }
    return \Drupal::service('entity.repository');
  }

  /**
   * Returns the Linkit profile storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The Linkit profile storage.
   */
  protected function linkitProfileStorage() {
    if (isset($this->linkitProfileStorage)) {
      return $this->linkitProfileStorage;
    }
    return \Drupal::entityTypeManager()->getStorage('linkit_profile');
  }

  /**
   * Returns the substitution manager.
   *
   * @return \Drupal\linkit\SubstitutionManagerInterface
   *   The substitution manager.
   */
  protected function substitutionManager() {
    if (isset($this->substitutionManager)) {
      return $this->substitutionManager;
    }
    return \Drupal::service('plugin.manager.linkit.substitution');
  }

  /**
   * Get all Linkit profiles.
   *
   * @return \Drupal\linkit\ProfileInterface[]
   *   An array of Linkit profiles.
   */
  public function getLinkitProfiles() {
    return $this->linkitProfileStorage()->loadMultiple();
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
    // First try to derive entity information from Linkit-specific attributes.
    // This is more reliable and is required for File entities.
    if (!empty($item->options['data-entity-type']) && !empty($item->options['data-entity-uuid'])) {
      $entity = $this->entityRepository()->loadEntityByUuid($item->options['data-entity-type'], $item->options['data-entity-uuid']);
      if ($entity instanceof EntityInterface) {
        $entity = $this->entityRepository()->getTranslationFromContext($entity);
      }
    }
    else {
      $entity = LinkitHelper::getEntityFromUserInput($item->uri);
    }
    if ($entity instanceof EntityInterface) {
      $linkit_profile = $this->linkitProfileStorage()->load($profileId);

      if (!$linkit_profile instanceof ProfileInterface) {
        return NULL;
      }

      /** @var \Drupal\linkit\Plugin\Linkit\Matcher\EntityMatcher $matcher */
      $matcher = $linkit_profile->getMatcherByEntityType($entity->getEntityTypeId());
      $substitution_type = $matcher ? $matcher->getConfiguration()['settings']['substitution_type'] : SubstitutionManagerInterface::DEFAULT_SUBSTITUTION;
      return $this->substitutionManager()->createInstance($substitution_type)->getUrl($entity);
    }
    return NULL;
  }

}
