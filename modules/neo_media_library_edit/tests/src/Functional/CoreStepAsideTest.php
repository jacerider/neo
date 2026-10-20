<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_media_library_edit\Functional;

use Drupal\Component\Utility\UrlHelper;
use Drupal\media\Entity\Media;
use Drupal\neo_media_library_edit\Hook\MediaLibraryEditHooks;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the module steps aside once core provides the edit button.
 *
 * A fixture module stands in for core: it adds a core-shaped `edit_button`
 * and wires the media form's Save, as merge request !4517 does.
 */
#[Group('neo_media_library_edit')]
class CoreStepAsideTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['media_library_test', 'neo_media_library_edit'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the button, the media form and the status report, before and after.
   */
  public function testStepAside(): void {
    $media = Media::create([
      'bundle' => 'type_one',
      'name' => 'Cat',
      'field_media_test' => 'Cat',
    ]);
    $media->save();
    $node = $this->drupalCreateNode([
      'type' => 'basic_page',
      'title' => 'Host',
      'field_unlimited_media' => [$media->id()],
    ]);
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'edit any basic_page content',
      'view media',
      'update any media',
      'administer site configuration',
    ]));
    $assert = $this->assertSession();

    // Before core: the module's own button, its notice, and a quiet report.
    $this->drupalGet($node->toUrl('edit-form'));
    $assert->elementsCount('css', '.edit-media', 1);
    $assert->elementExists('css', '.edit-media[' . MediaLibraryEditHooks::MARKER . ']');
    $this->assertModalNotice(TRUE);
    $this->drupalGet('admin/reports/status');
    $assert->pageTextNotContains('Neo | Media Library Edit');

    // With core's button: exactly one button per item, and it is core's.
    $this->container->get('module_installer')->install(['neo_media_library_edit_core_test']);
    $this->drupalGet($node->toUrl('edit-form'));
    $assert->elementsCount('css', '.edit-media', 1);
    $assert->elementExists('css', '.edit-media[data-core-stand-in]');
    $assert->elementNotExists('css', '[' . MediaLibraryEditHooks::MARKER . ']');

    // The media form is left to core: no notice, Delete untouched.
    $this->assertModalNotice(FALSE);

    $this->drupalGet('admin/reports/status');
    $assert->pageTextContains('Neo | Media Library Edit');
    $assert->pageTextContains('Drupal core now provides an Edit button');
  }

  /**
   * Asserts whether the modal media edit form shows the shared-usage notice.
   *
   * The form is requested as the button opens it, with the media library
   * state in the query. That state is read from the module's own button the
   * first time and reused once core's button, which has no URL here, replaces
   * it.
   */
  protected function assertModalNotice(bool $expected): void {
    static $query = NULL;
    $link = $this->getSession()->getPage()->find('css', '.edit-media[href]');
    if ($link) {
      $parts = UrlHelper::parse($link->getAttribute('href'));
      $query = $parts['query'];
    }
    $this->assertNotNull($query, 'A media library state is available.');
    $media = Media::load(1);
    $query['_wrapper_format'] = 'drupal_modal';
    $response = $this->drupalGet($media->toUrl('edit-form', ['query' => $query]));
    $notice = 'Changes to this media item apply everywhere it is used.';
    if ($expected) {
      $this->assertStringContainsString($notice, $response);
    }
    else {
      $this->assertStringNotContainsString($notice, $response);
    }
  }

}
