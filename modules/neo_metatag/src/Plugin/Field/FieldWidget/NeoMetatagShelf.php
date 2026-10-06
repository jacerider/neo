<?php

declare(strict_types=1);

namespace Drupal\neo_metatag\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metatag\Plugin\Field\FieldWidget\MetatagFirehose;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The meta tags form, built when asked for and edited in a side panel.
 *
 * The firehose widget builds every tag of every group on every entity form,
 * which with schema_metatag is hundreds of elements nobody looks at on most
 * edits. This widget shows a summary of what the entity overrides and an
 * "Edit meta tags" button instead. The button builds the full form over AJAX,
 * into a neo shelf: a side panel over the page that stays inside the entity
 * form, so its values submit with the entity.
 *
 * Until the form is built, the field's values are left as they were: there
 * are no fields to read them from. Once it is, the shelf opens and closes on
 * the page without another round trip. Done keeps the edits, which are saved
 * with the entity; Cancel puts back what the fields held when it opened.
 *
 * @see \Drupal\neo\Shelf
 */
#[FieldWidget(
  id: 'neo_metatag_shelf',
  label: new TranslatableMarkup('Neo | Meta tags - Side panel'),
  description: new TranslatableMarkup('Builds the meta tags form only when an editor asks for it, and opens it in a side panel.'),
  field_types: ['metatag'],
)]
class NeoMetatagShelf extends MetatagFirehose {

  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Built when opened, in a side panel.');
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_parents = $element['#field_parents'] ?? [];
    // Where the delta's values are. The wrapper and the shelf sit between the
    // delta and the groups, and must not add to the path.
    $parents = array_merge($field_parents, [$field_name, $delta]);
    $id = Html::getId(implode('-', array_merge(['neo-metatag'], $parents)));
    $button_name = $id . '-edit';
    $state = static::getWidgetState($field_parents, $field_name, $form_state);
    $loaded = !empty($state['neo_metatag_loaded']);
    $overrides = $this->getOverrides($items, (int) $delta);

    $sidebar = $this->getSetting('sidebar');
    $element['#type'] = $sidebar || $this->getSetting('use_details') ? 'details' : 'container';
    if ($sidebar) {
      $element['#group'] = 'advanced';
    }
    $element['#attributes']['class'][] = 'neo-metatag';
    $element['#attached']['library'][] = 'neo_metatag/widget';

