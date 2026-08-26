<?php

namespace Drupal\neo\Linkit;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\link\LinkItemInterface;
use Drupal\linkit\ProfileInterface;
use Drupal\linkit\SubstitutionManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Linkit seam: every URI to entity step the link traits are built on.
 *
 * `NeoLinkitTrait` (the link write path) and `NeoLinkitFormatterTrait` (the
 * link read path) both delegate here, so a fix to URI resolution lands in one
 * place instead of two. Every public method on both traits keeps the name,
 * signature and static-ness it has always had; what moved is what is behind
 * them.
 *
 * @see \Drupal\neo\NeoLinkitTrait
 * @see \Drupal\neo\NeoLinkitFormatterTrait
 * @see docs/adr/0007-the-linkit-seam-becomes-neos-first-service.md
 */
final class NeoLinkitResolver {

  /**
   * Constructs a NeoLinkitResolver.
   *
   * Every dependency arrives through this constructor; nothing here reaches
   * the container. The `neo.linkit_resolver` service passes all nine. They are
   * nullable, and only nullable, because ::fromContainer() has to be able to
   * assemble the seam out of a container that holds a subset of them — the
   * same containers in which the trait bodies these came from would have
   * thrown a service-not-found had the missing one ever been reached.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface|null $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\path_alias\AliasManagerInterface|null $pathAliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface|null $languageManager
   *   The language manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $configFactory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $requestStack
   *   The request stack.
   * @param \Drupal\linkit\SubstitutionManagerInterface|null $substitutionManager
   *   The Linkit substitution manager.
   */
  public function __construct(
    protected ?EntityTypeManagerInterface $entityTypeManager,
    protected ?EntityRepositoryInterface $entityRepository,
    protected ?ModuleHandlerInterface $moduleHandler,
    protected ?StreamWrapperManagerInterface $streamWrapperManager,
    protected ?AliasManagerInterface $pathAliasManager,
    protected ?LanguageManagerInterface $languageManager,
    protected ?ConfigFactoryInterface $configFactory,
    protected ?RequestStack $requestStack,
    protected ?SubstitutionManagerInterface $substitutionManager,
  ) {}

