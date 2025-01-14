<?php

namespace Drupal\neo;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Provides a helper to for nesting entity forms.
 *
 * @internal
 */
trait NeoNestedEntityFormTrait {

  /**
   * The inner form state key.
   *
   * @var string
   */
  protected static $innerFormStateKey = 'inner_form_state';

  /**
   * The main submit button.
   *
   * @var string
   */
  protected static $mainSubmitButton = 'submit';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Inner forms.
   *
   * @var \Drupal\Core\Entity\EntityFormInterface[]
   */
  protected $innerForms = [];

  /**
   * Create an inner form.
   */
  protected function createInnerForm($parents, $entity_type_id, $bundle_id = NULL, $form_handler = 'add', $data = []) {
    $key = implode(':', $parents);
    if (isset($this->innerForms[$key])) {
      return $this->innerForms[$key];
    }
    if ($data instanceof EntityInterface) {
      $entity = $data;
    }
    else {
      $entity_type = $this->getEntityTypeManager()->getDefinition($entity_type_id);
      if ($bundle_key = $entity_type->getKey('bundle')) {
        $data[$bundle_key] = $bundle_id;
      }
      $entity = $this->getEntityTypeManager()->getStorage($entity_type_id)->create($data);
    }
    $innerForm = $this->getEntityTypeManager()->getFormObject($entity->getEntityTypeId(), $form_handler)->setEntity($entity);
    $this->innerForms[$key] = $this->getEntityTypeManager()->getFormObject($entity->getEntityTypeId(), $form_handler)->setEntity($entity);
    if ($innerForm instanceof NeoNestedEntityFormInterface) {
      $innerForm->setInnerFormKey($key);
      $innerForm->setInnerFormParents($key, $parents);
      $this->innerForms[$key] = $innerForm;
      return $this->getInnerForm($parents);
    }
    return NULL;
  }

  /**
   * Get an inner form.
   */
  protected function getInnerForm($parents) {
    $key = implode(':', $parents);
    return $this->innerForms[$key] ?? NULL;
  }

  /**
   * Create an inner form.
   */
  protected function buildInnerForm(EntityFormInterface $entity_form, $form_state, array &$complete_form) {
    $parents = $entity_form->innerFormParents;
    $inner_form = [
      '#parents' => $parents,
    ];
    $inner_form_state = static::createInnerFormState($entity_form, $form_state);
    $inner_form = $entity_form->buildForm($inner_form, $inner_form_state);
    $inner_form['#type'] = 'container';
    if (!isset($complete_form['#process'])) {
      $complete_form['#process'] = [];
    }
    if (!in_array('::processInnerForms', $complete_form['#process'])) {
      $complete_form['#process'][] = '::processInnerForms';
    }
    unset($inner_form['form_token']);
    // The process array is called from the FormBuilder::doBuildForm method
    // with the form_state object assigned to the this (ComboForm) object.
    // This results in a compatibility issues because these methods should
    // be called on the inner forms (with their assigned FormStates).
    // To resolve this we move the process array in the inner_form_state
    // object.
    if (!empty($inner_form['#process'])) {
      $inner_form_state->set('#process', $inner_form['#process']);
      unset($inner_form['#process']);
    }
    else {
      $inner_form_state->set('#process', []);
    }
    // The actions array causes a UX problem because there should only be a
    // single save button and not multiple.
    // The current solution is to move the #submit callbacks of the submit
    // element to the inner form element root.
    if (!empty($inner_form['actions'])) {
      if (isset($inner_form['actions'][static::$mainSubmitButton])) {
        $inner_form['#submit'] = $inner_form['actions'][static::$mainSubmitButton]['#submit'];
      }
      unset($inner_form['actions']);
    }
    if (function_exists('field_group_form_alter')) {
      field_group_form_alter($inner_form, $inner_form_state);
    }
    return $inner_form;
  }

