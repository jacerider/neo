<?php

declare(strict_types=1);

namespace Drupal\neo\Hook;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImageStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The smart tokens: two hooks and the resolution chain behind them.
 *
 * Every meta tag on a Neo site passes through here. `hook_token_info()`
 * publishes one token type, `neo`, and eight tokens under it; `hook_tokens()`
 * is the dispatcher, and the nine private methods below it are the chain — the
 * definition list, the title, description, logo and image resolvers, the image
 * fetch with its alter hook, the dimension reader, the url build and the media
 * crawl. Nothing outside this class calls any of the nine, which is why none of
 * them kept a global forwarder.
 *
 * All of it stood in `neo.tokens.inc`, which is **deleted** rather than
 * emptied. Core's hook collector registers a `{module}.{group}.inc` file as a
 * group include from its filename, before it parses a line and outside the
 * branch `neo.skip_procedural_hook_scan` guards; `system` declares the `tokens`
 * group, so core included that file behind a deprecation — raised in Drupal
 * 11.2, removed in Drupal 12 — on every token replacement. An emptied file
 * would have kept the notice.
 *
 * **The three per-request memos are private properties.** They were
 * `drupal_static()` caches, which no test could empty without emptying every
 * static in the process. On an object they are per-request by construction,
 * emptied by constructing another instance, and reachable by a test. The
 * behaviour they have is kept exactly, including the part that looks like a bug
 * and is pinned as one: a computed `NULL` goes into the dispatcher's memo and
 * the `isset()` reading it treats it as absent, so a `NULL` is recomputed
 * rather than short-circuited. The **database cache** behind the dispatcher is
 * untouched — same key, same permanent expiry, same entity invalidation tags,
 * still written and read only when an entity is present.
 *
 * **Two calls stay as they are.** `token_render_array_value()` is a global from
 * contrib `token`, which `neo.info.yml` does not declare a dependency on, and
 * the `neo_image` module check has a `NeoImageStyle` instantiation behind it.
 * Both reach modules this one does not declare; injecting either would move a
 * lazy per-request failure to container build time on a site without it. That
 * undeclared `token` dependency is a recorded backlog finding, not a bug this
 * class introduces.
 *
 * This is not an API and it is not `final`. The two hook methods are public
 * because core's hook collector only reads public methods, and nothing but the
 * hook system calls them.
 */
class NeoTokensHooks {

  use StringTranslationTrait;

  /**
   * The dispatcher's memo, keyed by entity, token name and parameters.
   *
   * @var array
   */
  private array $dispatchCache = [];

  /**
   * The image fetch's memo, keyed by entity and alter hook name.
   *
   * What is memoised is the **uri**, before the url is built, so the parameters
   * a caller passed are deliberately outside the key.
   *
   * @var array
   */
  private array $imageFetchCache = [];

  /**
   * The dimension reader's memo, keyed by file uri.
   *
   * @var array
   */
  private array $imageDimensionCache = [];

