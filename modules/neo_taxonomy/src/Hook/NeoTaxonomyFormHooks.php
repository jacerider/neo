<?php

declare(strict_types=1);

namespace Drupal\neo_taxonomy\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\filter\FilterFormatRepositoryInterface;
use Drupal\media\MediaInterface;
use Drupal\menu_ui\Form\MenuLinkEditForm;
use Drupal\menu_ui\MenuForm;
use Drupal\neo_image\NeoImageStyle;
use Drupal\taxonomy\TermForm;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy_manager\Form\TaxonomyManagerForm;
use Drupal\taxonomy_manager\Form\TaxonomyManagerTermForm;

/**
 * The sub-module's form alter hook implementations.
 *
 * The other three of the five hooks `neo_taxonomy.module` carried, split from
 * \Drupal\neo_taxonomy\Hook\NeoTaxonomyHooks the way core splits its own
 * `…Hooks` / `…FormHooks` pairs: the general form alter with its six branches —
 * the vocabulary form, the term form's weights, description format and
 * relations access, the menu form's `taxonomy_menu`-managed rows, the menu link
 * edit form's parent and weight access, and the two `taxonomy_manager` forms —
 * the vocabulary overview alter and the term overview alter. The vocabulary
 * settings helper travels with the branch that calls it, as a protected method.
 *
 * The two callbacks the forms carry are static methods here, named by their
 * `Class::method` string in the `#after_build` and `#submit` arrays that carry
 * them. No global forwarder is kept for either: nothing outside this file ever
 * named them, and both arrays are rebuilt by the alter on every request.
 *
 * Every body below is what stood in the `.module`, with one substitution: the
 * four reaches for `\Drupal::entityTypeManager()` became the constructor
 * argument, and the `t()` calls became `$this->t()`, which is core's convention
 * on a class and renders the same string — neither static needs one. Nothing
 * about what any of them decides moved with them — not the array splice that
 * inserts the settings fieldset immediately after the description element, not
 * the three weights the term form assigns, not the `NeoImageStyle` the term
 * overview builds its thumbnails through, and not the two calls to `neo_icon`'s
 * global element helpers.
 *
 * **The filter format repository is deliberately still a static call.** This
 * sub-module declares no `filter` dependency, and injecting a service from an
 * undeclared module turns a lazy per-request failure into a container-build
 * failure on any site that does not have it. That undeclared dependency set —
 * `taxonomy`, `menu_ui`, `filter`, `media`, `neo_image`, `taxonomy_manager` —
 * is a backlog finding rather than something this class resolves.
 *
 * The two `phpstan-ignore-next-line` suppressions guarding the
 * `taxonomy_manager` `instanceof` tests move with the bodies they guard and are
 * not re-judged here: `taxonomy_manager` is not installed on the site this
 * package is developed against, so nothing here can observe what happens on a
 * site that has it.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods, and because a form's
 * `#after_build` and `#submit` arrays reach the two statics by name.
 */
class NeoTaxonomyFormHooks {

  use StringTranslationTrait;

  /**
   * Constructs a NeoTaxonomyFormHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which the term form branch, the menu form
   *   branch, the `taxonomy_manager` term form branch and the vocabulary
   *   settings helper all reach for a vocabulary, a term or the term storage's
   *   hierarchy type. It is core's and is always present, so injecting it moves
   *   no failure earlier than the static calls it replaces.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    if ($form_id === 'taxonomy_vocabulary_form') {
      $this->formAlterFormTaxonomyVocabulary($form, $form_state);
    }
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof TermForm) {
      $term = $form_object->getEntity();
      /** @var \Drupal\taxonomy\VocabularyInterface $vocabulary */
      $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($term->bundle());
      $controls = $vocabulary->getThirdPartySettings('neo_taxonomy');

      $form['status']['#weight'] = 800;
      $form['advanced']['#weight'] = 900;
      $form['actions']['#weight'] = 1000;

      if (isset($form['description']) && !empty($controls['description_format'])) {
        $form['description']['widget'][0]['#format'] = $controls['description_format'];
        $form['description']['widget'][0]['#allowed_formats'] = [$controls['description_format']];
        $form['description']['widget'][0]['#after_build'][] = static::class . '::descriptionAfterBuild';
      }

