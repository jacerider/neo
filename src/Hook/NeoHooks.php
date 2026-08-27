<?php

declare(strict_types=1);

namespace Drupal\neo\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo\Helpers\TableProps;
use Drupal\neo\NeoPreRender;
use Drupal\neo\NeoProcess;

/**
 * The module's behavioural hook implementations.
 *
 * The five hooks here alter a data structure something else then acts on, and
 * none of them produces markup: core's autocomplete library gains Neo's, an
 * entity build gains the classes and `data-` attributes its display's Neo
 * entity attribute components ask for, five element types gain process and
 * pre-render callbacks, the Views table style's per-column schema gains the
 * table props, and the `result` area plugin is pointed at Neo's own class.
 * Theme registration, the preprocessors and the form alter live in their own
 * classes, which is core's own `…Hooks` / `…ThemeHooks` / `…FormHooks` split
 * and the one `neo_toolbar` already went through.
 *
 * Every body below is what stood in `neo.module`, unchanged. Nothing any of
 * them decides moved with them: not which extension gains a library, not which
 * attribute a component becomes, not the position any callback lands in, not
 * which props reach the schema. The only collaborator anything here needs is
 * \Drupal\neo\Helpers\TableProps, which is a static rather than a global so
 * that this class does not depend on `neo.module` having been loaded — and
 * because nothing here reaches a container service, the class takes no
 * constructor arguments at all.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods, and nothing but the hook
 * system calls them.
 */
class NeoHooks {

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(&$libraries, $extension): void {
    if ($extension == 'core') {
      $libraries['drupal.autocomplete']['dependencies'][] = 'neo/autocomplete';
    }
  }

  /**
   * Implements hook_entity_view_alter().
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    if ($entity instanceof ContentEntityInterface) {
      foreach ($display->getComponents() as $field_name => $component) {
        if (!isset($component['type'])) {
          continue;
        }
        if ($component['type'] == 'neo_entity_attribute') {
          if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
            $field = $entity->get($field_name);
            $type = $component['settings']['type'] ?? 'class';
            if ($type === 'class') {
              foreach ($field->getValue() as $item) {
                $value = $item['value'] ?? $item['target_id'];
                $build['#attributes']['class'][] = Html::getClass($component['settings']['prefix'] . $value . $component['settings']['suffix']);
              }
            }
            elseif ($type === 'data') {
              $name = Html::getClass($component['settings']['prefix'] . str_replace('field_', '', $field_name) . $component['settings']['suffix']);
              if ($field->getFieldDefinition()->getFieldStorageDefinition()->getCardinality() === 1) {
                $build['#attributes']['data-' . $name] = $field->value ?? $field->target_id;
              }
              else {
                $build['#attributes']['data-' . $name] = json_encode($field->value);
              }
            }
          }
        }
      }
    }
  }

  /**
   * Implements hook_element_info_alter().
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(&$info): void {
    if (isset($info['details'])) {
      $processes = !empty($info['details']['#process']) ? $info['details']['#process'] : [];
      array_unshift($processes, [NeoProcess::class, 'details']);
      $info['details']['#process'] = $processes;
    }
    if (isset($info['view'])) {
      $info['view']['#pre_render'][] = [NeoPreRender::class, 'view'];
    }
    $info['entity_autocomplete']['#process'] = array_merge([[NeoProcess::class, 'entityAutocomplete']], $info['entity_autocomplete']['#process']);
    foreach ([
      'link',
      'button',
      'submit',
      'neo_modal_link',
    ] as $type) {
      $info[$type]['#pre_render'] = array_merge(
        [[NeoPreRender::class, 'disable']],
        $info[$type]['#pre_render'] ?? []
      );
    }
    if (isset($info['entity_autocomplete'])) {
      // Remove maxlength restriction for entity_autocomplete fields.
      $info['entity_autocomplete']['#maxlength'] = NULL;
    }
  }

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(&$definitions): void {
    if (isset($definitions['views.style.table'])) {
      foreach (TableProps::get() as $key => $prop) {
        $definitions['views.style.table']['mapping']['info']['sequence']['mapping'][$key] = [
          'type' => 'string',
          'label' => $prop['label'],
        ];
      }
    }
  }

  /**
   * Implements hook_views_plugins_area_alter().
   */
  #[Hook('views_plugins_area_alter')]
  public function viewsPluginsAreaAlter(array &$plugins): void {
    $plugins['result']['class'] = 'Drupal\neo\Plugin\views\area\NeoResult';
  }

}
