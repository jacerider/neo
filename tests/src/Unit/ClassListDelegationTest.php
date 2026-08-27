<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo\Plugin\Field\FieldWidget\NeoLinkWidget;
use Drupal\neo_menu_link\Settings\MenuLinkSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the two class list surfaces to a frozen shape over one parser.
 *
 * `neo` ships to roughly thirty sites, so getClassList() keeps its name, its
 * public visibility and its declared absence of a return type, and
 * validateClassListValue() keeps its name and its protected visibility. What
 * changes is what sits between them: each method used to carry its own
 * character-for-character copy of the parse, and now reads its string, hands
 * that and its own rule to the one parser, and returns.
 *
 * Neither method is exercised through its own class here. NeoLinkWidget is a
 * core-subclassing field-widget plugin and MenuLinkSettings sits behind the
 * settings repository; constructing either to reach three lines of delegation
 * costs more than it proves. Reflection reaches the shape without a container,
 * and the browser tier is where a wrong delegation would actually show.
 */
#[Group('neo')]
class ClassListDelegationTest extends UnitTestCase {

  /**
   * The two surfaces that render a class list.
   *
   * @return array<string, array{class-string}>
   *   The class of each surface, keyed by what it renders.
   */
  public static function surfaces(): array {
    return [
      'the link widget Style select' => [NeoLinkWidget::class],
      'the menu link CSS-classes select' => [MenuLinkSettings::class],
    ];
  }

  /**
   * The surface is unchanged and the parse is no longer in it.
   *
   * @param string $class
   *   The surface under test.
   */
  #[DataProvider('surfaces')]
  public function testGetClassListKeepsItsShapeAndDelegatesWithItsOwnRule(string $class): void {
    $parse = new \ReflectionMethod($class, 'getClassList');
    $this->assertTrue($parse->isPublic(), 'getClassList() is still public.');
    $this->assertFalse($parse->isStatic(), 'getClassList() is still an instance method.');
    $this->assertCount(0, $parse->getParameters(), 'getClassList() still takes no arguments.');
    $this->assertFalse($parse->hasReturnType(), 'getClassList() still declares no return type.');

    $rule = new \ReflectionMethod($class, 'validateClassListValue');
    $this->assertTrue($rule->isProtected(), 'validateClassListValue() is still protected.');
    $this->assertCount(1, $rule->getParameters(), 'validateClassListValue() still takes the line.');

    $body = $this->body($parse);
    $this->assertStringContainsString('ClassList::parse(', $body, 'getClassList() delegates to the parser.');
    $this->assertStringContainsString('validateClassListValue', $body, 'getClassList() hands in its own rule.');

    // The copy is gone rather than merely bypassed: nothing of the parse is
    // left behind to drift out of step with the parser.
    $this->assertStringNotContainsString('preg_match(', $body);
    $this->assertStringNotContainsString('array_filter(', $body);
    $this->assertStringNotContainsString('explode(', $body);
  }

  /**
   * Reads a method's source, so the delegation itself can be asserted.
   *
   * @param \ReflectionMethod $method
   *   The method to read.
   *
   * @return string
   *   The declaration and body, as written.
   */
  private function body(\ReflectionMethod $method): string {
    $lines = file($method->getFileName());
    $start = $method->getStartLine() - 1;
    return implode('', array_slice($lines, $start, $method->getEndLine() - $start));
  }

}
