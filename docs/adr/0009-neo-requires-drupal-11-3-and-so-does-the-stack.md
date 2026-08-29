# 0009 — `neo` requires Drupal 11.3, and so does everything that depends on it

**Status:** accepted
**Date:** 2026-08-26
**Context:** `neo` — the **core floor**, the **hook classes** its twenty-seven hook implementations
move into, and the fact that every Neo package depends on this one
**Plan:** `docs/plans/neo-hook-classes/`

## Decision

`neo`, `neo_menu_link` and `neo_taxonomy` declare `core_version_requirement: ^10.3 || ^11` today, and
`neo`'s package composer metadata declares the same range for `drupal/core`. All four narrow to
`^11.3`. No procedural fallback is kept: there is no legacy-hook wrapper beside any class method, and
the four `template_preprocess_*` functions become **initial preprocess** callbacks rather than
staying alongside them.

`neo_metatag` is not changed. It depends on `neo_site_settings` and `metatag` rather than on `neo`,
it keeps its one procedural hook, and nothing in it is version-gated.

## Why this is not a repeat of ADR 0006

`docs/adr/0006` settled the mechanism for `neo_toolbar` and left behind the test that decides the
question for any Neo package: **convert freely when a silently inert hook degrades, raise the floor
when it does not.** This ADR applies that test rather than re-deriving it, and records the two things
about `neo` that ADR 0006 could not.

The first is the outcome, which is not close. On Drupal 10 `neo`'s `hook_theme` would not fire, so
six theme hooks would go unregistered and the mobile **slide menu**, the accordion and the plus/minus
input would each reach a template that does not exist. `hook_element_info_alter` would not fire, so
details elements, views, entity autocompletes, links and buttons would lose their process and
pre-render callbacks site-wide. The token hooks would not fire, so every **smart token** in a meta
tag would resolve to nothing. `neo_menu_link`'s base field alter would not fire, so the menu link
form would lose the Neo link widget entirely. That is not a degraded package; it is a package that
does not work, failing quietly in four different places.

The second is the reach, and it is the reason this needs its own record. `neo_toolbar` is a leaf:
raising its floor constrains sites that install `neo_toolbar`. `neo` is the dependency root — every
Neo module and `neo_theme` require it — so raising this floor raises the floor for the whole stack.
A site on Drupal 10 cannot take the next release of anything in it.

## What it costs

**The stack's declarations become internally inconsistent.** Roughly twenty-four other Neo packages
go on declaring `^10.3 || ^11` while depending on something that no longer honours it. Composer will
refuse the combination, so the inconsistency is loud rather than silent, but a reader opening any one
of those info files will find a range that is no longer true of the thing it is part of. Correcting
them all is a separate, deliberate pass and not this plan's work.

**It is decided from one site's evidence.** This site runs 11.4.4. What the other roughly thirty
sites run is not visible from here. The decision rests on the composition — `neo_toolbar` already
declares `^11.3`, and `neo`'s own `FormAlterHook` has been partly inert below Drupal 11.1 for as long
as it has existed — rather than on a survey.

**The narrowing is published.** Once a tag carries the constraint it is in that version's metadata
permanently, and reversing it means writing a procedural implementation back beside every one of
twenty-six class methods and reinstating four preprocessor functions core has scheduled for removal.

## Alternatives considered

**Keep `^10.3 || ^11` and say nothing**, which is what `neo` did when it grew `FormAlterHook`.
Rejected. It was survivable for one `hook_form_alter` that adjusts a Views UI form — inert, that
degrades. It is not survivable for theme registration, element info and tokens.

**Dual support through legacy-hook wrappers.** Core provides an attribute that marks a procedural
function so it is skipped when the class-based implementation is available. Rejected because it keeps
every one of the twenty-six functions in a `.module` file beside its class method, which leaves 1,760
procedural lines exactly where they are. It buys a compatibility no evidence supports at the cost of
the change being pointless.

**Move the hooks but keep the four preprocessors procedural, holding the floor at 11.2.** Rejected
for the same reason ADR 0006 rejected it for two: those functions are the ones core has deprecated
with a removal date, so keeping them is keeping the part of the file with a deadline on it.

**Raise the floor on `neo` alone and leave the two sub-modules at `^10.3 || ^11`.** Rejected: they
declare `neo` as a dependency, so the range would be unreachable, and both of them convert hooks in
the same plan.

## Consequences

The declaration and the code agree, in this package and therefore in the one place the rest of the
stack inherits from.

Anything `neo` adds from here may use Drupal 11.3 mechanisms without a second conversation, which
matters more here than it did for `neo_toolbar`, because `neo` is where the shared traits, elements
and helpers the other packages build on actually live.

The remaining `^10.3 || ^11` declarations across the stack become a known inconsistency with a stated
cause, rather than a discovery someone makes at update time.
