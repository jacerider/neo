<?php

declare(strict_types=1);

namespace Drupal\neo_metatag;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Moves metatag fields' form widgets to the side panel widget, or back.
 *
 * Every form display, in every form mode, that shows a metatag field is
 * converted. A field the display hides stays hidden. The weight, region,
 * third-party settings and the two settings both widgets share (sidebar and
 * use_details) are kept. Converting what is already converted changes
 * nothing, so it is safe to run again after adding a bundle.
 *
 * This saves form display config. Exporting it is left to the site.
 */
final class WidgetConverter {

  /**
   * The side panel widget.
   */
  public const WIDGET = 'neo_metatag_shelf';

  /**
   * Metatag's own widget, which a revert goes back to.
   */
  public const FIREHOSE = 'metatag_firehose';

  /**
   * The settings both widgets understand.
   */
  private const SHARED_SETTINGS = ['sidebar', 'use_details'];

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Converts the form displays of metatag fields.
   *
   * @param bool $revert
   *   TRUE to move this module's widget back to metatag's. Any other widget
   *   is left as it is.
   * @param string|null $entity_type_id
   *   Only this entity type, or NULL for all.
   * @param string|null $bundle
   *   Only this bundle, or NULL for all.
   * @param bool $dry_run
   *   TRUE to report what would change without saving.
   *
   * @return array<int, array{display: string, field: string, from: string, to: string, status: string}>
   *   One row per visible metatag field in each display, in display order.
   *   Status is 'converted', 'unchanged' or 'skipped'.
   */
  public function convert(bool $revert = FALSE, ?string $entity_type_id = NULL, ?string $bundle = NULL, bool $dry_run = FALSE): array {
    $to = $revert ? self::FIREHOSE : self::WIDGET;
    $storage = $this->entityTypeManager->getStorage('entity_form_display');
    $rows = [];

    foreach ($this->entityFieldManager->getFieldMapByFieldType('metatag') as $type => $fields) {
      if ($entity_type_id !== NULL && $type !== $entity_type_id) {
        continue;
      }
      $by_bundle = [];
      foreach ($fields as $field_name => $info) {
        foreach ($info['bundles'] as $field_bundle) {
          $by_bundle[$field_bundle][] = $field_name;
        }
      }
      ksort($by_bundle);

      foreach ($by_bundle as $field_bundle => $field_names) {
        if ($bundle !== NULL && $field_bundle !== $bundle) {
          continue;
        }
        $displays = $storage->loadByProperties([
          'targetEntityType' => $type,
          'bundle' => $field_bundle,
        ]);
        ksort($displays);
        foreach ($displays as $display) {
          assert($display instanceof EntityFormDisplayInterface);
          $changed = FALSE;
          foreach ($field_names as $field_name) {
            $component = $display->getComponent($field_name);
            if ($component === NULL) {
              continue;
            }
            $from = (string) ($component['type'] ?? '');
            $status = 'converted';
            if ($from === $to) {
              $status = 'unchanged';
            }
            elseif ($revert && $from !== self::WIDGET) {
              $status = 'skipped';
            }
            else {
              $component['type'] = $to;
              $component['settings'] = array_intersect_key(
                $component['settings'] ?? [],
                array_flip(self::SHARED_SETTINGS),
              );
              $display->setComponent($field_name, $component);
              $changed = TRUE;
            }
            $rows[] = [
              'display' => (string) $display->id(),
              'field' => $field_name,
              'from' => $from,
              'to' => $status === 'converted' ? $to : $from,
              'status' => $status,
            ];
          }
          if ($changed && !$dry_run) {
            $display->save();
          }
        }
      }
    }

    return $rows;
  }

}