  /**
   * Assembles a resolver from a container that has no `neo.linkit_resolver`.
   *
   * This is the fallback both link traits use when `neo`'s services file has
   * not been loaded — a test container, or a container built before the
   * module was installed. Whatever the container holds is injected; whatever
   * it does not becomes NULL, so a caller only reaches a missing dependency
   * on a code path that actually needs it.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to assemble the resolver from.
   *
   * @return self
   *   The resolver.
   */
  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      self::optionalService($container, 'entity_type.manager'),
      self::optionalService($container, 'entity.repository'),
      self::optionalService($container, 'module_handler'),
      self::optionalService($container, 'stream_wrapper_manager'),
      self::optionalService($container, 'path_alias.manager'),
      self::optionalService($container, 'language_manager'),
      self::optionalService($container, 'config.factory'),
      self::optionalService($container, 'request_stack'),
      self::optionalService($container, 'plugin.manager.linkit.substitution'),
    );
  }

  /**
   * Returns a service if the container has it, NULL otherwise.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to read from.
   * @param string $id
   *   The service ID.
   *
   * @return object|null
   *   The service, or NULL if the container does not have it.
   */
  private static function optionalService(ContainerInterface $container, string $id): ?object {
    return $container->has($id) ? $container->get($id) : NULL;
  }

  /**
   * Returns the Linkit profile storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The Linkit profile storage.
   */
  protected function linkitProfileStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('linkit_profile');
  }

  /**
   * Checks if the Linkit module exists.
   *
   * @return bool
   *   TRUE if the Linkit module exists, FALSE otherwise.
   */
  public function moduleExists(): bool {
    return $this->moduleHandler->moduleExists('linkit');
  }

  /**
   * Get all Linkit profiles.
   *
   * @return \Drupal\linkit\ProfileInterface[]
   *   An array of Linkit profiles.
   */
  public function getProfiles(): array {
    // The `linkit_profile` storage yields `Drupal\linkit\Entity\Profile`
    // objects, every one of which is a ProfileInterface. The storage cannot
    // say so — EntityStorageInterface is not parameterised by entity class —
    // so the narrower type is restated here rather than left disagreeing with
    // what this method declares.
    /** @var \Drupal\linkit\ProfileInterface[] $profiles */
    $profiles = $this->linkitProfileStorage()->loadMultiple();
    return $profiles;
  }

  /**
   * Get a Linkit profile by its ID.
   *
   * @param string|null $profile_id
   *   The ID of the Linkit profile.
   *
   * @return \Drupal\linkit\ProfileInterface|null
   *   The Linkit profile, or NULL if not found.
   */
  public function getProfile(?string $profile_id): ?ProfileInterface {
    // Same caveat as ::getProfiles(): the storage can only promise an entity.
    /** @var \Drupal\linkit\ProfileInterface|null $profile */
    $profile = $this->linkitProfileStorage()->load($profile_id);
    return $profile;
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
  public function getEntityFromUri(string $uri): ?EntityInterface {
    // Strip out potential query and fragment from the uri.
    $uri = strtok(strtok($uri, "?"), "#");
    // Strip a known on-site scheme so the remainder is a plain "type/id" path.
    // "entity:node/23", "internal:/node/23" and "base:node/23" all reference
    // local content. Any other scheme (external URLs, "mailto:", "route:…")
    // is left untouched and simply won't resolve to an entity below.
    $scheme = parse_url($uri, PHP_URL_SCHEME);
    if (in_array($scheme, ['entity', 'internal', 'base'], TRUE)) {
      $uri = substr($uri, strlen($scheme) + 1);
    }
    $uri = trim($uri, '/');

    if ($uri) {
      $parts = explode('/', $uri, 2);
      if (count($parts) === 2) {
        [$entity_type, $entity_id] = $parts;
        // External URLs ("https://example.com/…") keep the scheme's colon glued
        // to the first segment. A real entity type ID never contains the plugin
        // derivative separator (":"), and passing such an ID to the entity type
        // manager throws a LogicException before hasDefinition() can suppress
        // it, so bail out early.
        $entity_manager = $this->entityTypeManager;
        if (!str_contains($entity_type, ':') && $entity_manager->hasDefinition($entity_type)) {
          if ($entity = $entity_manager->getStorage($entity_type)->load($entity_id)) {
            return $this->entityRepository->getTranslationFromContext($entity);
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Returns a processed uri with a proper scheme (if applicable).
   *
   * Turns the internal links into uri strings.
   *
   * @param string|null $input
   *   The raw (or processed) input. NULL is a real caller input — the guard on
   *   the first line answers it — so the parameter is nullable.
   *
   * @return string|null
   *   The uri string or null if the input is empty.
   */
  public function getUriFromUserInput(?string $input): ?string {
    if (empty($input)) {
      return NULL;
    }

    // Support linking to nothing. These special routes must be stored as
    // 'route:<nolink>' (etc.) and must not be treated as internal paths, which
    // would URL-encode the angle brackets into e.g. '/%3Cnolink%3E'.
    // @see \Drupal\link\Plugin\Field\FieldWidget\LinkWidget::getUserEnteredStringAsUri()
    if (in_array($input, ['<nolink>', '<none>', '<button>'], TRUE)) {
      return 'route:' . $input;
    }

    $host = parse_url($input, PHP_URL_HOST);
    $scheme = parse_url($input, PHP_URL_SCHEME);

    if ($scheme == 'mailto') {
      return $input;
    }

    if ($host && UrlHelper::isExternal($input)) {
      if (UrlHelper::externalIsLocal($input, $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost())) {
        // The link points to this domain. Make it relative to perform an entity
        // lookup.
        $host_end = strpos($input, $host) + strlen($host);
        $input = substr($input, $host_end);
      }
      else {
        // This link is really external.
        return $input;
      }
    }

    // Make sure the URI starts with a slash, otherwise the Url's factory
    // methods will throw exceptions.
    $starts_with_hash = strpos($input, '#') === 0;
    $starts_with_a_slash = strpos($input, '/') === 0;
    $is_front = substr($input, 0, 7) === '<front>';
    $is_nolink = substr($input, 0, 14) === 'route:<nolink>';
    if (!$scheme && !$is_front && !$is_nolink && !$starts_with_a_slash && !$starts_with_hash) {
      $input = "/$input";
    }
    // - '<front>' -> '/'
    // - '<front>#foo' -> '/#foo'
    if ($is_front) {
      $input = '/' . substr($input, strlen('<front>'));
    }

    $entity = $this->getEntityFromUserInput($input);
    if ($entity) {
      return 'entity:' . $entity->getEntityTypeId() . '/' . $entity->id() . $this->getQueryAndFragment($input);
    }

    // It's a relative link. If it's a file, store it as `base:`. Otherwise it's
    // most likely internal.
    // A site with no public files scheme has no public stream wrapper, and
    // getViaScheme() answers FALSE rather than one. getDirectoryPath() is not
    // on StreamWrapperInterface either — it arrives with LocalStream, and a
    // remote public wrapper may implement it without extending that — so the
    // guard asks whether this wrapper can answer rather than what it is.
    // Either way the result is "no public directory path", which is exactly
    // what the empty() check below already intends to do with one: the `base:`
    // branch is skipped.
    $public_wrapper = $this->streamWrapperManager->getViaScheme('public');
    $public_files_dir = $public_wrapper && method_exists($public_wrapper, 'getDirectoryPath')
      ? $public_wrapper->getDirectoryPath()
      : '';

    if (!empty($public_files_dir) && strpos($input, "/$public_files_dir") === 0) {
      return "base:$input";
    }
    $scheme = parse_url($input, PHP_URL_SCHEME);
    // Check if the input already contains a scheme.
    if (!empty($scheme)) {
      return $input;
    }

    return "internal:$input";
  }

  /**
   * Tries to convert an uri into an entity in multiple ways.
   *
   * Both halves of the seam resolve here. The link read path used to call
   * Linkit's own utility helper instead — upstream's copy of this method,
   * without the fixes this module's fork has taken — so a fix had to be
   * applied twice to hold. It no longer does, and `neo` now owns this code
   * outright: a future Linkit release changing that helper has no effect here.
   *
   * @param string $input
   *   A uri or a path.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity if found, null otherwise.
   */
  public function getEntityFromUserInput(string $input): ?EntityInterface {
    $scheme = parse_url($input, PHP_URL_SCHEME);

    // Check if it's an entity URI (e.g. entity:node/1).
    if (($scheme === 'entity' || !$scheme) && ($entity = $this->getEntityFromUri($input))) {
      return $entity;
    }

    // If not, it can be a path pointing to an entity.
    if (!$scheme) {
      // Which can be hidden behind an alias in any of the site's languages.
      $input = 'internal:' . $this->getPathByAlias($input);
    }

    try {
      $route_name = Url::fromUri($input)->getRouteName();
      $params = array_filter(Url::fromUri($input)->getRouteParameters());
      foreach ($params as $possibly_an_entity_type => $possibly_an_entity_id) {
        // Return only the entity, if this is a canonical route.
        if ($route_name === 'entity.' . $possibly_an_entity_type . '.canonical') {
          $entity = $this->entityTypeManager
            ->getStorage($possibly_an_entity_type)
            ->load($possibly_an_entity_id);
          if (!($entity instanceof EntityInterface)) {
            return NULL;
          }
          return $this->entityRepository
            ->getTranslationFromContext($entity);
        }
      }
    }
    catch (\Exception $e) {
      // Or not.
    }

    return NULL;
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
  public function getQueryAndFragment(string $input): string {
    $result = '';
    if ($query = parse_url($input, PHP_URL_QUERY)) {
      $result .= "?$query";
    }
    if ($fragment = parse_url($input, PHP_URL_FRAGMENT)) {
      $result .= "#$fragment";
    }
    return $result;
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
  public function getPathByAlias(string $input): string {
    $config = $this->configFactory->get('language.negotiation');
    /** @var \Drupal\path_alias\AliasManagerInterface $path_alias_manager */
    $path_alias_manager = $this->pathAliasManager;
    /** @var \Drupal\Core\Language\LanguageManagerInterface $language_manager */
    $language_manager = $this->languageManager;

    $input_path = parse_url($input, PHP_URL_PATH);
    foreach ($language_manager->getLanguages() as $language) {
      if ($prefix = $config->get('url.prefixes.' . $language->getId())) {
        // Strip the language prefix.
        $input_path = preg_replace("/^\/$prefix\//", '/', $input_path);
      }
      $path_resolved = $path_alias_manager->getPathByAlias($input_path, $language->getId());
      if ($path_resolved !== $input_path) {
        return $path_resolved . $this->getQueryAndFragment($input);
      }
    }

    return $input;
  }

  /**
   * Returns a substitution URL for the given linked item.
   *
   * In case the items links to an entity use a substituted/generated URL.
   *
   * @param \Drupal\link\LinkItemInterface $item
   *   The link item.
   * @param string|null $profileId
   *   The Linkit profile ID.
   *
   * @return \Drupal\Core\Url|\Drupal\Core\GeneratedUrl|null
   *   The substitution URL, or NULL if not able to retrieve it from the item.
   */
  public function getSubstitutedUrl(LinkItemInterface $item, ?string $profileId = 'default') {
    // The stored uri, read through the item's own value accessor. `$item->uri`
    // is a magic typed-data property that analysis cannot see, which made the
    // is_string() guards below read as defensive against a type it believed
    // impossible; through the accessor the value is honestly untyped and the
    // guards mean what they were written to mean. It is the same read —
    // FieldItemBase::__get() answers get($name)->getValue() — taken once
    // because all three guards want the same value.
    $uri = $item->get('uri')->getValue();

    // First try to derive entity information from Linkit-specific attributes.
    // This is more reliable and is required for File entities.
    $entity = NULL;
    if (!empty($item->options['data-entity-type']) && !empty($item->options['data-entity-uuid'])) {
      // The entity type ID is a stored attribute rather than a literal, so
      // analysis cannot resolve this load to a concrete entity class and
      // collapses it to *NEVER*, which makes the guard below look dead.
      // Restate what EntityRepositoryInterface itself documents it returns.
      /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
      $entity = $this->entityRepository->loadEntityByUuid($item->options['data-entity-type'], $item->options['data-entity-uuid']);
      if ($entity instanceof EntityInterface) {
        $entity = $this->entityRepository->getTranslationFromContext($entity);
      }
    }
    else {
      if (is_string($uri)) {
        $entity = $this->getEntityFromUserInput($uri);
      }
    }
    if ($entity instanceof EntityInterface) {
      $linkit_profile = $this->linkitProfileStorage()->load($profileId);

      if (!$linkit_profile instanceof ProfileInterface) {
        return NULL;
      }

      // Linkit's own ProfileInterface documents this as answering NULL when
      // no matcher matches the entity type, and the fall-back to the default
      // substitution below is what handles that. The annotation used to claim
      // non-null, which is the only reason the ternary looked always-true.
      /** @var \Drupal\linkit\Plugin\Linkit\Matcher\EntityMatcher|null $matcher */
      $matcher = $linkit_profile->getMatcherByEntityType($entity->getEntityTypeId());
      $substitution_type = $matcher ? $matcher->getConfiguration()['settings']['substitution_type'] : SubstitutionManagerInterface::DEFAULT_SUBSTITUTION;
      $url = $this->substitutionManager->createInstance($substitution_type)->getUrl($entity);

      // The substituted entity URL drops any query string present on the
      // original uri (e.g. "internal:/projects?market=1"). Re-apply it so
      // deliberate query parameters survive Linkit substitution.
      if ($url && is_string($uri) && ($queryPos = strpos($uri, '?')) !== FALSE) {
        // A fragment may trail the query; keep only the query portion.
        $queryString = substr($uri, $queryPos + 1);
        if (($hashPos = strpos($queryString, '#')) !== FALSE) {
          $queryString = substr($queryString, 0, $hashPos);
        }
        if ($queryString !== '') {
          parse_str($queryString, $query);
          if ($url instanceof Url) {
            $url->setOption('query', $query + ($url->getOption('query') ?? []));
          }
          elseif ($url instanceof GeneratedUrl) {
            $generated = $url->getGeneratedUrl();
            if (strpos($generated, '?') === FALSE) {
              // Insert the query ahead of any fragment already on the URL.
              $fragment = '';
              if (($generatedHashPos = strpos($generated, '#')) !== FALSE) {
                $fragment = substr($generated, $generatedHashPos);
                $generated = substr($generated, 0, $generatedHashPos);
              }
              $url->setGeneratedUrl($generated . '?' . $queryString . $fragment);
            }
          }
        }
      }

      // The substituted entity URL drops any fragment present on the original
      // uri (e.g. "entity:node/385#hello"). Re-apply it so in-page anchors
      // survive Linkit substitution.
      if ($url && is_string($uri) && ($hashPos = strpos($uri, '#')) !== FALSE) {
        $fragment = substr($uri, $hashPos + 1);
        if ($fragment !== '') {
          if ($url instanceof Url) {
            $url->setOption('fragment', $fragment);
          }
          elseif ($url instanceof GeneratedUrl) {
            $generated = $url->getGeneratedUrl();
            if (strpos($generated, '#') === FALSE) {
              $url->setGeneratedUrl($generated . '#' . $fragment);
            }
          }
        }
      }

      return $url;
    }
    return NULL;
  }

}
