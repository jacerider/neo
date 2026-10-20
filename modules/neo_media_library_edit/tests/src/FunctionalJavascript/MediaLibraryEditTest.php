<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_media_library_edit\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests editing a selected media item from the media library widget.
 *
 * Runs on core's media_library_test fixtures and the stark theme, so no Neo
 * theme styles or alters the widget. The modal is a NeoModal: neo requires
 * neo_icon, which requires neo_modal, so that is the dialog every Neo site has.
 */
#[Group('neo_media_library_edit')]
class MediaLibraryEditTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['media_library_test', 'neo_media_library_edit'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The node whose media field the tests edit through.
   */
  protected NodeInterface $node;

  /**
   * The two media items the node references.
   *
   * @var \Drupal\media\MediaInterface[]
   */
  protected array $media = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['Cat', 'Dog'] as $name) {
      $media = Media::create([
        'bundle' => 'type_one',
        'name' => $name,
        'field_media_test' => $name,
      ]);
      $media->save();
      $this->media[$name] = $media;
    }
    $this->node = $this->drupalCreateNode([
      'type' => 'basic_page',
      'title' => 'Host',
      'field_unlimited_media' => array_values(array_map(fn (MediaInterface $media) => $media->id(), $this->media)),
    ]);
  }

  /**
   * Tests the edit button, the modal form and the widget refresh.
   */
  public function testEditSelectedMedia(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'edit any basic_page content',
      'view media',
      'update any media',
      'delete any media',
    ]));
    $this->drupalGet($this->node->toUrl('edit-form'));
    $this->shimNeoAnimations();
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // One edit button per selected item, as core renders it.
    $assert->elementsCount('css', '.js-media-library-item .edit-media', 2);
    $assert->elementAttributeContains('css', '[data-media-library-item-delta="0"] .edit-media', 'aria-label', 'Edit Cat');
    // Not in the library grid: the widget has no grid until it is opened, and
    // the button is never placed outside a selected item.
    $assert->elementsCount('css', '.edit-media', 2);

    // Unsaved input on the host form must survive the edit.
    $page->fillField('title[0][value]', 'Host, unsaved');

    $page->find('css', '[data-media-library-item-delta="0"] .edit-media')->click();
    $dialog = $assert->waitForElementVisible('css', '.neo-modal');
    $this->assertNotNull($dialog);
    $this->assertNotNull($assert->waitForField('name[0][value]'));
    $this->assertStringContainsString('Changes to this media item apply everywhere it is used.', $dialog->getText());
    $this->assertFalse($dialog->hasLink('Delete'), 'The modal hides the Delete action.');

    // A validation error re-renders the form inside the modal. The browser's
    // own required check is lifted so the server's validation runs.
    $dialog->fillField('name[0][value]', '');
    $this->getSession()->executeScript("document.querySelector('.neo-modal [name=\"name[0][value]\"]').removeAttribute('required');");
    $this->pressModalSave();
    $this->assertTrue($dialog->waitFor(10, fn () => str_contains($dialog->getText(), 'Name field is required.')));
    $this->assertTrue($dialog->isVisible(), 'The modal stays open on a validation error.');

    // A successful save closes the modal and refreshes the widget preview.
    $dialog->fillField('name[0][value]', 'Kitten');
    $this->pressModalSave();
    $this->assertTrue($assert->waitForElementRemoved('css', '.neo-modal'));
    $this->assertNotNull($assert->waitForElement('css', '.js-media-library-item .edit-media[aria-label="Edit Kitten"]'));
    $assert->fieldValueEquals('title[0][value]', 'Host, unsaved');
    $this->assertSame('Kitten', $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($this->media['Cat']->id())->label());

    // The host form still saves, with both items and the unsaved title.
    $assert->assertWaitOnAjaxRequest();
    $page->pressButton('Save');
    $assert->pageTextContains('Host, unsaved has been updated.');
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    $this->assertCount(2, $node->get('field_unlimited_media'));
  }

  /**
   * Tests that the button needs update access on the media item.
   */
  public function testNoUpdateAccess(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'edit any basic_page content',
      'view media',
    ]));
    $this->drupalGet($this->node->toUrl('edit-form'));
    $this->assertSession()->elementsCount('css', '.js-media-library-item', 2);
    $this->assertSession()->elementNotExists('css', '.edit-media');
  }

  /**
   * Tests that the media edit page, reached directly, is left alone.
   */
  public function testDirectEditFormUnchanged(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view media',
      'update any media',
      'delete any media',
    ]));
    $this->drupalGet($this->media['Dog']->toUrl('edit-form'));
    $this->assertSession()->linkExists('Delete');
    $this->assertSession()->pageTextNotContains('Changes to this media item apply everywhere it is used.');
  }

  /**
   * Presses Save in the open modal.
   *
   * NeoModal hides the form's own actions and shows a proxy button for each
   * in its footer, so the visible Save is the proxy.
   */
  protected function pressModalSave(): void {
    $button = $this->assertSession()->waitForElementVisible('xpath', '//div[contains(@class, "neo-modal--actions")]//button[normalize-space()="Save"]');
    $this->assertNotNull($button);
    $button->click();
  }

  /**
   * Gives NeoModal's animation classes an animation to finish.
   *
   * NeoModal completes opening, and starts watching its content for AJAX
   * replacements, on the animationend of its content block. The keyframes ship
   * in a Neo theme's compiled CSS, which stark does not have, so without this
   * no animationend arrives and the modal never finishes opening.
   */
  protected function shimNeoAnimations(): void {
    $this->getSession()->executeScript(<<<'JS'
      const style = document.createElement('style');
      style.textContent = '@keyframes neo-test-shim { to { opacity: 1; } } [class*="neo-animate--"] { animation: neo-test-shim 1ms !important; }';
      document.head.appendChild(style);
      JS);
  }

}
