<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\neo\Hook\NeoTokensHooks;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * The share image tokens keep the image's own format.
 *
 * `[neo:image]` and `[neo:logo]` fill og:image and twitter:image. Built with
 * neo_image's styles they came out as AVIF, which the social networks do not
 * show in link previews. Without parameters they are now built with the
 * neo_social image style — shipped as optional config, added to existing sites
 * by neo_update_11002() and turned from fitting to cropping by
 * neo_update_11003() — which crops the image to 1200×630 in its source format,
 * and never enlarges one smaller than that. The token only names the
 * derivative: it is built when something first fetches it, as any image
 * style's is, and the width and height tokens are worked out from the source.
 * Building it while the page rendered took the page down whenever the source
 * was too large to convert in one request (a 24.8MB photo on Pantheon answered
 * 502).
 *
 * neo_image is not installed here: with the style present its branch is never
 * reached for a parameterless token, and without the style the fallback is
 * what SmartTokenLogoAndImageTest already pins.
 *
 * @see \Drupal\neo\Hook\NeoTokensHooks::imageData()
 * @see neo_update_11002()
 */
#[Group('neo')]
final class SocialShareImageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'file',
    'image',
    'token',
    'neo',
    'neo_test',
  ];

  /**
   * The source image: a 2400×1000 PNG.
   */
  private const SOURCE = 'public://neo-share-source.png';

  /**
   * The instance under test, rebuilt when the request changes.
   */
  private ?NeoTokensHooks $hooks = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'file', 'image', 'neo']);
    \Drupal::service('router.builder')->rebuild();

    $image = imagecreatetruecolor(2400, 1000);
    ob_start();
    imagepng($image);
    file_put_contents(self::SOURCE, (string) ob_get_clean());
    imagedestroy($image);
    clearstatcache();

    // Off the front page, where the image token asks the image alter hook.
    $this->enterRequest('neo_test.open');
    \Drupal::state()->set('neo_test.token_image_alter', self::SOURCE);
  }

  /**
   * The share image is a PNG from the neo_social style, not AVIF.
   */
  public function testTheShareImageKeepsItsFormat(): void {
    $this->assertInstanceOf(ImageStyle::class, ImageStyle::load('neo_social'), 'Premise: the optional config installed the style.');

    $url = $this->invoke('image', [[], NULL]);
    $this->assertStringContainsString('/styles/neo_social/public/neo-share-source.png', $url);
    $this->assertStringContainsString('itok=', $url);
    $this->assertStringNotContainsString('.avif', $url);

    // Cropped to 1200×630, worked out without building the derivative.
    $this->assertSame('1200', $this->invoke('image', [['width'], NULL]));
    $this->assertSame('630', $this->invoke('image', [['height'], NULL]));
    $this->assertFileDoesNotExist(ImageStyle::load('neo_social')->buildUri(self::SOURCE));
  }

  /**
   * A source smaller than the card is shared as it is, never enlarged.
   *
   * The favicon a page without an image falls back to is one: enlarged into
   * the card it would come out blurred and cut.
   */
  public function testSmallSourceKeepsItsDimensions(): void {
    $image = imagecreatetruecolor(800, 600);
    ob_start();
    imagepng($image);
    file_put_contents('public://neo-share-small.png', (string) ob_get_clean());
    imagedestroy($image);
    \Drupal::state()->set('neo_test.token_image_alter', 'public://neo-share-small.png');

    $this->assertSame('800', $this->invoke('image', [['width'], NULL]));
    $this->assertSame('600', $this->invoke('image', [['height'], NULL]));
    $original = \Drupal::service('file_url_generator')->generateAbsoluteString('public://neo-share-small.png');
    $this->assertSame($original, $this->invoke('image', [[], NULL]), 'The original, not the style.');
    $this->assertFileDoesNotExist(ImageStyle::load('neo_social')->buildUri('public://neo-share-small.png'));
  }

  /**
   * Without the style the tokens fall back as before.
   */
  public function testWithoutTheStyleTheFallbackIsUnchanged(): void {
    ImageStyle::load('neo_social')->delete();
    $url = $this->invoke('image', [[], NULL]);
    $this->assertSame(\Drupal::service('file_url_generator')->generateAbsoluteString(self::SOURCE), $url);
  }

  /**
   * The update adds the style to a site that lacks it, and only then.
   */
  public function testTheUpdateAddsTheStyle(): void {
    ImageStyle::load('neo_social')->delete();
    \Drupal::moduleHandler()->loadInclude('neo', 'install');

    $this->assertStringContainsString('Added the neo_social image style', neo_update_11002());
    $style = ImageStyle::load('neo_social');
    $this->assertInstanceOf(ImageStyle::class, $style);
    $effect = $style->getEffects()->getIterator()->current();
    $this->assertSame('image_scale_and_crop', $effect->getPluginId());
    $this->assertSame(['width' => 1200, 'height' => 630, 'anchor' => 'center-center'], $effect->getConfiguration()['data']);

    $this->assertStringContainsString('already exists', neo_update_11002());
  }

  /**
   * The update crops a style still as shipped, and leaves an edited one.
   */
  public function testTheUpdateCropsTheShippedStyle(): void {
    \Drupal::moduleHandler()->loadInclude('neo', 'install');
    $style = ImageStyle::load('neo_social');
    foreach ($style->getEffects() as $effect) {
      $style->deleteImageEffect($effect);
    }
    $style->addImageEffect([
      'id' => 'image_scale',
      'weight' => 1,
      'data' => ['width' => 1200, 'height' => 630, 'upscale' => FALSE],
    ]);
    $style->save();

    $this->assertStringContainsString('now crops share images to 1200×630', neo_update_11003());
    $effects = array_values(ImageStyle::load('neo_social')->getEffects()->getConfiguration());
    $this->assertCount(1, $effects);
    $this->assertSame('image_scale_and_crop', $effects[0]['id'], 'Without focal_point, a centred crop.');
    $this->assertSame(['width' => 1200, 'height' => 630, 'anchor' => 'center-center'], $effects[0]['data']);

    // An edited style is the site's.
    $style = ImageStyle::load('neo_social');
    $style->addImageEffect(['id' => 'image_desaturate', 'weight' => 2, 'data' => []]);
    $style->save();
    $this->assertStringContainsString('left as it is', neo_update_11003());
    $this->assertCount(2, ImageStyle::load('neo_social')->getEffects());
  }

  /**
   * Replaces the current request with one on the given route.
   *
   * @param string $routeName
   *   The route to put on the request.
   */
  private function enterRequest(string $routeName): void {
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, \Drupal::service('router.route_provider')->getRouteByName($routeName));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $routeName);
    $current = \Drupal::requestStack()->getCurrentRequest();
    if ($current !== NULL && $current->hasSession()) {
      $request->setSession($current->getSession());
    }
    \Drupal::requestStack()->push($request);
    $this->container->set('path.matcher', new PathMatcher(
      $this->container->get('config.factory'),
      $this->container->get('current_route_match')
    ));
    $this->hooks = NULL;
  }

  /**
   * Calls a private method on the hook class.
   */
  private function invoke(string $method, array $arguments): mixed {
    $this->hooks ??= new NeoTokensHooks(
      $this->container->get('cache.default'),
      $this->container->get('module_handler'),
      $this->container->get('path.matcher'),
      $this->container->get('config.factory'),
      $this->container->get('request_stack'),
      $this->container->get('title_resolver'),
      $this->container->get('file_url_generator'),
      $this->container->get('entity_type.manager'),
    );
    return (new \ReflectionMethod($this->hooks, $method))->invokeArgs($this->hooks, $arguments);
  }

}
