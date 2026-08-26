<?php

namespace Drupal\neo;

use Drupal\linkit\ProfileInterface;
use Drupal\neo\Linkit\NeoLinkitResolver;

/**
 * Provides helper to operate on URIs.
 */
trait NeoLinkitTrait {

  /**
   * Optionally-injected Linkit resolver. Falls back to the container.
   */
  protected ?NeoLinkitResolver $linkitResolver = NULL;

  /**
   * Returns the Linkit resolver for a static call site.
   *
   * The URI helpers below are `public static` because `NeoLinkWidget` calls
   * them as `self::`, so they cannot read an injected property. The container
   * is the only seam available to them.
   *
   * @return \Drupal\neo\Linkit\NeoLinkitResolver
   *   The Linkit resolver.
   */
  protected static function staticLinkitResolver(): NeoLinkitResolver {
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
   * Returns the Linkit resolver.
   *
   * @return \Drupal\neo\Linkit\NeoLinkitResolver
   *   The Linkit resolver.
   */
  protected function linkitResolver(): NeoLinkitResolver {
    if (isset($this->linkitResolver)) {
      return $this->linkitResolver;
    }
    return static::staticLinkitResolver();
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
   * Get a Linkit element.
   *
   * @param string|null $default_value
   *   The default value for the element.
   * @param string $linkit_profile_id
   *   The ID of the Linkit profile.
   *
   * @return array<string, mixed>
   *   A Linkit element.
   */
  public function getLinkitElement($default_value = NULL, $linkit_profile_id = 'default') {
    return [
      '#type' => 'linkit',
      '#title' => $this->t('Link URL'),
      '#autocomplete_route_name' => 'linkit.autocomplete',
      '#autocomplete_route_parameters' => [
        'linkit_profile_id' => $linkit_profile_id,
      ],
      '#default_value' => $default_value,
    ];
  }

  /**
   * Get all Linkit profiles as options.
   *
   * @return array<int|string, string|\Drupal\Core\StringTranslation\TranslatableMarkup|null>
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
   * Get a Linkit profile by its ID.
   *
   * @param string $profile_id
   *   The ID of the Linkit profile.
   *
   * @return \Drupal\linkit\ProfileInterface|null
   *   The Linkit profile, or NULL if not found.
   */
  public function getLinkitProfile($profile_id): ?ProfileInterface {
    return $this->linkitResolver()->getProfile($profile_id);
  }

  /**
   * Load the entity referenced by an entity scheme uri.
   *
   * @param string $uri
   *   An internal uri string representing an entity path, such as
   *   "entity:node/23".
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The most appropriate translation of the entity that matches the given
   *   uri, or NULL if could not match any entity.
   */
  public static function getLinkitEntityFromUri($uri) {
    return static::staticLinkitResolver()->getEntityFromUri($uri);
  }

  /**
   * Returns a processed uri with a proper scheme (if applicable).
   *
   * Turns the internal links into uri strings.
   *
   * @param string $input
   *   The raw (or processed) input.
   *
   * @return string|null
   *   The uri string or null if the input is empty.
   */
  public static function getLinkitUriFromUserInput($input) {
    return static::staticLinkitResolver()->getUriFromUserInput($input);
  }

  /**
   * Tries to convert an uri into an entity in multiple ways.
   *
   * @param string $input
   *   A uri or a path.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity if found, null otherwise.
   */
  public static function getLinkitEntityFromUserInput($input) {
    return static::staticLinkitResolver()->getEntityFromUserInput($input);
  }

  /**
   * Returns the query and fragment part of a given URL string.
   *
   * @param string $input
   *   An arbitrary URL.
   *
   * @return string
   *   The query and fragment parts or an empty string.
   */
  public static function getLinkitQueryAndFragment($input) {
    return static::staticLinkitResolver()->getQueryAndFragment($input);
  }

  /**
   * Tries to translate the given raw url path into an internal one.
   *
   * @param string $input
   *   Raw URL string consisting of a path and, optionally, query and fragment.
   *
   * @return string
   *   The internal path if any matched. The input string otherwise.
   */
  public static function getLinkitPathByAlias($input) {
    return static::staticLinkitResolver()->getPathByAlias($input);
  }

}
