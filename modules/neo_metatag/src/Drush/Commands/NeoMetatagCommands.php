<?php

declare(strict_types=1);

namespace Drupal\neo_metatag\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\neo_metatag\WidgetConverter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Neo | Metatag.
 */
final class NeoMetatagCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_metatag.widget_converter')]
    private readonly WidgetConverter $converter,
  ) {
    parent::__construct();
  }

  /**
   * Switch every metatag field's form widget to the side panel widget.
   */
  #[CLI\Command(name: 'neo:metatag:widgets', aliases: ['neom-widgets'])]
  #[CLI\Option(name: 'dry-run', description: 'List what would change without saving.')]
  #[CLI\Option(name: 'revert', description: 'Switch the side panel widget back to metatag\'s own (metatag_firehose).')]
  #[CLI\Option(name: 'entity-type', description: 'Only this entity type, e.g. node.')]
  #[CLI\Option(name: 'bundle', description: 'Only this bundle, e.g. page.')]
  #[CLI\Usage(name: 'drush neo:metatag:widgets --dry-run', description: 'Show which form displays would change.')]
  #[CLI\Usage(name: 'drush neo:metatag:widgets --entity-type=node --bundle=page', description: 'Convert the page content type only.')]
  #[CLI\Usage(name: 'drush neo:metatag:widgets --revert', description: 'Go back to metatag\'s widget everywhere.')]
  #[CLI\FieldLabels(labels: [
    'display' => 'Form display',
    'field' => 'Field',
    'from' => 'From',
    'to' => 'To',
    'status' => 'Status',
  ])]
  public function widgets(
    array $options = [
      'dry-run' => FALSE,
      'revert' => FALSE,
      'entity-type' => self::REQ,
      'bundle' => self::REQ,
      'format' => 'table',
    ],
  ): RowsOfFields {
    $dry_run = (bool) $options['dry-run'];
    $rows = $this->converter->convert(
      (bool) $options['revert'],
      $options['entity-type'] ?: NULL,
      $options['bundle'] ?: NULL,
      $dry_run,
    );

    $converted = count(array_filter($rows, fn ($row) => $row['status'] === 'converted'));
    if (!$rows) {
      $this->logger()->notice('No form display shows a metatag field.');
    }
    elseif ($dry_run) {
      $this->logger()->notice(dt('Dry run: @count field(s) would be converted. Nothing was saved.', ['@count' => $converted]));
    }
    elseif ($converted) {
      $this->logger()->success(dt('Converted @count field(s). Export the form displays with drush config:export.', ['@count' => $converted]));
    }
    else {
      $this->logger()->success('Nothing to convert.');
    }

    if ($dry_run) {
      foreach ($rows as &$row) {
        if ($row['status'] === 'converted') {
          $row['status'] = 'would convert';
        }
      }
      unset($row);
    }
    return new RowsOfFields($rows);
  }

}
