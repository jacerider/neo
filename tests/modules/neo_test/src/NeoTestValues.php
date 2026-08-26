<?php

declare(strict_types=1);

namespace Drupal\neo_test;

use Drupal\neo\ValuesInterface;
use Drupal\neo\ValuesTrait;

/**
 * A concrete consumer of the values contract.
 *
 * `ValuesTrait` has no consumer anywhere in `neo` — every class that uses it
 * lives in `neo_settings`, `neo_modal` or `neo_alchemist` — which is why
 * phpstan reports it `trait.unused` and why it had never run under a test. This
 * class is the whole of what the trait needs to become reachable: an array to
 * keep values in, and a `&getValues()` that hands back a **reference** to it.
 *
 * The reference is the load-bearing part. `getValue()` and `setValues()` both
 * write through the value `getValues()` returns, so a consumer that returned a
 * copy would leave `setValue()` and `setValues()` silently inert. Returning
 * `$this->values` by reference is therefore not a convenience here — it is the
 * contract `ValuesInterface::getValues()` states, and a stand-in that got it
 * wrong would characterise a trait nobody uses.
 *
 * Nothing is overridden. Every method under test is the trait's own.
 */
final class NeoTestValues implements ValuesInterface {

  use ValuesTrait;

  /**
   * The values.
   *
   * @var array
   */
  protected array $values = [];

  /**
   * Constructs a values consumer.
   *
   * @param array $values
   *   (optional) The values to start with.
   */
  public function __construct(array $values = []) {
    $this->values = $values;
  }

  /**
   * {@inheritdoc}
   */
  public function &getValues() {
    return $this->values;
  }

}
