# 0009 — `neo` requires Drupal 11.3, and so does everything that depends on it

**Status:** accepted · **Date:** 2026-08-26
**Context:** `neo` — the **core floor**, and the **hook classes** its twenty-seven hooks move into
**Issue:** jacerider/neo#8

**Decision.** `neo`, `neo_menu_link` and `neo_taxonomy` declare `core_version_requirement:
^10.3 || ^11`, as does `neo`'s composer metadata for `drupal/core`; all four narrow to `^11.3`. No
procedural fallback is kept: no legacy-hook wrapper beside any class method, and the four
`template_preprocess_*` functions become **initial preprocess** callbacks. `neo_metatag` is not
changed: it depends on `neo_site_settings` and `metatag`, not `neo`, keeps its one procedural hook,
and nothing in it is version-gated.

**Why it needs recording.** ADR 0006 settled the mechanism for `neo_toolbar` and left the test that
decides it for any Neo package: convert freely when a silently inert hook degrades, raise the floor
when it does not. Here the outcome is not close. On Drupal 10 `hook_theme` would not fire (six theme
hooks unregistered; the mobile **slide menu**, accordion and plus/minus input reach templates that
do not exist), nor `hook_element_info_alter` (details, views, entity autocompletes, links, buttons
lose process and pre-render callbacks site-wide), nor the token hooks (every **smart token** in a
meta tag resolves to nothing), nor `neo_menu_link`'s base field alter (the menu link form loses the
Neo link widget): a package that does not work, failing quietly in four places. What 0006 could not
record is the reach: `neo_toolbar` is a leaf, but `neo` is the dependency root every Neo module and
`neo_theme` require, so this floor is the stack's — a Drupal 10 site cannot take the next release.

**Rejected.**
- Keep `^10.3 || ^11` and say nothing, as `neo` did when it grew `FormAlterHook` — survivable for
  one inert `hook_form_alter` on a Views UI form; not for theme registration, element info, tokens.
- Legacy-hook wrappers (core's attribute that skips a procedural function when the class exists) —
  leaves all twenty-six functions and 1,760 procedural lines in place; the change becomes pointless.
- Keep the four preprocessors procedural, floor at 11.2 — as in 0006: those are the functions core
  has deprecated with a removal date, the one part of the file with a deadline on it.
- Raise `neo` alone, leave the two sub-modules at `^10.3 || ^11` — they depend on `neo`, so the
  range is unreachable, and both convert hooks in the same plan.

**Cost.** Some twenty-four other Neo packages go on declaring `^10.3 || ^11` while depending on
something that no longer honours it; Composer refuses the combination, loudly, but correcting them
is a separate pass. It rests on one site's evidence — this runs 11.4.4; the other thirty-odd are
unseen — and on composition (`neo_toolbar` already declares `^11.3`; `FormAlterHook` has been partly
inert below 11.1 all along), not a survey. Once tagged it is permanent; reversal means procedural
code back beside twenty-six class methods and four preprocessors core will remove.