  /**
   * Process form.
   *
   * This method will be called from FormBuilder::doBuildForm during the process
   * stage.
   * In here we call the #process callbacks that were previously removed.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The altered form element.
   *
   * @see \Drupal\Core\Form\FormBuilder::doBuildForm()
   */
  public function processInnerForms(array $element, FormStateInterface &$form_state, array &$complete_form) {
    foreach ($this->innerForms as $key => $entity_form) {
      $inner_form_state = static::getInnerFormState($entity_form->innerFormParents, $form_state);
      foreach ($inner_form_state->get('#process') as $callback) {
        // The callback format was copied from FormBuilder::doBuildForm().
        $element[$key]['form'] = call_user_func_array($inner_form_state->prepareCallback($callback), [
          &$element[$key]['form'],
          &$inner_form_state,
          &$complete_form,
        ]);
        $this->processRecursiveInnerForms($element[$key]['form'], $key);
      }
    }
    return $element;
  }

  /**
   * Nested process form.
   */
  public function processNestedInnerForms(array &$element, FormStateInterface &$form_state, array &$complete_form) {
    $this->processRecursiveInnerForms($element, $element['#inner_form_parents']);
    return $element;
  }

  /**
   * Find any nested submit buttons.
   */
  public function processRecursiveInnerForms(&$element, $inner_form_parents) {
    if (is_array($element)) {
      if (isset($element['#ajax']) && !isset($element['#inner_form_parents'])) {
        $element['#inner_form_parents'] = $inner_form_parents;
        $element['#inner_form_submit'] = !empty($element['#submit']) ? $element['#submit'] : [];
        $element['#submit'] = ['::inlineButtonSubmit'];
        if (!empty($element['#validate'])) {
          $element['#inner_form_validate'] = $element['#validate'];
          $element['#validate'] = '::inlineButtonValidate';
        }
      }
      if (!empty($element['#process'])) {
        $element['#inner_form_parents'] = $inner_form_parents;
        $element['#process'][] = '::processNestedInnerForms';
      }
      foreach (Element::children($element) as $key) {
        $this->processRecursiveInnerForms($element[$key], $inner_form_parents);
      }
    }
  }

  /**
   * Make sure we use the correct form state.
   */
  public static function inlineButtonValidate(array $element, FormStateInterface $form_state) {
    \Drupal::messenger()->addWarning('Site Settings need validation.');
  }

  /**
   * Make sure we use the correct form state.
   */
  public static function inlineButtonSubmit(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (isset($trigger['#inner_form_parents']) && isset($trigger['#inner_form_submit'])) {
      $inner_form_state = static::getInnerFormState($trigger['#inner_form_parents'], $form_state);
      /** @var \Drupal\Core\Form\FormSubmitterInterface $form_submitter */
      $form_submitter = \Drupal::service('form_submitter');
      $inner_form_state->setSubmitted();
      $inner_form_state->setSubmitHandlers($trigger['#inner_form_submit']);
      $form_submitter->doSubmitForm($form, $inner_form_state);
      $form_state->setRebuild($inner_form_state->isRebuilding());
      $inner_form_state->setSubmitHandlers([]);

      // Merge in user input changes as submit handler may have altered them.
      $user_input = $form_state->getUserInput();
      NestedArray::setValue($user_input, $trigger['#inner_form_parents'], NestedArray::getValue($inner_form_state->getUserInput(), $trigger['#inner_form_parents']) ?? []);
      $form_state->setUserInput($user_input);
    }
  }

  /**
   * Validate inner forms.
   */
  public function validateInnerForm(array $form, FormStateInterface $form_state) {
    if ($entity_form = $this->getInnerForm($form['#parents'])) {
      /** @var \Drupal\Core\Form\FormValidatorInterface $form_validator */
      $form_validator = \Drupal::service('form_validator');
      $inner_form_state = static::getInnerFormState($form['#parents'], $form_state);
      $inner_form_state->setValidationComplete(FALSE);
      $inner_form_state->clearErrors();
      // $inner_form_state->setTemporaryValue('entity_validated', FALSE);
      // Pass through both the form elements validation and the form object
      // validation.
      $entity_form->validateForm($form, $inner_form_state);

      // Build Entity.
      $this->buildInnerEntity($form, $form_state);

      $form_validator->validateForm($entity_form->getFormId(), $form, $inner_form_state);
      foreach ($inner_form_state->getErrors() as $error_element_path => $error) {
        $form_state->setErrorByName($error_element_path, $error);
      }
    }
  }

