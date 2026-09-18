<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * neo_metatag's install hook, which writes its metatag defaults over the site's.
 *
 * On a fresh install that is the module's purpose. During a config import the
 * site's own defaults arrive in the same import, and writing over them there
 * silently replaced a site's metatags with the bundled set.
 */
#[Group('neo')]
final class MetatagInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once $this->root . '/' . $this->bundledPath() . '/../../neo_metatag.install';
  }

  /**
   * A fresh install writes every bundled default.
   */
  public function testInstallWritesBundledDefaults(): void {
    $bundled = new FileStorage($this->bundledPath());
    $storage = $this->container->get('config.storage');

    neo_metatag_install(FALSE);

    $this->assertNotEmpty($bundled->listAll(''));
    foreach ($bundled->listAll('') as $name) {
      $this->assertSame($bundled->read($name), $storage->read($name), "$name is the bundled default.");
    }
  }

  /**
   * A config import leaves the site's defaults, and adds none of its own.
   */
  public function testConfigImportLeavesSiteDefaults(): void {
    $storage = $this->container->get('config.storage');
    $site = [
      'id' => 'global',
      'label' => 'Global',
      'tags' => ['description' => 'The site’s own description.'],
    ];
    $storage->write('metatag.metatag_defaults.global', $site);

    neo_metatag_install(TRUE);

    $this->assertSame($site, $storage->read('metatag.metatag_defaults.global'));
    $this->assertFalse($storage->exists('metatag.metatag_defaults.user'));
  }

  /**
   * The directory neo_metatag ships its defaults in, relative to the root.
   */
  private function bundledPath(): string {
    return $this->container->get('extension.list.module')->getPath('neo_metatag') . '/config/optional';
  }

}
