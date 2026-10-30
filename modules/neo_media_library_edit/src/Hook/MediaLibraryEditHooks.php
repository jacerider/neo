<?php

declare(strict_types=1);

namespace Drupal\neo_media_library_edit\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryState;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Edit a selected media item from the media library widget, in a modal.
 *
 * A stand-in for core issue #2985168, mirroring its merge request !4517: an
 * `edit_button` on each selected item opens the media item's default edit form
 * in a modal, and saving it re-renders the widget through the widget's own
 * hidden update button. Uninstall this module once core provides the button.
 *
 * Where it departs from the merge request, nothing an editor sees changes:
 * - The button is a link, not a `#type submit`. Core's submit button carries
 *   `use-ajax`, so Drupal.ajax finds its form and posts the whole host form to
 *   the media edit URL on every click; a link opens the modal with a plain GET,
 *   degrades to the edit page without JavaScript, and cannot become the host
 *   form's triggering element.
 * - The edit URL carries the media library state already on `open_button`,
 *   rather than a state rebuilt with an extra `form_mode` opener parameter, so
 *   an opener swapped in by another module (neo_alchemist) travels with it.
 * - The edit form opens with a notice that the change applies everywhere the
 *   media item is used.
 * - Core's `MediaLibraryWidgetEditItem` behavior, dead code inherited from the
 *   media_library_edit contrib module, is not copied.
 *
 * Should core ship the button while this module is still installed, the module
 * steps aside: an item that already has an `edit_button` gets nothing more,
 * a media form whose Save core has already wired is left to core, and the
 * status report says this module can be uninstalled.
 *
 * @see https://www.drupal.org/project/drupal/issues/2985168
 */
class MediaLibraryEditHooks {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The attribute marking an edit button as this module's, not core's.
   */
  public const MARKER = 'data-neo-media-library-edit';

