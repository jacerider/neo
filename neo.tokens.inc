<?php

/**
 * @file
 * Provides token definitions for the Neo Alchemist module.
 */

declare(strict_types=1);

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImageStyle;

/**
 * Provides definitions for Neo Alchemist tokens.
 *
 * These tokens are used to represent entities in a smart way, such as
 * providing a title or description based on the content of the entity.
 *
 * @return array
 *   An array of token definitions.
 */
function neo_tokens_definitions() {
  $data = [];
  $data['title'] = [
    'name' => t('Neo Alchemist: Smart Title'),
    'description' => t('Will determine the best title to use for representing this token.'),
    'type' => 'neo',
  ];
  $data['description'] = [
    'name' => t('Neo Alchemist: Smart Description'),
    'description' => t('Will determine the best description to use for representing this token.'),
    'type' => 'neo',
  ];
  $data['logo'] = [
    'name' => t('Neo Alchemist: Smart Logo'),
    'description' => t('Will determine the site logo to use for representing this token.'),
    'type' => 'neo',
  ];
  $data['logo:width'] = [
    'name' => t('Neo Alchemist: Smart Logo Width'),
    'description' => t('Will determine the best image width to use for representing this token.'),
    'type' => 'neo',
  ];
  $data['logo:height'] = [
    'name' => t('Neo Alchemist: Smart Logo Height'),
    'description' => t('Will determine the best image height to use for representing this token.'),
    'type' => 'neo',
  ];
  $data['image'] = [
    'name' => t('Neo Alchemist: Smart Image'),
    'description' => t('Will determine the best image to use for representing this token. By default, this will return an exact image of 1200x630 pixels. You can specify the effect as parameters, e.g., [neo:image:crop:1200:630].'),
    'type' => 'neo',
  ];
  $data['image:width'] = [
    'name' => t('Neo Alchemist: Smart Image Width'),
    'description' => t('Will determine the best image width to use for representing this token.'),
    'type' => 'neo',
  ];
  $data['image:height'] = [
    'name' => t('Neo Alchemist: Smart Image Height'),
    'description' => t('Will determine the best image height to use for representing this token.'),
    'type' => 'neo',
  ];
  return $data;
}

/**
 * Implements hook_token_info().
 */
function neo_token_info() {
  return [
    'types' => [
      'neo' => [
        'name' => t('Neo'),
        'description' => t('Tokens provided by Neo.'),
      ],
    ],
    'tokens' => [
      'neo' => neo_tokens_definitions(),
    ],
  ];
}

/**
 * Implements hook_tokens().
 */
