<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityFormMode;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_metatag\WidgetConverter;
use PHPUnit\Framework\Attributes\Group;

/**
 * The converter behind `drush neo:metatag:widgets`, display by display.
 *
 * The command is a thin table over WidgetConverter, so the converter is what
 * is driven here. Two bundles carry the field; one has a second form mode and
 * a third that hides the field, so the sweep, the filters and "hidden stays
 * hidden" each have something to act on.
 */
#[Group('neo')]
final class MetatagWidgetConverterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'token',
    'metatag',
    'neo_metatag',
  ];

  /**
   * The converter.
   */
  private WidgetConverter $converter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    EntityTestHelper::createBundle('other');

    FieldStorageConfig::create([
      'field_name' => 'field_metatags',
      'entity_type' => 'entity_test',
      'type' => 'metatag',
    ])->save();
    foreach (['compact', 'hiding'] as $mode) {
      EntityFormMode::create([
        'id' => 'entity_test.' . $mode,
        'label' => $mode,
        'targetEntityType' => 'entity_test',
      ])->save();
    }
    foreach (['entity_test', 'other'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_metatags',
        'entity_type' => 'entity_test',
        'bundle' => $bundle,
      ])->save();
      $this->display($bundle, 'default')->setComponent('field_metatags', [
        'type' => 'metatag_firehose',
        'weight' => 7,
        'region' => 'content',
        'settings' => ['sidebar' => FALSE, 'use_details' => FALSE],
      ])->save();
    }
    $this->display('entity_test', 'compact')
      ->setComponent('field_metatags', ['type' => 'metatag_firehose'])
      ->save();
    $this->display('entity_test', 'hiding')->removeComponent('field_metatags')->save();

    $this->converter = $this->container->get('neo_metatag.widget_converter');
  }

  /**
   * Converts every display showing the field, keeping everything else.
   */
  public function testConvertsEveryVisibleDisplay(): void {
    $rows = $this->converter->convert();

    $this->assertSame([
      'entity_test.entity_test.compact',
      'entity_test.entity_test.default',
      'entity_test.other.default',
    ], array_column($rows, 'display'));
    $this->assertSame(['converted'], array_unique(array_column($rows, 'status')));

    $component = $this->display('entity_test', 'default')->getComponent('field_metatags');
    $this->assertSame('neo_metatag_shelf', $component['type']);
    $this->assertSame(7, $component['weight']);
    $this->assertSame('content', $component['region']);
    $this->assertSame(['sidebar' => FALSE, 'use_details' => FALSE], $component['settings']);
    $this->assertSame('neo_metatag_shelf', $this->display('entity_test', 'compact')->getComponent('field_metatags')['type']);
    $this->assertNull($this->display('entity_test', 'hiding')->getComponent('field_metatags'));
  }

  /**
   * A second run finds nothing to do.
   */
  public function testIsIdempotent(): void {
    $this->converter->convert();
    $rows = $this->converter->convert();
    $this->assertSame(['unchanged'], array_unique(array_column($rows, 'status')));
  }

  /**
   * A dry run reports the same rows and saves nothing.
   */
  public function testDryRunSavesNothing(): void {
    $rows = $this->converter->convert(dry_run: TRUE);
    $this->assertCount(3, $rows);
    $this->assertSame('metatag_firehose', $this->display('entity_test', 'default')->getComponent('field_metatags')['type']);
  }

  /**
   * The entity type and bundle filters narrow the sweep.
   */
  public function testFilters(): void {
    $this->assertSame([], $this->converter->convert(entity_type_id: 'node'));

    $rows = $this->converter->convert(entity_type_id: 'entity_test', bundle: 'other');
    $this->assertSame(['entity_test.other.default'], array_column($rows, 'display'));
    $this->assertSame('metatag_firehose', $this->display('entity_test', 'default')->getComponent('field_metatags')['type']);
  }

  /**
   * A revert goes back to metatag's widget, and only from this module's.
   */
  public function testRevert(): void {
    $this->converter->convert(bundle: 'other');
    $rows = $this->converter->convert(revert: TRUE);

    $statuses = array_combine(array_column($rows, 'display'), array_column($rows, 'status'));
    $this->assertSame([
      'entity_test.entity_test.compact' => 'unchanged',
      'entity_test.entity_test.default' => 'unchanged',
      'entity_test.other.default' => 'converted',
    ], $statuses);
    $component = $this->display('other', 'default')->getComponent('field_metatags');
    $this->assertSame('metatag_firehose', $component['type']);
    $this->assertSame(['sidebar' => FALSE, 'use_details' => FALSE], $component['settings']);
  }

  /**
   * Loads a form display fresh from storage, or creates it.
   */
  private function display(string $bundle, string $mode): EntityFormDisplay {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_form_display');
    $storage->resetCache();
    return $storage->load("entity_test.$bundle.$mode") ?? EntityFormDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => $bundle,
      'mode' => $mode,
      'status' => TRUE,
    ]);
  }

}