  /**
   * Build inner entity.
   */
  public function buildInnerEntity(array $form, FormStateInterface $form_state) {
    if ($entity_form = $this->getInnerForm($form['#parents'])) {
      $inner_form_state = static::getInnerFormState($form['#parents'], $form_state);

      // Build Entity.
      $entity = $entity_form->buildEntity($form, $inner_form_state);
      $entity_form->setEntity($entity);
    }
  }

  /**
   * Submit inner forms.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity if saved successfully.
   */
  public function submitInnerForm(array $form, FormStateInterface $form_state) {
    if ($entity_form = $this->getInnerForm($form['#parents'])) {
      /** @var \Drupal\Core\Form\FormSubmitterInterface $form_submitter */
      $form_submitter = \Drupal::service('form_submitter');
      $inner_form_state = static::getInnerFormState($form['#parents'], $form_state);
      // The form state needs to be set as submitted before executing the
      // doSubmitForm method.
      $inner_form_state->setSubmitted();
      $form_submitter->doSubmitForm($form, $inner_form_state);
      return $entity_form->getEntity();
    }
    return NULL;
  }

  /**
   * Get inner form entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity if saved built.
   */
  protected function getInnerEntity(array $form, FormStateInterface $form_state) {
    if ($entity_form = $this->getInnerForm($form['#parents'])) {
      return $entity_form->getEntity();
    }
    return NULL;
  }

  /**
   * Get an inner form state.
   *
   * Before returning the innerFormState object, we need to set the
   * complete_form, values and user_input properties from the main form state.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The inner form state.
   */
  protected static function getInnerFormState(array $parents, FormStateInterface $form_state) {
    $key = implode(':', $parents);
    /** @var \Drupal\Core\Form\FormStateInterface $inner_form_state */
    $inner_form_state = $form_state->get([static::$innerFormStateKey, $key]);
    if ($complete_form = $form_state->getCompleteForm()) {
      $inner_form_state->setCompleteForm($complete_form);
    }
    $inner_form_state->setValues($form_state->getValues() ? $form_state->getValues() : []);
    $inner_form_state->setUserInput($form_state->getUserInput() ? $form_state->getUserInput() : []);
    $inner_form_state->setRebuild($form_state->isRebuilding());
    $inner_form_state->setRebuildInfo($form_state->getRebuildInfo());
    $inner_form_state->setTriggeringElement($form_state->getTriggeringElement());
    $inner_form_state->setLimitValidationErrors($form_state->getLimitValidationErrors());
    $inner_form_state->set('field_storage', $inner_form_state->get('field_storage') ?? $form_state->get('field_storage'));
    $inner_form_state->set('inner_form_parents', $parents);
    $inner_form_state->set('inner_form_key', $key);

    // Inline entity form support.
    $inner_form_state->set('inline_entity_form', NestedArray::mergeDeep($inner_form_state->get('inline_entity_form') ?? [], $form_state->get('inline_entity_form') ?? []));
    $form_state->set('inline_entity_form', $inner_form_state->get('inline_entity_form'));

    return $inner_form_state;
  }

  /**
   * Create an inner form state.
   *
   * After the initialization of the inner form state, we need to assign it with
   * the inner form object and set it inside the main form state.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The inner form state.
   */
  protected static function createInnerFormState(EntityFormInterface $entity_form, $form_state) {
    $key = $entity_form->innerFormKey;
    if (!$form_state->get([static::$innerFormStateKey, $key])) {
      $inner_form_state = new FormState();
      $inner_form_state->setFormObject($entity_form);
      $form_state->set([static::$innerFormStateKey, $key], $inner_form_state);
    }
    return static::getInnerFormState($entity_form->innerFormParents, $form_state);
  }

  /**
   * Gets the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function getEntityTypeManager() {
    if (!$this->entityTypeManager) {
      $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }

    return $this->entityTypeManager;
  }

}
