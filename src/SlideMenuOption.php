<?php

declare(strict_types=1);

namespace Drupal\neo;

use Drupal\Core\Template\Attribute;

/**
 * The options a slide menu takes, and the definition of the set.
 *
 * A slide menu is configured by an option array — the second argument to
 * SlideMenu::__construct(), and the twelve keys Element\SlideMenu forwards from
 * its own '#' properties. The set is closed: a menu has these options and no
 * others.
 *
 * The mapping is the decision the enum encodes. A case's backing value is the
 * option key a caller writes, so SlideMenu::applyOptions() resolves a key with
 * ::tryFrom() and the set a caller may pass is the set this file states. Before
 * this existed the keys appeared nowhere at all: an option was whatever
 * method_exists() answered for a camel-cased key, so learning that the expand
 * depth was configurable meant reading two hundred and fifty lines of setters,
 * and mistyping one bought silence.
 *
 * Each case carries two more things, and both were stated twice before it did.
 * ::defaultValue() is the value the option starts at — the fourteen property
 * initialisers and the five Attribute objects the constructor used to name.
 * ::cast() is the type an incoming value is coerced to, which nothing stated at
 * all: a ReflectionClass derived it from the setter's declared parameter type
 * on every call. The cast is not a convenience. SlideMenu declares
 * strict_types=1 and its setters declare scalar types, so the element's own
 * t('View All') default would reach setAllPrefix(string) as a TypeError
 * without it.
 *
 * neo_build's Scope and neo_toolbar's ElementAttributeBag are the in-house
 * precedent for a small backed enum standing in for a closed set. This is the
 * same shape and exists for the same reason.
 *
 * The enum is public because PHP has no other way to spell one, not because
 * anything outside the menu is expected to act on a case: the accessor that
 * answers an option for a case is deliberately protected on SlideMenu, so a
 * case's only use from outside is to be handed back to code that already had
 * it. An option key this enum does not name is still ignored in silence rather
 * than raised — the option array is assembled by other packages and by site
 * code, so a stale key stays a no-op instead of becoming an outage.
 */
enum SlideMenuOption: string {

  case Items = 'items';
  case ItemAttributes = 'item_attributes';
  case LinkAttributes = 'link_attributes';
  case ChildIcon = 'child_icon';
  case ChildIconAttributes = 'child_icon_attributes';
  case BackStatus = 'back_status';
  case BackIcon = 'back_icon';
  case BackAttributes = 'back_attributes';
  case BackIconAttributes = 'back_icon_attributes';
  case BackLabel = 'back_label';
  case AllStatus = 'all_status';
  case AllPrefix = 'all_prefix';
  case AllSuffix = 'all_suffix';
  case ExpandDepth = 'expand_depth';

  /**
   * The value this option starts at on a menu nothing has configured.
   *
   * A menu seeds every option from this, so the default is stated here and
   * nowhere else. Two of the five attribute bags are not empty, and both are
   * load-bearing: an attribute option merges into what it already holds, so a
   * caller passing a class to the link bag adds one to these rather than
   * replacing them.
   *
   * Answers a fresh Attribute each call — the bags are mutable and a menu owns
   * its own.
   *
   * @return mixed
   *   The option's default value.
   */
  public function defaultValue(): mixed {
    return match ($this) {
      self::Items => [],
      self::ItemAttributes, self::ChildIconAttributes, self::BackIconAttributes => new Attribute(),
      self::LinkAttributes => new Attribute([
        'class' => [
          'flex items-center justify-between',
        ],
      ]),
      self::BackAttributes => new Attribute([
        'class' => [
          'flex items-center justify-between w-full',
          'neo-slide-menu--backlink',
          'neo-slide-menu--control',
        ],
        'data-action' => 'back',
      ]),
      self::ChildIcon => 'chevron-right',
      self::BackIcon => 'chevron-left',
      self::BackLabel => 'Back',
      self::AllPrefix => 'View all',
      self::AllSuffix => 'Right now',
      self::BackStatus, self::AllStatus => TRUE,
      self::ExpandDepth => 0,
    };
  }

  /**
   * The type an incoming value for this option is cast to.
   *
   * Reproduces what a ReflectionClass used to derive from the setter's own
   * declared parameter type: the three scalar setters' types, and NULL for an
   * option whose setter takes an array, which is handed on untouched.
   *
   * @return string|null
   *   'string', 'int', 'bool', or NULL when the value is left as it arrives.
   */
  public function castType(): ?string {
    return match ($this) {
      self::ChildIcon, self::BackIcon, self::BackLabel, self::AllPrefix, self::AllSuffix => 'string',
      self::ExpandDepth => 'int',
      self::BackStatus, self::AllStatus => 'bool',
      self::Items, self::ItemAttributes, self::LinkAttributes, self::ChildIconAttributes, self::BackAttributes, self::BackIconAttributes => NULL,
    };
  }

  /**
   * Casts an incoming value to the type this option is stored as.
   *
   * @param mixed $value
   *   The value as the caller passed it.
   *
   * @return mixed
   *   The value cast to this option's type, or unchanged when it has none.
   */
  public function cast(mixed $value): mixed {
    return match ($this->castType()) {
      'string' => (string) $value,
      'int' => (int) $value,
      'bool' => (bool) $value,
      default => $value,
    };
  }

}