  /**
   * Constructs a NeoTokensHooks object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache bin, which holds the dispatcher's database cache.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, which runs the four alter hooks and answers whether
   *   `neo_image` is installed.
   * @param \Drupal\Core\Path\PathMatcherInterface $pathMatcher
   *   The path matcher, which decides the front-page branches.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the site name and slogan.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for the request the title resolver runs against.
   * @param \Drupal\Core\Controller\TitleResolverInterface $titleResolver
   *   The title resolver, which answers the current route's page title.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file url generator, which builds the absolute url a meta tag needs.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected readonly CacheBackendInterface $cache,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly PathMatcherInterface $pathMatcher,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly RequestStack $requestStack,
    protected readonly TitleResolverInterface $titleResolver,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    return [
      'types' => [
        'neo' => [
          'name' => $this->t('Neo'),
          'description' => $this->t('Tokens provided by Neo.'),
        ],
      ],
      'tokens' => [
        'neo' => $this->definitions(),
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    if ($type !== 'neo') {
      return $replacements;
    }

    $entities = [];
    foreach ($data as $value) {
      if ($value instanceof ContentEntityInterface) {
        $entities[] = $value;
      }
    }
    if (!$entities) {
      $entities[] = NULL;
    }

    $definitions = $this->definitions();
    foreach ($tokens as $name => $original) {
      $params = explode(':', $name);
      $name = array_shift($params);
      if (!isset($definitions[$name])) {
        continue;
      }
      foreach ($entities as $entity) {
        $key = implode(':', array_merge($entity ? [
          'neo_tokens',
          $entity->getEntityTypeId(),
          $entity->id(),
          $name,
        ] : [
          'neo_tokens',
          'no-entity',
          $name,
        ], $params));

        // Check the per-request cache.
        if (isset($this->dispatchCache[$key])) {
          $replacements[$original] = $this->dispatchCache[$key];
          continue 2;
        }

        // Check database cache.
        if ($entity) {
          if ($cacheData = $this->cache->get($key)) {
            $replacements[$original] = $cacheData->data;
            continue 2;
          }
        }

        // Fetch values if not found in cache.
        $value = match ($name) {
          'title' => $this->title($params, $entity),
          'description' => $this->description($params, $entity),
          'logo' => $this->logo($params, $entity),
          'image' => $this->image($params, $entity),
          default => NULL,
        };

        // Store in cache.
        $this->dispatchCache[$key] = $value;
        if ($entity) {
          $this->cache->set($key, $value, CacheBackendInterface::CACHE_PERMANENT, $entity->getCacheTagsToInvalidate());
        }

        if ($value) {
          $replacements[$original] = $value;
          continue 2;
        }
      }
    }
    return $replacements;
  }

  /**
   * Provides definitions for Neo Alchemist tokens.
   *
   * These tokens are used to represent entities in a smart way, such as
   * providing a title or description based on the content of the entity.
   *
   * @return array
   *   An array of token definitions.
   */
  private function definitions(): array {
    $data = [];
    $data['title'] = [
      'name' => $this->t('Neo Alchemist: Smart Title'),
      'description' => $this->t('Will determine the best title to use for representing this token.'),
      'type' => 'neo',
    ];
    $data['description'] = [
      'name' => $this->t('Neo Alchemist: Smart Description'),
      'description' => $this->t('Will determine the best description to use for representing this token.'),
      'type' => 'neo',
    ];
    $data['logo'] = [
      'name' => $this->t('Neo Alchemist: Smart Logo'),
      'description' => $this->t('Will determine the site logo to use for representing this token.'),
      'type' => 'neo',
    ];
    $data['logo:width'] = [
      'name' => $this->t('Neo Alchemist: Smart Logo Width'),
      'description' => $this->t('Will determine the best image width to use for representing this token.'),
      'type' => 'neo',
    ];
    $data['logo:height'] = [
      'name' => $this->t('Neo Alchemist: Smart Logo Height'),
      'description' => $this->t('Will determine the best image height to use for representing this token.'),
      'type' => 'neo',
    ];
    $data['image'] = [
      'name' => $this->t('Neo Alchemist: Smart Image'),
      'description' => $this->t('Will determine the best image to use for representing this token. By default, this will return an exact image of 1200x630 pixels. You can specify the effect as parameters, e.g., [neo:image:crop:1200:630].'),
      'type' => 'neo',
    ];
    $data['image:width'] = [
      'name' => $this->t('Neo Alchemist: Smart Image Width'),
      'description' => $this->t('Will determine the best image width to use for representing this token.'),
      'type' => 'neo',
    ];
    $data['image:height'] = [
      'name' => $this->t('Neo Alchemist: Smart Image Height'),
      'description' => $this->t('Will determine the best image height to use for representing this token.'),
      'type' => 'neo',
    ];
    return $data;
  }

  /**
   * Retrieves a title for token replacement.
   *
   * This method attempts to find a title in the following order:
   * 1. From another module's hook_neo_token_title_alter() implementation.
   * 2. From the current route's page title.
   * 3. From the entity's label.
   *
   * @param array $params
   *   Additional parameters, such as image effect and dimensions.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   *
   * @return string|null
   *   The resolved title value or NULL if no title could be found.
   *
   * @see hook_neo_token_title_alter()
   */
  private function title(array $params = [], ?ContentEntityInterface $entity = NULL) {
    $title = NULL;

    if ($this->pathMatcher->isFrontPage()) {
      return $this->configFactory->get('system.site')->get('name');
    }

    // Allow modules to alter the title before using.
    $this->moduleHandler->alter('neo_token_title', $title, $params, $entity);

    if (!$title) {
      $request = $this->requestStack->getCurrentRequest();
      $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
      if ($route) {
        $title = $this->titleResolver->getTitle($request, $route);
        $title = token_render_array_value($title);
      }
      elseif ($entity) {
        $title = $entity->label();
      }
    }

    return $title;
  }