      if (isset($controls['nest']) && empty($controls['nest'])) {
        $form['relations']['#access'] = FALSE;
      }
    }

    if ($form_object instanceof MenuForm) {
      if (!empty($form['links']['links'])) {
        foreach ($form['links']['links'] as $key => &$link) {
          // If the link is a taxonomy term created by the taxonomy_menu module.
          if (str_contains($key, 'menu_plugin_id:taxonomy_menu.menu_link:taxonomy_menu.menu_link.')) {
            [, $tid] = explode('.', str_replace('menu_plugin_id:taxonomy_menu.menu_link:taxonomy_menu.menu_link.', '', $key));
            /** @var \Drupal\taxonomy\TermInterface $term */
            $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
            $link['title'][] = [
              '#type' => 'markup',
              '#markup' => '<small class="pl-2">' . $this->t('Managed via Taxonomy') . '</small>',
            ];
            $link['operations']['#links']['term'] = [
              'title' => $this->t('Manage Terms'),
              'url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => $term->bundle()]),
            ];
            unset($link['operations']['#links']['add-child']);
            unset($link['operations']['#links']['reset']);
            unset($link['operations']['#links']['delete']);
            $link['enabled']['#disabled'] = TRUE;
            $link['weight']['#disabled'] = TRUE;
            $link['#attributes']['class'][] = 'draggable-disabled';
          }
        }
      }
    }

    if ($form_object instanceof MenuLinkEditForm) {
      $menuLinkId = $form['menu_link_id']['#value'] ?? '';
      if (str_contains($menuLinkId, 'taxonomy_menu.menu_link:taxonomy_menu.menu_link.')) {
        $form['menu_parent']['#access'] = FALSE;
        $form['weight']['#access'] = FALSE;
      }
    }

    // @phpstan-ignore-next-line
    if ($form_object instanceof TaxonomyManagerForm) {
      $form['toolbar']['search_terms']['#prefix'] = '<div class="taxonomy-manager-autocomplete-input hidden mt-4">';
      $form['taxonomy']['#prefix'] = '<div class="flex-0">';
      $form['taxonomy']['#suffix'] = '</div>';
      $form['term-data']['#prefix'] = '<div class="flex-1"><div id="taxonomy-term-data-form">';
      $form['term-data']['#suffix'] = '</div></div>';
      $form['taxonomy'] = [
        '#prefix' => '<div class="flex gap-4">',
        '#suffix' => '</div>',
        'taxonomy' => $form['taxonomy'],
        'term-data' => $form['term-data'],
      ];
      unset($form['term-data']);
      $form['load-term-data']['#attributes']['class'][] = 'hidden';
    }

    // @phpstan-ignore-next-line
    if ($form_object instanceof TaxonomyManagerTermForm) {
      $form['#neo_style'] = 'flush';
      $term = $form_object->getEntity();
      /** @var \Drupal\taxonomy\VocabularyInterface $vocabulary */
      $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($term->bundle());
      $controls = $vocabulary->getThirdPartySettings('neo_taxonomy');

      $form['fieldset']['original']['revision_information']['#access'] = FALSE;
      $form['fieldset']['original']['revision']['#access'] = FALSE;

      if (isset($controls['nest']) && empty($controls['nest'])) {
        $form['fieldset']['original']['relations']['#access'] = FALSE;
      }
    }
  }

  /**
   * After build callback to remove help and guidelines from description field.
   *
   * @param array $form_element
   *   The description widget element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The altered element.
   */
  public static function descriptionAfterBuild($form_element, FormStateInterface $form_state) {
    if (isset($form_element['format'])) {
      unset($form_element['format']['help']);
      unset($form_element['format']['guidelines']);
    }
    return $form_element;
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_taxonomy_overview_vocabularies_alter')]
  public function formTaxonomyOverviewVocabulariesAlter(&$form, FormStateInterface $form_state) {
    $form['vocabularies']['#neo_style'] = [
      'label' => 'heading',
    ];
    foreach (Element::children($form['vocabularies']) as $key) {
      $form['vocabularies'][$key]['label'] = [
        '#markup' => neo_icon($form['vocabularies'][$key]['label']['#plain_text'], NULL, NULL, ['entity.taxonomy_vocabulary']),
      ];
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_taxonomy_overview_terms_alter')]
  public function formTaxonomyOverviewTermsAlter(&$form, FormStateInterface $form_state) {
    /** @var \Drupal\taxonomy\VocabularyInterface $vocabulary */
    $vocabulary = $form_state->get(['taxonomy', 'vocabulary']);
    $controls = $vocabulary->getThirdPartySettings('neo_taxonomy');

    $form['help']['#attributes']['class'][] = 'card bg-base-100 text-xs text-base-700 p-2';
    if (
      isset($controls['order']) && empty($controls['order'])
      ||
      isset($controls['nest']) && empty($controls['nest'])
    ) {
      if ($description = $vocabulary->getDescription()) {
        $form['help']['message']['#markup'] = $description;
      }
    }
    $hideWeight = FALSE;
    foreach (Element::children($form['terms']) as $key) {
      if ($form['terms'][$key]['weight'] === NULL) {
        $hideWeight = TRUE;
      }
    }
    if ($hideWeight || (isset($controls['order']) && empty($controls['order']))) {
      $form['actions']['reset_alphabetical']['#access'] = FALSE;
      unset($form['terms']['#tabledrag']);
      unset($form['terms']['#header']['weight']);
      foreach (Element::children($form['terms']) as $key) {
        unset($form['terms'][$key]['weight']);
      }
    }

    foreach (Element::children($form['terms']) as $key) {
      $form['terms'][$key]['status']['#markup'] = neo_icon_admin($form['terms'][$key]['status']['#markup'])->iconOnly();
      $term = $form['terms'][$key]['#term'] ?? NULL;

      $default = $form['terms'][$key]['term'];
      $form['terms'][$key]['term'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['flex items-center gap-2'],
        ],
        'tid' => $default['tid'] ?? NULL,
        'parent' => $default['parent'] ?? NULL,
        'depth' => $default['depth'] ?? NULL,
        '#prefix' => $default['#prefix'] ?? '',
      ];
      unset($default['tid'], $default['parent'], $default['depth'], $default['#prefix']);
      $default['#field_prefix'] = 'asdf';
      $form['terms'][$key]['term']['title'] = $default;
      if ($term instanceof TermInterface) {
        $media = NULL;
        if ($term->hasField('field_image') && !$term->get('field_image')->isEmpty()) {
          $media = $term->get('field_image')->entity;
        }
        elseif ($term->hasField('field_media') && !$term->get('field_media')->isEmpty()) {
          $media = $term->get('field_media')->entity;
        }
        if ($media instanceof MediaInterface && $media->bundle() === 'image') {
          $neoImageStyle = new NeoImageStyle();
          $neoImageStyle->scaleCrop(30, 30);
          $form['terms'][$key]['term']['image'] = $neoImageStyle->toRenderableFromEntity($media, 'Alt Text', 'Title Text');
          $form['terms'][$key]['term']['image']['#attributes']['class'][] = 'rounded';
          $form['terms'][$key]['term']['image']['#weight'] = -10;
        }
        else {
          if ($term->hasField('field_icon') && !$term->get('field_icon')->isEmpty()) {
            $form['terms'][$key]['term']['icon'] = [
              '#type' => 'neo_icon',
              '#icon' => $term->get('field_icon')->value,
              '#weight' => -10,
            ];
          }
        }
      }
      if (isset($controls['link']) && empty($controls['link'])) {
        $form['terms'][$key]['term']['title']['#type'] = 'markup';
        $form['terms'][$key]['term']['title']['#markup'] = '<div>' . $form['terms'][$key]['term']['title']['#title'] . '</div>';
      }
    }
  }

  /**
   * Alter form for taxonomy vocabulary to add neo_taxonomy settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function formAlterFormTaxonomyVocabulary(&$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\taxonomy\VocabularyInterface $vocabulary */
    $vocabulary = $form_object->getEntity();

    /** @var \Drupal\taxonomy\TermStorageInterface $taxonomy_storage */
    $taxonomy_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $hierarchy_type = $taxonomy_storage->getVocabularyHierarchyType($vocabulary->id());

    $controls = $vocabulary->getThirdPartySettings('neo_taxonomy');
    $elements = [
      '#type' => 'fieldset',
      '#title' => $this->t('Settings'),
      '#tree' => TRUE,
    ];
    $elements['link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link to Term'),
      '#description' => $this->t('Allow terms to be linked on the management page.'),
      '#default_value' => !empty($controls['link']) || !isset($controls['link']),
    ];
    $elements['order'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Ordering'),
      '#description' => $this->t('Allow terms to be ordered.'),
      '#default_value' => !empty($controls['order']) || !isset($controls['order']),
    ];
    $elements['nest'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Nesting'),
      '#description' => $this->t('Allow terms to be nested.'),
      '#disabled' => $hierarchy_type !== 0,
      '#default_value' => !empty($controls['nest']) || !isset($controls['nest']) || $hierarchy_type !== 0,
      '#states' => [
        'visible' => [
          ':input[name="neo_taxonomy[order]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $elements['description_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Description'),
      '#description' => $this->t('Require a description to be entered when creating or editing a term.'),
      '#default_value' => !empty($controls['description_required']),
    ];

    $formats = \Drupal::service(FilterFormatRepositoryInterface::class)->getAllFormats();
    $options = array_map(function ($format) {
      return $format->label();
    }, $formats);
    $elements['description_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Description text format'),
      '#options' => $options,
      '#empty_option' => $this->t('- Default -'),
      '#default_value' => $controls['description_format'] ?? '',
    ];

    // Insert after description.
    $keys = array_keys($form);
    $index = array_search('description', $keys);
    $pos = FALSE === $index ? count($form) : $index + 1;
    $form = array_merge(array_slice($form, 0, $pos), ['neo_taxonomy' => $elements], array_slice($form, $pos));

    $form['actions']['submit']['#submit'][] = static::class . '::formTaxonomyVocabularyFormSubmit';
  }

  /**
   * Submit handler for the taxonomy vocabulary form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function formTaxonomyVocabularyFormSubmit(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\taxonomy\VocabularyInterface $vocabulary */
    $vocabulary = $form_object->getEntity();

    $controls = $form_state->getValue('neo_taxonomy');
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'link', !empty($controls['link']));
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'order', !empty($controls['order']));
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'nest', !empty($controls['order']) && !empty($controls['nest']));
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'description_required', !empty($controls['description_required']));
    $vocabulary->setThirdPartySetting('neo_taxonomy', 'description_format', $controls['description_format'] ?? '');
    $vocabulary->save();
  }

}