    $element['widget'] = [
      '#type' => 'container',
      '#parents' => $parents,
      '#attributes' => [
        'id' => $id,
        'class' => ['neo-metatag__widget'],
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-metatag__summary']],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $overrides
            ? $this->formatPlural(count($overrides), '1 tag overridden', '@count tags overridden')
            : $this->t('Using the defaults'),
        ],
        'badge' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Unsaved'),
          '#attributes' => [
            'class' => ['neo-metatag__badge'],
            'title' => $this->t('Saved when you save this form.'),
            'hidden' => 'hidden',
          ],
        ],
      ],
    ];

    if (!$loaded) {
      $element['widget']['edit'] = [
        '#type' => 'submit',
        '#name' => $button_name,
        '#value' => $this->t('Edit meta tags'),
        '#limit_validation_errors' => [],
        '#submit' => [[static::class, 'loadSubmit']],
        '#ajax' => [
          'callback' => [static::class, 'loadAjax'],
          'wrapper' => $id,
        ],
        '#attributes' => ['class' => ['neo-metatag__edit']],
      ];
      return $element;
    }

    // Same name as the button that built the form, so focus goes back to it.
    $element['widget']['edit'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Edit meta tags'),
      '#attributes' => [
        'type' => 'button',
        'name' => $button_name,
        'class' => ['button', 'btn', 'neo-metatag__edit'],
        'data-neo-metatag-open' => 'true',
      ],
    ];

    $firehose = parent::formElement($items, $delta, [], $form, $form_state);
    $triggering = $form_state->getTriggeringElement();
    $element['widget']['shelf'] = [
      '#type' => 'container',
      '#parents' => $parents,
      '#attributes' => ['class' => ['neo-metatag__shelf']],
      '#neo_shelf' => [
        'key' => 'metatag|' . implode('|', $parents),
        'title' => $this->t('Edit meta tags'),
        'hint' => ($root = $this->getRootLabel($form_state))
          ? $this->t('Changes are saved with the @label.', ['@label' => $root])
          : $this->t('Changes are saved with this form.'),
        'confirm' => $this->t('Discard your changes to the meta tags?'),
        'return' => [$button_name],
        'owner' => $id,
        // Open when just built; afterwards the page opens it.
        'open' => ($triggering['#name'] ?? NULL) === $button_name,
      ],
      'actions' => [
        '#type' => 'container',
        'done' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Done'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['button', 'button--primary', 'btn', 'btn-md'],
            'data-neo-shelf-primary' => 'true',
            'data-neo-metatag-done' => 'true',
          ],
        ],
        'cancel' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Cancel'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['button', 'btn', 'btn-md'],
            'data-neo-shelf-cancel' => 'true',
            'data-neo-metatag-cancel' => 'true',
          ],
        ],
      ],
    ];
    foreach (Element::children($firehose) as $key) {
      // The firehose's "Configure the meta tags below." says what the shelf
      // heading already does.
      if ($key === 'preamble') {
        continue;
      }
      $child = $firehose[$key];
      if (($child['#type'] ?? NULL) === 'details') {
        // Open what an editor came for: the basics, and whatever this entity
        // already overrides. The firehose opens every group with a value,
        // which is most of them once the defaults fill in.
        $child['#open'] = $key === 'basic'
          || (bool) array_intersect(Element::children($child), array_keys($overrides));
      }
      $element['widget']['shelf'][$key] = $child;
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * A form never built has nothing to read, so the field keeps its values.
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    $state = static::getWidgetState($form['#parents'], $this->fieldDefinition->getName(), $form_state);
    if (empty($state['neo_metatag_loaded'])) {
      return;
    }
    parent::extractFormValues($items, $form, $form_state);
  }

  /**
   * Button #submit callback: builds the meta tags form.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function loadSubmit(array $form, FormStateInterface $form_state): void {
    // The button sits at [...field parents, field name, delta, 'edit'].
    $path = $form_state->getTriggeringElement()['#parents'];
    $parents = array_slice($path, 0, -3);
    $field_name = $path[count($path) - 3];
    $state = static::getWidgetState($parents, $field_name, $form_state);
    $state['neo_metatag_loaded'] = TRUE;
    static::setWidgetState($parents, $field_name, $form_state, $state);
    $form_state->setRebuild();
  }

  /**
   * Button #ajax callback: the rebuilt widget, holding the open shelf.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The widget container.
   */
  public static function loadAjax(array $form, FormStateInterface $form_state): array {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1)) ?? [];
  }

  /**
   * The tags a field item overrides, by tag id.
   *
   * Metatag stores only the tags that differ from the defaults.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The item's delta.
   */
  protected function getOverrides(FieldItemListInterface $items, int $delta): array {
    $value = $items[$delta]->value ?? NULL;
    if (empty($value)) {
      return [];
    }
    $tags = metatag_data_decode($value);
    return is_array($tags) ? array_filter($tags, fn ($tag) => $tag !== '' && $tag !== NULL && $tag !== []) : [];
  }

  /**
   * The lowercase name of the entity the whole form saves.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function getRootLabel(FormStateInterface $form_state): ?string {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return NULL;
    }
    $entity = $form_object->getEntity();
    $type = $entity->getEntityType();
    $label = $type->getSingularLabel();
    if ($type->getBundleEntityType()) {
      $info = $this->bundleInfo->getBundleInfo($type->id());
      $label = $info[$entity->bundle()]['label'] ?? $label;
    }
    return mb_strtolower((string) $label);
  }

}