  /**
   * Provides a description token for Neo.
   *
   * This method retrieves the best description to represent an entity,
   * allowing for optional parameters to specify the desired description.
   *
   * @param array $params
   *   Additional parameters, such as image effect and dimensions.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   *
   * @return string|null
   *   A formatted description or NULL if no suitable description is found.
   */
  private function description(array $params = [], ?ContentEntityInterface $entity = NULL) {
    $description = NULL;

    if ($this->pathMatcher->isFrontPage()) {
      return $this->configFactory->get('system.site')->get('slogan');
    }

    // Allow modules to alter the description before using.
    $this->moduleHandler->alter('neo_token_description', $description, $params, $entity);

    if (!$description) {
      if ($entity) {
        $description = match($entity->getEntityTypeId()) {
          'taxonomy_term' => $entity->get('description')->value,
          default => NULL,
        };
      }
      if (!$description) {
        $description = $this->configFactory->get('system.site')->get('slogan');
      }
    }

    return $description;
  }

  /**
   * Provides a smart image logo token for Neo.
   *
   * This method retrieves the best image to represent an entity, allowing
   * for optional parameters to specify the desired image dimensions or effects.
   *
   * @param array $params
   *   Additional parameters, such as image effect and dimensions.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   *
   * @return string
   *   A formatted image URL or 'image' if no suitable image is found.
   */
  private function logo(array $params = [], ?ContentEntityInterface $entity = NULL) {
    $op = $params[0] ?? NULL;
    if (in_array($op, ['width', 'height'], TRUE)) {
      // If the first parameter is 'width' or 'height', we will return the
      // respective dimension of the image.
      $params = array_slice($params, 1);
    }

    if ($data = $this->imageFetch($params, 'neo_token_logo', $entity, FALSE)) {
      return match ($op) {
        'width', 'height' => $this->imageDimension($data['uri'], $op),
        default => $data['url'],
      };
    }
    return NULL;
  }

  /**
   * Provides a smart image token for Neo.
   *
   * This method retrieves the best image to represent an entity, allowing
   * for optional parameters to specify the desired image dimensions or effects.
   *
   * @param array $params
   *   Additional parameters, such as image effect and dimensions.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   *
   * @return string
   *   A formatted image URL or 'image' if no suitable image is found.
   */
  private function image(array $params = [], ?ContentEntityInterface $entity = NULL) {
    if ($this->pathMatcher->isFrontPage()) {
      // We are on the front page, so we want to use the logo.
      return $this->logo($params, $entity);
    }
    $op = $params[0] ?? NULL;
    if (in_array($op, ['width', 'height'], TRUE)) {
      // If the first parameter is 'width' or 'height', we will return the
      // respective dimension of the image.
      $params = array_slice($params, 1);
    }

    if ($data = $this->imageFetch($params, 'neo_token_image', $entity, TRUE)) {
      return match ($op) {
        'width', 'height' => $this->imageDimension($data['uri'], $op),
        default => $data['url'],
      };
    }
    return NULL;
  }

  /**
   * Gets the best image URL to represent an entity for token replacement.
   *
   * This method searches for an appropriate image in the following order:
   * 1. Media reference fields of type 'image'
   * 2. From another module's hook_neo_token_image_alter() implementation.
   *
   * @param array $params
   *   Additional parameters.
   * @param string $hook
   *   The hook name to use for altering the image URI, e.g., 'neo_token_image'.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   * @param bool $crawlEntity
   *   Whether to crawl the entity for media images. Defaults to TRUE.
   *
   * @return array
   *   An array containing the uri and url.
   */
  private function imageFetch(array $params, string $hook, ?ContentEntityInterface $entity = NULL, bool $crawlEntity = TRUE): array {
    $key = implode(':', $entity ? [
      $entity->getEntityTypeId(),
      $entity->id(),
      $hook,
    ] : [
      'no-entity',
      $hook,
    ]);
    if (!array_key_exists($key, $this->imageFetchCache)) {
      $uri = NULL;
      if ($entity && $crawlEntity) {
        // Try to get a media image from the entity's fields.
        $uri = $this->entityToMediaImageUri($entity);
      }

      // Allow modules to alter the URI before using.
      $this->moduleHandler->alter($hook, $uri, $params, $entity);

      $this->imageFetchCache[$key] = $uri;
    }
    $uri = $this->imageFetchCache[$key];
    return $uri ? $this->imageData($uri, $params) : [
      'uri' => NULL,
      'url' => NULL,
    ];
  }