  /**
   * The state key recording that core's own edit button has been seen.
   *
   * Set from the widget alter, the one place core's button can be observed,
   * so the status report can say so without building a widget itself.
   */
  public const CORE_DETECTED = 'neo_media_library_edit.core_detected';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly MessengerInterface $messenger,
    protected readonly RequestStack $requestStack,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   *
   * Runs last, so the state read from `open_button` is the one the media
   * library will open with, after any other module has swapped its opener.
   */
  #[Hook('field_widget_single_element_media_library_widget_form_alter', order: Order::Last)]
  public function widgetAlter(array &$element, FormStateInterface $form_state, array $context): void {
    $state = $element['open_button']['#media_library_state'] ?? NULL;
    if (!$state instanceof MediaLibraryState || empty($element['selection'])) {
      return;
    }

    $ids = [];
    foreach (Element::children($element['selection']) as $delta) {
      $item = $element['selection'][$delta];
      if (isset($item['edit_button'])) {
        // Core, or something else, already put a button here.
        $this->recordCoreDetected();
      }
      elseif (isset($item['target_id']['#value'])) {
        $ids[$delta] = $item['target_id']['#value'];
      }
    }
    if (!$ids) {
      return;
    }

    $media_items = $this->entityTypeManager->getStorage('media')->loadMultiple($ids);
    foreach ($ids as $delta => $id) {
      $media_item = $media_items[$id] ?? NULL;
      if (!$media_item instanceof MediaInterface) {
        continue;
      }
      $element['selection'][$delta]['remove_button']['#weight'] = -10;
      $element['selection'][$delta]['edit_button'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => $media_item->toUrl('edit-form', ['query' => $state->all()]),
        '#weight' => 1,
        // An access result rather than a boolean, so its cacheability reaches
        // the render array.
        '#access' => $media_item->access('update', NULL, TRUE),
        '#attributes' => [
          'aria-label' => $media_item->access('view label') ? $this->t('Edit @label', ['@label' => $media_item->label()]) : $this->t('Edit media'),
          'class' => ['edit-media', 'use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode(['width' => '80%']),
          static::MARKER => '',
        ],
      ];
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for media_form.
   *
   * Makes the media edit form work inside the modal the edit button opens:
   * Save submits over AJAX, and every other action is hidden. Runs last, so a
   * Save that core's own alter has already wired is recognised and left alone.
   */
  #[Hook('form_media_form_alter', order: Order::Last)]
  public function formMediaFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface || $form_object->getOperation() !== 'edit') {
      return;
    }

    $state = $this->stateFromRequest();
    if (!$state || $state->getOpenerId() !== 'media_library.opener.field_widget') {
      return;
    }

    $callback = $form['actions']['submit']['#ajax']['callback'] ?? NULL;
    if ($callback && !(is_array($callback) && ($callback[0] ?? NULL) instanceof self)) {
      $this->recordCoreDetected();
      return;
    }

    $wrapper_format = $this->requestStack->getCurrentRequest()->query->get(MainContentViewSubscriber::WRAPPER_FORMAT);
    if ($wrapper_format !== 'drupal_modal' && $wrapper_format !== 'drupal_ajax') {
      return;
    }

    foreach (Element::children($form['actions']) as $key) {
      if ($key === 'submit') {
        $form['actions'][$key]['#ajax'] = [
          'callback' => [$this, 'mediaEditFormAjaxCallback'],
        ];
        // Keep the form that opened the modal from refocusing the Save button.
        $form['actions'][$key]['#attributes']['data-disable-refocus'] = 'true';
        // The data-drupal-selector must stay the same across AJAX requests,
        // which \Drupal\Core\Form\FormBuilder only allows when #id does too.
        // This form only ever occurs once on a page.
        // @todo Remove once https://www.drupal.org/node/2897377 is fixed.
        $form['#id'] = Html::getId($form_state->getBuildInfo()['form_id']);
      }
      else {
        $form['actions'][$key]['#access'] = FALSE;
      }
    }

    $form['neo_media_library_edit_notice'] = [
      '#theme' => 'status_messages',
      '#message_list' => [
        'warning' => [
          $this->t('Changes to this media item apply everywhere it is used.'),
        ],
      ],
      '#status_headings' => [
        'warning' => $this->t('Warning message'),
      ],
      '#weight' => -1000,
    ];
  }

  /**
   * AJAX callback for the media edit form inside the modal.
   *
   * @internal
   */
  public function mediaEditFormAjaxCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
      return $response;
    }

    $field_widget_id = $this->stateFromRequest()?->getOpenerParameters()['field_widget_id'] ?? NULL;
    if (!$field_widget_id) {
      return $response;
    }

    // Trigger the field widget to update itself, and close the dialog.
    $response->addCommand(new InvokeCommand("[data-media-library-widget-update=\"$field_widget_id\"]", 'trigger', ['mousedown']));
    $response->addCommand(new CloseModalDialogCommand());
    $this->messenger->deleteByType(MessengerInterface::TYPE_STATUS);

    return $response;
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    if (!$this->state->get(static::CORE_DETECTED)) {
      return [];
    }
    return [
      'neo_media_library_edit' => [
        'title' => $this->t('Neo | Media Library Edit'),
        'value' => $this->t('No longer needed'),
        'description' => $this->t('Drupal core now provides an Edit button for media selected in the media library widget, so this module adds nothing. Uninstall <em>Neo | Media Library Edit</em>.'),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

  /**
   * Records that core's own edit button has been seen.
   */
  protected function recordCoreDetected(): void {
    if (!$this->state->get(static::CORE_DETECTED)) {
      $this->state->set(static::CORE_DETECTED, TRUE);
    }
  }

  /**
   * The media library state carried by the current request, if valid.
   */
  protected function stateFromRequest(): ?MediaLibraryState {
    try {
      return MediaLibraryState::fromRequest($this->requestStack->getCurrentRequest());
    }
    catch (BadRequestHttpException | \InvalidArgumentException) {
      return NULL;
    }
  }

}