function neo_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];
  if ($type !== 'neo') {
    return $replacements;
  }

  $entities = [];
  foreach ($data as $dataType => $value) {
    if ($value instanceof ContentEntityInterface) {
      $entities[] = $value;
    }
  }
  if (!$entities) {
    $entities[] = NULL;
  }

  // Use both static and database caching.
  $cache = \Drupal::cache();
  $staticCache = &drupal_static(__FUNCTION__);

  $definitions = neo_tokens_definitions();
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

      // Check static cache.
      if (isset($staticCache[$key])) {
        $replacements[$original] = $staticCache[$key];
        continue 2;
      }

      // Check database cache.
      if ($cacheData = $cache->get($key)) {
        $replacements[$original] = $cacheData->data;
        continue 2;
      }

      // Fetch values if not found in cache.
      $value = match ($name) {
        'title' => neo_tokens_title($params, $entity),
        'description' => neo_tokens_description($params, $entity),
        'logo' => neo_tokens_logo($params, $entity),
        'image' => neo_tokens_image($params, $entity),
        default => NULL,
      };

      // Store in cache.
      $staticCache[$key] = $value;
      if ($entity) {
        $cache->set($key, $value, CacheBackendInterface::CACHE_PERMANENT, $entity->getCacheTagsToInvalidate());
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
 * Retrieves a title for token replacement.
 *
 * This function attempts to find a title in the following order:
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
function neo_tokens_title(array $params = [], ?ContentEntityInterface $entity = NULL) {
  $title = NULL;

  if (\Drupal::service('path.matcher')->isFrontPage()) {
    return \Drupal::config('system.site')->get('name');
  }

  // Allow modules to alter the title before using.
  \Drupal::moduleHandler()->alter('neo_token_title', $title, $params, $entity);

  if (!$title) {
    $request = \Drupal::request();
    $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    if ($route) {
      $title = \Drupal::service('title_resolver')->getTitle($request, $route);
      $title = token_render_array_value($title);
    }
    else {
      $title = $entity->label();
    }
  }

  return $title;
}

/**
 * Provides a description token for Neo.
 *
 * This function retrieves the best description to represent an entity,
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
function neo_tokens_description(array $params = [], ?ContentEntityInterface $entity = NULL) {
  $description = NULL;

  if (\Drupal::service('path.matcher')->isFrontPage()) {
    return \Drupal::config('system.site')->get('slogan');
  }

  // Allow modules to alter the description before using.
  \Drupal::moduleHandler()->alter('neo_token_description', $description, $params, $entity);

  if (!$description) {
    if ($entity) {
      $description = match($entity->getEntityTypeId()) {
        'taxonomy_term' => $entity->get('description')->value,
        default => NULL,
      };
    }
    if (!$description) {
      $description = \Drupal::config('system.site')->get('slogan');
    }
  }

  return $description;
}

/**
 * Provides a smart image logo token for Neo.
 *
 * This function retrieves the best image to represent an entity, allowing
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
function neo_tokens_logo(array $params = [], ?ContentEntityInterface $entity = NULL) {
  $op = $params[0] ?? NULL;
  if (in_array($op, ['width', 'height'])) {
    // If the first parameter is 'width' or 'height', we will return the
    // respective dimension of the image.
    $params = array_slice($params, 1);
  }

  if ($data = neo_tokens_image_fetch($params, 'neo_token_logo', $entity, FALSE)) {
    return match ($op) {
      'width' => $data['uri'] && file_exists($data['uri']) ? (((string) getimagesize($data['uri'])[0]) ?? NULL) : NULL,
      'height' => $data['uri'] && file_exists($data['uri']) ? (((string) getimagesize($data['uri'])[1]) ?? NULL) : NULL,
      default => $data['url'],
    };
  }
  return NULL;
}

/**
 * Provides a smart image token for Neo.
 *
 * This function retrieves the best image to represent an entity, allowing
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
function neo_tokens_image(array $params = [], ?ContentEntityInterface $entity = NULL) {
  if (\Drupal::service('path.matcher')->isFrontPage()) {
    // We are on the front page, so we want to use the logo.
    return neo_tokens_logo($params, $entity);
  }
  $op = $params[0] ?? NULL;
  if (in_array($op, ['width', 'height'])) {
    // If the first parameter is 'width' or 'height', we will return the
    // respective dimension of the image.
    $params = array_slice($params, 1);
  }

  if ($data = neo_tokens_image_fetch($params, 'neo_token_image', $entity, TRUE)) {
    return match ($op) {
      'width' => $data['uri'] && file_exists($data['uri']) ? (((string) getimagesize($data['uri'])[0]) ?? NULL) : NULL,
      'height' => $data['uri'] && file_exists($data['uri']) ? (((string) getimagesize($data['uri'])[1]) ?? NULL) : NULL,
      default => $data['url'],
    };
  }
  return NULL;
}

/**
 * Gets the best image URL to represent an entity for token replacement.
 *
 * This function searches for an appropriate image in the following order:
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
function neo_tokens_image_fetch(array $params, string $hook, ?ContentEntityInterface $entity = NULL, bool $crawlEntity = TRUE): array {
  $staticCache = &drupal_static(__FUNCTION__);
  $moduleHandler = \Drupal::moduleHandler();
  $key = implode(':', $entity ? [
    $entity->getEntityTypeId(),
    $entity->id(),
    $hook,
  ] : [
    'no-entity',
    $hook,
  ]);
  $uri = $staticCache[$key] ?? [];
  if (!$uri) {
    if ($entity && $crawlEntity) {
      // Try to get a media image from the entity's fields.
      $uri = neo_tokens_entity_to_media_image_uri($entity);
    }

    // Allow modules to alter the URI before using.
    $moduleHandler->alter($hook, $uri, $params, $entity);

    // Cache the URI if it was found.
    $staticCache[$key] = $uri;
  }
  return $uri ? neo_tokens_image_data($uri, $params) : [
    'uri' => NULL,
    'url' => NULL,
  ];
}

/**
 * Converts a URI to an image URL.
 *
 * @param string $uri
 *   The file URI to convert to a URL.
 *
 * @return array
 *   An array containing the uri and url.
 */
function neo_tokens_image_data(string $uri, array $params = []): array {
  if (\Drupal::moduleHandler()->moduleExists('neo_image')) {
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
    'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($uri),
  ];
}

/**
 * Find a media image URI from a content entity.
 *
 * This function searches through the entity's fields to find a media
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
function neo_tokens_entity_to_media_image_uri(ContentEntityInterface $entity): ?string {
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
