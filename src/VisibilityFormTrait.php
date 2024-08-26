<?php

namespace Drupal\neo;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;

/**
 * Provides visibility form helpers.
 */
trait VisibilityFormTrait {

  /**
   * The condition plugin manager.
   *
   * @var \Drupal\Core\Condition\ConditionManager
   */
  protected $conditionPluginManager;

  /**
   * The context repository service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  protected $contextRepository;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Prepare form for visibility functionality.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function prepareVisibility(array $form, FormStateInterface $form_state) {
    // Store the gathered contexts in the form state for other objects to use
    // during form building.
    $form_state->setTemporaryValue('gathered_contexts', $this->contextRepository()->getAvailableContexts());
  }

  /**
   * Helper function for building the visibility UI form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $title
   *   The section title.
   *
   * @return array
   *   The form array with the visibility UI added in.
   */
  protected function buildVisibility(array $form, FormStateInterface $form_state, $title = 'Visibility') {
    $this->prepareVisibility($form, $form_state);
    $form['#tree'] = TRUE;
    $form['visibility_tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t($title), // phpcs:ignore
      '#parents' => ['visibility_tabs'],
      '#attached' => [
        'library' => [
          'neo/visibility',
        ],
      ],
    ];
    // @todo Allow list of conditions to be configured in
    //   https://www.drupal.org/node/2284687.
    $visibility = $this->entity->getVisibility();
    $definitions = $this->conditionPluginManager()->getFilteredDefinitions('neo', $form_state->getTemporaryValue('gathered_contexts'), [$this->entity->getEntityTypeId() => $this->entity]);
    foreach ($definitions as $conditionId => $definition) {
      // Don't display the current theme condition.
      if ($conditionId == 'current_theme') {
        continue;
      }
      // Don't display the language condition until we have multiple languages.
      if (isset($this->language) && $conditionId == 'language' && !$this->language->isMultilingual()) {
        continue;
      }
      /** @var \Drupal\Core\Condition\ConditionInterface $condition */
      $condition = $this->conditionPluginManager()->createInstance($conditionId, $visibility[$conditionId] ?? []);
      $form_state->set(['conditions', $conditionId], $condition);
      $conditionForm = $condition->buildConfigurationForm([], $form_state);
      $conditionForm['#type'] = 'details';
      $conditionForm['#title'] = $condition->getPluginDefinition()['label'];
      $conditionForm['#group'] = 'visibility_tabs';
      $form[$conditionId] = $conditionForm;
    }

    if (isset($form['node_type'])) {
      $form['node_type']['#title'] = $this->t('Content types');
      $form['node_type']['bundles']['#title'] = $this->t('Content types');
      $form['node_type']['negate']['#type'] = 'value';
      $form['node_type']['negate']['#title_display'] = 'invisible';
      $form['node_type']['negate']['#value'] = $form['node_type']['negate']['#default_value'];
    }
    if (isset($form['user_role'])) {
      $form['user_role']['#title'] = $this->t('Roles');
      unset($form['user_role']['roles']['#description']);
      $form['user_role']['negate']['#type'] = 'value';
      $form['user_role']['negate']['#value'] = $form['user_role']['negate']['#default_value'];
    }
    if (isset($form['request_path'])) {
      $form['request_path']['#title'] = $this->t('Pages');
      $form['request_path']['negate']['#type'] = 'radios';
      $form['request_path']['negate']['#default_value'] = (int) $form['request_path']['negate']['#default_value'];
      $form['request_path']['negate']['#title_display'] = 'invisible';
      $form['request_path']['negate']['#options'] = [
        $this->t('Show for the listed pages'),
        $this->t('Hide for the listed pages'),
      ];
    }
    if (isset($form['language'])) {
      $form['language']['negate']['#type'] = 'value';
      $form['language']['negate']['#value'] = $form['language']['negate']['#default_value'];
    }
    return $form;
  }

  /**
   * Helper function to independently validate the visibility UI.
   *
   * @param array $form
   *   A nested array form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function validateVisibility(array $form, FormStateInterface $form_state) {
    // Validate visibility condition settings.
    foreach ($form_state->getValue('visibility') as $conditionId => $values) {
      // All condition plugins use 'negate' as a Boolean in their schema.
      // However, certain form elements may return it as 0/1. Cast here to
      // ensure the data is in the expected type.
      if (array_key_exists('negate', $values)) {
        $form_state->setValue(['visibility', $conditionId, 'negate'], (bool) $values['negate']);
      }

      // Allow the condition to validate the form.
      $condition = $form_state->get(['conditions', $conditionId]);
      $condition->validateConfigurationForm($form['visibility'][$conditionId], SubformState::createForSubform($form['visibility'][$conditionId], $form, $form_state));
    }
  }

  /**
   * Helper function to independently submit the visibility UI.
   *
   * @param array $form
   *   A nested array form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function submitVisibility(array $form, FormStateInterface $form_state) {
    foreach ($form_state->getValue('visibility') as $conditionId => $values) {
      // Allow the condition to submit the form.
      $condition = $form_state->get(['conditions', $conditionId]);
      $condition->submitConfigurationForm($form['visibility'][$conditionId], SubformState::createForSubform($form['visibility'][$conditionId], $form, $form_state));

      $condition_configuration = $condition->getConfiguration();
      // Update the visibility conditions on the block.
      $this->entity->getVisibilityConditions()->addInstanceId($conditionId, $condition_configuration);
    }
  }

  /**
   * Gets the condition plugin manager.
   *
   * @return \Drupal\Core\Condition\ConditionManager
   *   The condition plugin manager.
   */
  protected function conditionPluginManager() {
    if (!isset($this->conditionPluginManager)) {
      $this->conditionPluginManager = \Drupal::service('plugin.manager.condition');
    }
    return $this->conditionPluginManager;
  }

  /**
   * Gets the context repository service.
   *
   * @return \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   *   The context repository service.
   */
  protected function contextRepository() {
    if (!isset($this->contextRepository)) {
      $this->contextRepository = \Drupal::service('context.repository');
    }
    return $this->contextRepository;
  }

  /**
   * Gets the context repository service.
   *
   * @return \Drupal\Core\Language\LanguageManagerInterface
   *   The language manager.
   */
  protected function languageManager() {
    if (!isset($this->languageManager)) {
      $this->languageManager = \Drupal::service('language_manager');
    }
    return $this->languageManager;
  }

}
