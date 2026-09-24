<?php

declare(strict_types=1);

namespace Drupal\neo_inline_entity_form\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex;
use Drupal\neo_inline_entity_form\ShelfPreRender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The complex inline entity form, with its forms opened in side panels.
 *
 * IEF opens an edit form in a table row under its entity, and lets any number
 * of rows be open at once, at every level of nesting. This widget presents
 * each open form as a shelf instead: a panel fixed to the right edge over a
 * dimmed page, with a nested form stacking a narrower panel on top. Each shelf
 * has a heading, a scrolling body and a footer holding the form's buttons.
 *
 * The shelf is presentation only. An IEF form is part of its parent form and
 * cannot leave it, so nothing here moves a form element at build time:
 * ElementSubmit::trigger() finds the entity form from its button's
 * #array_parents, and a moved button would close the row without applying
 * it. The markup is split into heading, body and footer at render time by
 * \Drupal\neo_inline_entity_form\ShelfPreRender, and the panel stays where IEF
 * rendered it, inside the form. The behavior in src/js/shelf.ts does the rest.
 *
 * Also marks rows that are changed but not yet saved, says that changes are
 * saved with the parent form, and can give each addable type its own button.
 */
#[FieldWidget(
  id: 'neo_inline_entity_form_complex',
  label: new TranslatableMarkup('Neo | Inline entity form - Complex'),
  field_types: [
    'entity_reference',
    'entity_reference_revisions',
  ],
  multiple_values: TRUE,
)]
class NeoInlineEntityFormComplex extends InlineEntityFormComplex {

  /**
   * The element info manager.
   *
   * @var \Drupal\Core\Render\ElementInfoManagerInterface
   */
  protected ElementInfoManagerInterface $elementInfo;

  /**
   * The label of the entity the whole form saves, set per formElement() call.
   *
   * Used in "saved with the restaurant" wording. NULL when the form is not an
   * entity form.
   *
   * @var string|null
   */
  protected ?string $rootLabel = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->elementInfo = $container->get('element_info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'presentation' => 'shelf',
      'bundle_buttons' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);
    $states_prefix = 'fields[' . $this->fieldDefinition->getName()
      . '][settings_edit_form][settings]';

