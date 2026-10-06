<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_metatag\Plugin\Field\FieldWidget\NeoMetatagShelf;
use PHPUnit\Framework\Attributes\Group;

/**
 * The neo_metatag side panel widget keeps what it never built.
 *
 * The widget renders no meta tag fields until an editor asks for them. A
 * save without them must leave the entity's overrides alone: reading the
 * missing fields as empty would wipe every override on every save of an
 * entity nobody opened the meta tags of. Once built, the fields are read as
 * metatag's own widget reads them.
 *
 * The form is submitted programmatically, which is what the entity form does
 * on a real save minus the browser. "Built" is the widget state the AJAX
 * button sets, put in place before the submit.
 */
#[Group('neo')]
final class MetatagShelfWidgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'entity_test',
    'token',
    'metatag',
    'neo_metatag',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['metatag']);

    FieldStorageConfig::create([
      'field_name' => 'field_metatags',
      'entity_type' => 'entity_test',
      'type' => 'metatag',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_metatags',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_metatags', ['type' => 'neo_metatag_shelf'])->save();
  }

  /**
   * A save without the form built leaves the overrides as they were.
   */
  public function testUnbuiltFormKeepsOverrides(): void {
    $entity = $this->createEntity(['abstract' => 'Saved abstract']);

    $this->submit($entity, FALSE, []);

    $this->assertSame(['abstract' => 'Saved abstract'], $this->overrides($entity));
  }

  /**
   * A save with the form built stores what its fields hold.
   */
  public function testBuiltFormSavesFields(): void {
    $entity = $this->createEntity(['abstract' => 'Saved abstract']);

    $this->submit($entity, TRUE, [
      'field_metatags' => [['basic' => ['abstract' => 'Changed abstract']]],
    ]);

    $this->assertSame(['abstract' => 'Changed abstract'], $this->overrides($entity));
  }

  /**
   * Creates a saved test entity with meta tag overrides.
   */
  private function createEntity(array $overrides): EntityTest {
    $entity = EntityTest::create([
      'name' => 'Test',
      'field_metatags' => Json::encode($overrides),
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Submits the entity's form, with or without the meta tags form built.
   */
  private function submit(EntityTest $entity, bool $built, array $values): void {
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('entity_test', 'default')
      ->setEntity($entity);
    $form_state = (new FormState())->setValues($values + [
      'name' => [['value' => 'Test']],
      'op' => 'Save',
    ]);
    if ($built) {
      NeoMetatagShelf::setWidgetState([], 'field_metatags', $form_state, [
        'items_count' => 1,
        'array_parents' => [],
        'neo_metatag_loaded' => TRUE,
      ]);
    }
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], array_map('strval', $form_state->getErrors()));
  }

  /**
   * The overrides stored for an entity, read back from storage.
   */
  private function overrides(EntityTest $entity): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache();
    $reloaded = $storage->load($entity->id());
    return metatag_data_decode($reloaded->get('field_metatags')->value);
  }

}
