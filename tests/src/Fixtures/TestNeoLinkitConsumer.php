<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Fixtures;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo\NeoLinkitTrait;

/**
 * A concrete consumer of the link write path's half of the Linkit seam.
 *
 * `NeoLinkitTrait` is reached in production through `NeoLinkWidget` and
 * `neo_modal`'s `NeoModalBlockBase`, both of which are plugins that need the
 * full field and plugin machinery to construct. The seam is the thing under
 * test, not the plugins, so the tests exercise the trait through this class
 * instead — the same shape `neo_toolbar`'s suite uses for its link trait.
 *
 * Every method on the trait is already public, so nothing has to be re-exposed
 * here. What the class buys is a name to call the `public static` helpers on:
 * calling a static method on a trait name is an error in modern PHP, so a
 * concrete `use`r is the only way in.
 *
 * `StringTranslationTrait` is here because `getLinkitElement()` calls
 * `$this->t()`. No test calls it, but a class that would fatal on one of its
 * own methods is not a fair stand-in for the plugins that really use the trait.
 */
final class TestNeoLinkitConsumer {

  use StringTranslationTrait;
  use NeoLinkitTrait;

}