    $element['presentation'] = [
      '#type' => 'radios',
      '#title' => $this->t('Open edit and add forms'),
      '#options' => [
        'shelf' => $this->t('In a side panel'),
        'inline' => $this->t('Inline, in the table'),
      ],
      '#default_value' => $this->getSetting('presentation'),
    ];
    $element['bundle_buttons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Give each type its own add button'),
      '#description' => $this->t('Replaces the type select list when more than one type can be added.'),
      '#default_value' => $this->getSetting('bundle_buttons'),
      '#states' => [
        'visible' => [
          ':input[name="' . $states_prefix . '[allow_new]"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->isShelf()
      ? $this->t('Forms open in a side panel.')
      : $this->t('Forms open inline.');
    if ($this->getSetting('allow_new') && $this->getSetting('bundle_buttons')) {
      $summary[] = $this->t('Each type has its own add button.');
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Set before the parent builds, because it calls buildRemoveForm().
    $this->rootLabel = $this->getRootLabel($form_state);
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    $ief_id = $this->getIefId();
    $state = $form_state->get(['inline_entity_form', $ief_id]) ?? [];
    $entities = $state['entities'] ?? [];
    $new_key = $entities ? max(array_keys($entities)) + 1 : 0;
    $shelf = $this->isShelf();

    $element['#attached']['library'][] = 'neo_inline_entity_form/widget';
    $element['#attributes']['class'][] = 'neo-ief';
    $element['entities']['#neo_ief'] = TRUE;

    $pending = !empty($state['delete']);
    foreach (Element::children($element['entities']) as $key) {
      $row = &$element['entities'][$key];
      // A row is unsaved when it was changed, or when something in a form
      // nested inside it was: a section whose entries were edited is saved
      // with the parent form just the same.
      $row['#neo_unsaved'] = !empty($entities[$key]['needs_save'])
        || static::hasPendingChildren($form_state, $ief_id . '-' . $key . '-');
      $pending = $pending || $row['#neo_unsaved'];

      if (!$shelf) {
        continue;
      }
      if (isset($row['actions'])) {
        foreach (Element::children($row['actions']) as $name) {
          $row['actions'][$name]['#ajax']['disable-refocus'] = TRUE;
        }
      }
      $op = $entities[$key]['form'] ?? NULL;
      if (in_array($op, ['edit', 'duplicate'], TRUE) && isset($row['form']['inline_entity_form'])) {
        $return = ['ief-' . $ief_id . '-entity-edit-' . $key];
        if ($op === 'duplicate') {
          array_unshift($return, 'ief-' . $ief_id . '-entity-duplicate-' . $key);
        }
        $this->prepareShelf($row['form'], $op, (int) $key, $entities[$key]['entity'], $return);
      }
    }
    unset($row);

    // Always present, so the behavior can tell whether a nested widget holds
    // work that closing its parent's add form would throw away.
    $element['#attributes']['data-neo-ief-pending'] = $pending ? 'true' : 'false';
    if ($pending) {
      $element['neo_ief_note'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->rootLabel
          ? $this->t('Changes apply when you save the @label.', ['@label' => $this->rootLabel])
          : $this->t('Changes apply when you save this form.'),
        '#attributes' => ['class' => ['neo-ief-note']],
        // Just above IEF's add buttons, at 100.
        '#weight' => 99,
      ];
    }

    if ($this->getSetting('bundle_buttons')) {
      $this->buildBundleButtons($element);
    }

    if ($shelf) {
      if (isset($element['actions'])) {
        foreach (Element::children($element['actions']) as $name) {
          $button = &$element['actions'][$name];
          if (($button['#type'] ?? NULL) === 'submit') {
            $button['#ajax']['disable-refocus'] = TRUE;
            // A nested widget's add buttons sit in the parent's table cell,
            // where they would be drawn as a row's small outline buttons.
            // See markShelfButton().
            $button['#attributes']['class'][] = 'btn-md';
          }
          unset($button);
        }
      }

      // A form IEF opened on its own, for a required field with nothing in
      // it, has no Cancel. As a shelf it would have no way out, so it stays
      // inline.
      if (isset($element['form']) && !static::isPinned($element['form'])) {
        $op = $state['form'] === 'ief_add_existing' ? 'ief_add_existing' : 'add';
        $bundle = $state['form settings']['bundle'] ?? NULL;
        // Once done, the new row's Edit button; once cancelled, the button
        // that opened the form, whichever of IEF's or ours that was.
        $prefix = 'ief-' . $ief_id;
        $return = [$prefix . '-entity-edit-' . $new_key];
        if ($op === 'ief_add_existing') {
          $return[] = $prefix . '-add-existing';
        }
        elseif ($bundle) {
          $return[] = $prefix . '-add-bundle-' . $bundle;
        }
        $return[] = $prefix . '-add';
        $this->prepareShelf($element['form'], $op, $new_key, NULL, $return);
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * In a shelf, the save button becomes the panel's primary "Done" and both
   * buttons get the data attributes the behavior presses them by. Names and
   * submit handlers are IEF's, so which button was pressed still resolves.
   */
  public static function buildEntityFormActions(array $element) {
    $element = parent::buildEntityFormActions($element);
    if (empty($element['#neo_ief_shelf'])) {
      return $element;
    }

    $op = $element['#op'];
    $save = &$element['actions']['ief_' . $op . '_save'];
    $save['#value'] = new TranslatableMarkup('Done');
    static::markShelfButton($save, 'primary');
    $cancel = &$element['actions']['ief_' . $op . '_cancel'];
    static::markShelfButton($cancel, 'cancel');
    if ($op === 'add') {
      $cancel['#submit'][] = [static::class, 'discardAbandonedChildren'];
    }

    return $element;
  }

  /**
   * Readies a shelf footer button for the panel and for the behavior.
   *
   * The shelf sits in a table cell, and neo_back draws every button in a
   * table as a small outline button unless its classes already name a `btn-`
   * style. `btn-md` is the size a form button has anywhere else, so the
   * footer reads as a form's actions rather than as a row's.
   *
   * @param array $button
   *   The submit button.
   * @param string $role
   *   Either 'primary' or 'cancel': the data attribute the behavior finds
   *   the button by.
   */
  protected static function markShelfButton(array &$button, string $role): void {
    $button['#attributes']['class'][] = 'btn-md';
    $button['#attributes']['data-neo-ief-' . $role] = 'true';
    $button['#ajax']['disable-refocus'] = TRUE;
    if ($role === 'primary') {
      $button['#button_type'] = 'primary';
    }
  }

  /**
   * {@inheritdoc}
   *
   * The confirmation stays inline under its row: it has no fields to lose and
   * reads best next to what it removes.
   */
  protected function buildRemoveForm(array &$form) {
    parent::buildRemoveForm($form);
    $ief_id = $form['#ief_id'];
    $delta = $form['#ief_row_delta'];

    $form['#attributes']['class'][] = 'neo-ief-confirm';
    $form['#attributes']['data-neo-ief-confirm'] = $ief_id . '|' . $delta;
    $form['#attributes']['data-neo-ief-return'] = 'ief-' . $ief_id . '-entity-remove-' . $delta;

    $entity = $form['#entity'];
    if ($entity->id() && $this->getSetting('removed_reference') === self::REMOVED_DELETE) {
      $form['neo_ief_delete'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->rootLabel
          ? $this->t('It will be deleted when you save the @label.', ['@label' => $this->rootLabel])
          : $this->t('It will be deleted when you save this form.'),
        '#attributes' => ['class' => ['neo-ief-confirm__note']],
        '#weight' => 50,
      ];
    }

    $confirm = &$form['actions']['ief_remove_confirm'];
    $confirm['#button_type'] = 'danger';
    $confirm['#submit'][] = [static::class, 'forgetRemovedChildren'];
    $form['actions']['ief_remove_cancel']['#attributes']['data-neo-ief-cancel'] = 'true';
    if ($this->isShelf()) {
      $confirm['#ajax']['disable-refocus'] = TRUE;
      $form['actions']['ief_remove_cancel']['#ajax']['disable-refocus'] = TRUE;
    }
  }

  /**
   * Button #submit callback: opens the add form for the button's bundle.
   *
   * IEF only reads the bundle from its select list, which the per-type
   * buttons replace. The bundle comes from the button's own definition, which
   * was built from the bundles the user may create, so it cannot be forged.
   *
   * @param array $form
   *   The complete parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   */
  public static function openBundleForm(array $form, FormStateInterface $form_state): void {
    inline_entity_form_open_form($form, $form_state);
    $element = inline_entity_form_get_element($form, $form_state);
    $form_state->set(
      ['inline_entity_form', $element['#ief_id'], 'form settings'],
      ['bundle' => $form_state->getTriggeringElement()['#ief_bundle']],
    );
  }

  /**
   * Button #submit callback: forgets nested forms of a cancelled add form.
   *
   * A nested widget keeps its state under an id built from the add form's
   * row key. IEF leaves it behind on Cancel, so the next add form, which gets
   * the same key, shows the abandoned entries again and saves them.
   *
   * @param array $form
   *   The complete parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   */
  public static function discardAbandonedChildren(array $form, FormStateInterface $form_state): void {
    $element = inline_entity_form_get_element($form, $form_state);
    $ief_id = $element['#ief_id'];
    $entities = $form_state->get(['inline_entity_form', $ief_id, 'entities']);
    $new_key = $entities ? max(array_keys($entities)) + 1 : 0;
    static::forgetChildStates($form_state, $ief_id . '-' . $new_key . '-');
  }

  /**
   * Button #submit callback: forgets nested forms of a removed row.
   *
   * Otherwise a later add form that reuses the row key inherits them.
   *
   * @param array $form
   *   The complete parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   */
  public static function forgetRemovedChildren(array $form, FormStateInterface $form_state): void {
    $element = inline_entity_form_get_element($form, $form_state);
    $delta = $form_state->getTriggeringElement()['#ief_row_delta'];
    static::forgetChildStates($form_state, $element['#ief_id'] . '-' . $delta . '-');
  }

  /**
   * Marks an open form as a shelf.
   *
   * @param array $container
   *   The container IEF wraps the form in.
   * @param string $op
   *   One of 'edit', 'duplicate', 'add' or 'ief_add_existing'.
   * @param int $delta
   *   The row key the form edits, or the one a new entity will get.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity being edited, or NULL for a new one.
   * @param string[] $return
   *   Names of the buttons to focus once the shelf closes, in order of
   *   preference.
   */
  protected function prepareShelf(array &$container, string $op, int $delta, ?EntityInterface $entity, array $return): void {
    $labels = $this->getEntityTypeLabels();
    $bundle = $entity?->bundle() ?? ($container['inline_entity_form']['#bundle'] ?? NULL);
    $noun = $this->getNoun($op === 'ief_add_existing' ? NULL : $bundle);
    $label = $entity ? (string) $this->inlineFormHandler->getEntityLabel($entity) : '';

    $title = match ($op) {
      'edit' => $label !== ''
        ? $this->t('Edit @label', ['@label' => $label])
        : $this->t('Edit @noun', ['@noun' => $noun]),
      'duplicate' => $label !== ''
        ? $this->t('Duplicate @label', ['@label' => $label])
        : $this->t('Duplicate @noun', ['@noun' => $noun]),
      'ief_add_existing' => $this->t('Add existing @noun', ['@noun' => $noun]),
      default => $this->t('Add @noun', ['@noun' => $noun]),
    };

    // The add forms are fieldsets, which neo_back flattens into the widget.
    $container['#type'] = 'container';
    unset($container['#title']);
    $container['#attributes']['class'][] = 'neo-ief-shelf';
    $container['#attributes'] += [
      'data-neo-ief-shelf' => $this->getIefId() . '|' . $op . '|' . $delta,
      'data-neo-ief-op' => $op,
      'data-neo-ief-collection' => ucfirst((string) $labels['plural']),
      'data-neo-ief-label' => $label !== '' ? $label : (string) $this->t('New @noun', ['@noun' => $noun]),
      'data-neo-ief-noun' => $noun,
      'data-neo-ief-return' => implode(' ', array_unique($return)),
    ];
    $container['#neo_ief_shelf'] = [
      'title' => $title,
      'hint' => $this->rootLabel
        ? $this->t('Changes are saved with the @label.', ['@label' => $this->rootLabel])
        : $this->t('Changes are saved with this form.'),
    ];
    // Setting #pre_render replaces the element type's, so keep those.
    $container['#pre_render'] = array_merge(
      $this->elementInfo->getInfoProperty('container', '#pre_render', []),
      [[ShelfPreRender::class, 'preRender']],
    );

    if (isset($container['inline_entity_form'])) {
      // Read by buildEntityFormActions(), which builds the buttons later.
      $container['inline_entity_form']['#neo_ief_shelf'] = TRUE;
    }
    // The reference form's buttons already exist.
    if (isset($container['actions']['ief_reference_save'])) {
      static::markShelfButton($container['actions']['ief_reference_save'], 'primary');
      static::markShelfButton($container['actions']['ief_reference_cancel'], 'cancel');
    }
  }

  /**
   * Replaces IEF's bundle select list with one add button per bundle.
   *
   * @param array $element
   *   The widget element.
   */
  protected function buildBundleButtons(array &$element): void {
    $actions = &$element['actions'];
    if (($actions['bundle']['#type'] ?? NULL) !== 'select' || !isset($actions['ief_add'])) {
      return;
    }

    $buttons = [];
    foreach ($actions['bundle']['#options'] as $bundle => $bundle_label) {
      $button = $actions['ief_add'];
      $button['#value'] = $this->t('Add @bundle', ['@bundle' => mb_strtolower((string) $bundle_label)]);
      $button['#name'] = 'ief-' . $this->getIefId() . '-add-bundle-' . $bundle;
      $button['#ief_bundle'] = $bundle;
      $button['#submit'] = [[static::class, 'openBundleForm']];
      $buttons['ief_add_bundle_' . $bundle] = $button;
    }
    unset($actions['bundle'], $actions['ief_add']);
    // Keep the new buttons ahead of "Add existing".
    $actions = $buttons + $actions;
  }

  /**
   * Whether forms open as shelves.
   */
  protected function isShelf(): bool {
    return $this->getSetting('presentation') !== 'inline';
  }

  /**
   * The lowercase name of one entity of this field, for a bundle if known.
   *
   * @param string|null $bundle
   *   The bundle, or NULL when unknown.
   */
  protected function getNoun(?string $bundle): string {
    $labels = $this->getEntityTypeLabels();
    if ($bundle === NULL || count($this->getTargetBundles() ?? []) < 2) {
      return (string) $labels['singular'];
    }
    $info = $this->entityTypeBundleInfo->getBundleInfo($this->getFieldSetting('target_type'));
    return mb_strtolower((string) ($info[$bundle]['label'] ?? $labels['singular']));
  }

  /**
   * The lowercase name of the entity the whole form saves.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
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
      $info = $this->entityTypeBundleInfo->getBundleInfo($type->id());
      $label = $info[$entity->bundle()]['label'] ?? $label;
    }
    return mb_strtolower((string) $label);
  }

  /**
   * Whether IEF opened a form that cannot be cancelled.
   *
   * @param array $form
   *   The widget's bottom form.
   */
  protected static function isPinned(array $form): bool {
    $callbacks = array_merge(
      $form['#process'] ?? [],
      $form['inline_entity_form']['#process'] ?? [],
    );
    foreach ($callbacks as $callback) {
      if (is_array($callback) && ($callback[1] ?? NULL) === 'hideCancel') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a nested widget under a prefix holds unsaved work.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $prefix
   *   The start of the nested widgets' IEF ids.
   */
  protected static function hasPendingChildren(FormStateInterface $form_state, string $prefix): bool {
    foreach ($form_state->get('inline_entity_form') ?? [] as $id => $state) {
      if (!str_starts_with((string) $id, $prefix)) {
        continue;
      }
      if (!empty($state['delete'])) {
        return TRUE;
      }
      foreach ($state['entities'] ?? [] as $item) {
        if (!empty($item['needs_save'])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Forgets every nested widget state under a prefix.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   * @param string $prefix
   *   The start of the nested widgets' IEF ids.
   */
  protected static function forgetChildStates(FormStateInterface $form_state, string $prefix): void {
    $states = $form_state->get('inline_entity_form') ?? [];
    foreach (array_keys($states) as $id) {
      if (str_starts_with((string) $id, $prefix)) {
        unset($states[$id]);
      }
    }
    $form_state->set('inline_entity_form', $states);
  }

}