  /**
   * Reads a dimension (width or height) from a local image file.
   *
   * Memoizes getimagesize() so width and height tokens for the same file
   * resolve in one syscall.
   *
   * @param string|null $uri
   *   The file URI to inspect.
   * @param string $dimension
   *   Either 'width' or 'height'.
   *
   * @return string|null
   *   The dimension as a string, or NULL if the file is missing or unreadable.
   */
  private function imageDimension(?string $uri, string $dimension): ?string {
    if (empty($uri) || !file_exists($uri)) {
      return NULL;
    }
    if (!array_key_exists($uri, $this->imageDimensionCache)) {
      $this->imageDimensionCache[$uri] = @getimagesize($uri) ?: NULL;
    }
    if ($this->imageDimensionCache[$uri] === NULL) {
      return NULL;
    }
    return (string) $this->imageDimensionCache[$uri][$dimension === 'width' ? 0 : 1];
  }

  /**
   * Converts a URI to an image URL.
   *
   * @param string $uri
   *   The file URI to convert to a URL.
   * @param array $params
   *   Additional parameters. Read only by the `neo_image` branch; the fallback
   *   branch accepts them and never looks at them, which the characterisation
   *   suite pins. Documenting them is the one docblock change the move forced:
   *   an undocumented parameter is a phpcs error on a file under `src/`.
   *
   * @return array
   *   An array containing the uri and url.
   */
  private function imageData(string $uri, array $params = []): array {
    if ($this->moduleHandler->moduleExists('neo_image')) {
      // Provide default parameters if none are given. These defaults are ideal
      // for meta tags and social media sharing.
      if (empty($params)) {
        $params = ['exact', '1200', '630'];
      }
      // Format the params for use in the NeoImageStyle.
      // The first parameter is the option type, e.g., 'exact', 'crop', etc.
      // The rest are the parameters passed to the style type.
      $optionType = array_shift($params);
      $options = [
        $optionType => $params,
      ];
      $neoImageStyle = new NeoImageStyle($options);
      return [
        'uri' => $neoImageStyle->getImageStyle()->buildUri($uri),
        'url' => $neoImageStyle->toUrlFromUri($uri, TRUE),
      ];
    }
    return [
      'uri' => $uri,
      'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
    ];
  }

  /**
   * Find a media image URI from a content entity.
   *
   * This method searches through the entity's fields to find a media
   * reference field that points to a media item of type 'image'. If such a
   * media item is found, it retrieves the thumbnail file URI from the media
   * item and returns it.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to search for media images.
   *
   * @return string|null
   *   The file URI of the media image thumbnail if found, or NULL if no
   *   suitable media image is found.
   */
  private function entityToMediaImageUri(ContentEntityInterface $entity): ?string {
    $uri = NULL;

    // Try to get a media image from the entity's fields.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface[] $mediaFields */
    $mediaFields = array_filter($entity->getFields(), function ($field) {
      $definition = $field->getFieldDefinition();
      return $definition->getType() === 'entity_reference'
        && $definition->getSetting('target_type') === 'media'
        && !$field->isEmpty()
        && in_array(
          'image',
          $definition->getSetting('handler_settings')['target_bundles'] ?? [],
          TRUE
        );
    });

    // Process media fields to find a valid image URI.
    foreach ($mediaFields as $mediaField) {
      foreach ($mediaField->referencedEntities() as $media) {
        if (!$media instanceof MediaInterface || $media->bundle() !== 'image') {
          continue;
        }

        $thumbnailField = $media->get('thumbnail');
        if (!$thumbnailField->isEmpty() && ($file = $thumbnailField->entity) instanceof FileInterface) {
          $uri = $file->getFileUri();
          break 2;
        }
      }
    }

    return $uri;
  }

}
