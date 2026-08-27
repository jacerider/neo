<?php

declare(strict_types=1);

namespace Drupal\neo_taxonomy\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * The sub-module's bundle field and library hook implementations.
 *
 * Two of the five hooks `neo_taxonomy.module` carried: the bundle field info
 * hook that makes a term's description field required per vocabulary from a
 * third-party setting, and the library alter that blanks `taxonomy_manager`'s
 * form CSS. Everything form-shaped — three alters, two callbacks and the
 * vocabulary settings helper — is on
 * \Drupal\neo_taxonomy\Hook\NeoTaxonomyFormHooks, which is core's
 * `…Hooks` / `…FormHooks` split. Between the two classes nothing procedural is
 * left, which is what lets the module declare its hook scan skip parameter and
 * what let `neo_taxonomy.module` go entirely.
 *
 * Both bodies are what stood in the `.module`, with one substitution: the
 * bundle field hook's `\Drupal::config()` reach became the constructor
 * argument. Nothing either of them decides moved with them — not that the
 * description is cloned before it is made required, which is what keeps one
 * vocabulary's setting out of every other vocabulary, and not which of
 * `taxonomy_manager`'s libraries is blanked.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods, and nothing but the hook
 * system calls them.
 */
class NeoTaxonomyHooks {

  /**
   * Constructs a NeoTaxonomyHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, which answers the vocabulary's third-party settings
   *   the bundle field hook reads. It is core's and is always present, so
   *   injecting it moves no failure earlier than the static call it replaces.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_entity_bundle_field_info().
   */
  #[Hook('entity_bundle_field_info')]
  public function entityBundleFieldInfo(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    $fields = [];
    // The description field is a base field and cannot be made required via the
    // field UI. Allow it to be required on a per-vocabulary basis.
    if ($entity_type->id() === 'taxonomy_term' && isset($base_field_definitions['description'])) {
      $settings = $this->configFactory->get('taxonomy.vocabulary.' . $bundle)->get('third_party_settings.neo_taxonomy');
      if (!empty($settings['description_required'])) {
        $fields['description'] = clone $base_field_definitions['description'];
        $fields['description']->setRequired(TRUE);
      }
    }
    return $fields;
  }

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(&$libraries, $extension) {
    if ($extension == 'taxonomy_manager') {
      $libraries['form']['css'] = [];
    }
  }

}
