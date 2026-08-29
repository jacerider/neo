# 0010 — The **table props shim** is deprecated in its docblock and nowhere else, and `neo_theme` is left calling it

**Status:** accepted
**Date:** 2026-08-26
**Context:** `neo` — **table props**, the `neo_table_props()` global that answers them, and the one
caller outside this package
**Plan:** `docs/plans/neo-hook-classes/`

## Decision

`neo_table_props()`'s body moves to `Helpers\TableProps`, a static beside the **Neo helpers** already
under `src/Helpers/`. The global stays in `neo.module` as a one-line **table props shim**.

The shim carries a `@deprecated` tag naming the replacement and a major-version removal. It carries
**no `@trigger_error`**, so nothing is logged at runtime and nothing appears on a page.

`neo_theme` — the one caller outside this package, at `neo_base/src/NeoBasePreRender.php:273` — is
**not edited**, in this plan or as a condition of it. It goes on calling the shim.

## Why this needs recording

It contradicts the nearest precedent in this repository. `neo_toolbar`'s **gate forwarder** is the
same shape — one surviving global delegating to the code that moved — and `docs/plans/neo-toolbar-hook-classes/`
deliberately left it *undeprecated*, on the argument that a forwarder called once per consulting block
would fire a deprecation eleven times on an admin page, in the log, on sites whose maintainers did not
ask for the move. A reader who knows that decision will find this one and assume the inconsistency is
an oversight.

It is not. The two globals differ in exactly one way that matters: nothing outside `neo_toolbar`
calls the gate forwarder, so a deprecation on it would have had no audience. `neo_table_props()` has
a known caller in a different repository, and expand–contract without a signal to the consumer is not
expand–contract — it is just an extra function. The docblock is the smallest form of that signal that
reaches a tool without reaching an operator.

## What it costs

**One new finding in a repository this plan does not open.** The site's generated `phpstan.neon`
lists `./web/themes/contrib/neo_theme/neo_base` among its paths and `phpstan-deprecation-rules` is
active at the gate's own level — that is how `neo-gate-level-clean` measured a `function.deprecated`
finding for a `menu_ui` call. So a whole-site run after this plan reports one new
`function.deprecated` at `NeoBasePreRender.php:273`. It is a true finding and it is the deprecation
working as designed, but it is a finding planted in a package that cannot clear it today, and no
ticket's `lint` gate sees it because that tier scopes to changed files.

**A deprecation nobody running a site will ever notice.** Without `@trigger_error`, an operator gets
nothing — no log line, no notice, no page warning. The signal reaches static analysis and a reader of
the source, and stops there. If `neo_theme` never gets opened, the shim outlives the deprecation
indefinitely and no one is told.

**`neo` carries the global for the foreseeable future.** Contract — deleting it — is a breaking
release across roughly thirty sites and cannot happen until `neo_theme` has migrated. The decision
not to edit `neo_theme` now is therefore also a decision to carry the shim for at least one more
release cycle.

## Alternatives considered

**A runtime `@trigger_error` beside the tag**, which is what Drupal's own deprecation policy asks
for. Rejected on the precedent's own argument: `neo_table_props()` is called once per Views table
pre-render, which means on `/admin/content` and every other admin listing, on thirty sites, for a
move nobody asked for. The one consumer that would benefit is a package the same maintainer owns and
will read the source of.

**No deprecation at all**, matching the **gate forwarder** exactly. Rejected because it leaves
`neo_theme` with no reason to ever stop calling a global, and the backlog candidate that produced this
plan asked specifically for a deprecated shim. A forwarder with no deprecation is a permanent second
way to reach the same data, and this package already has enough of those.

**Edit `neo_theme` in the same plan and delete the global outright.** Rejected on two facts, either of
which is sufficient. `neo_theme`'s `composer.json` requires `jacerider/neo: ^1` with no minimum, so a
theme that called `Helpers\TableProps` would need a constraint naming a `neo` version that does not
exist until `pkg release` produces one — a number this plan cannot know and must not invent — and
without it a site could install a `neo_theme` whose call fatals against an older `neo`. Separately,
the `neo_theme` checkout on this site sits at a detached HEAD at tag 1.1.0 rather than on `develop`,
so it is not commit-eligible under the site contract's own rule without a `pkg work` first. A
two-line change is not worth a release-ordering problem and a stale contract row.

**Keep the global as the implementation and skip the static entirely.** Rejected because it is the
friction the plan exists to remove: a `#[Hook]` class calling a global function means a class-based
hook that depends on a `.module` file having been loaded, which is what `FormAlterHook` has been
doing since it was written.

## Consequences

Every call inside `neo` moves to the static in the same commit that adds the tag, because a
`@deprecated` function called from its own package is a `function.deprecated` finding and this package
is held to **gate-level clean** at two file errors.

The next plan that opens `neo_theme` finds a phpstan finding telling it exactly what to change and,
by then, a released `neo` version to constrain against. That is the whole mechanism this ADR is
choosing, and it works only if someone opens `neo_theme`.

`neo.module` ends up holding the **procedural remainder** and nothing else: this shim, and the two
debug helpers the packages' pre-commit hook pins to that file by path.

## Update — 2026-08-27

**`neo_theme` migrated.** The mechanism this ADR chose worked as designed: the finding was found and
the package was opened. `neo_base/src/NeoBasePreRender.php` now calls `Helpers\TableProps::get()` at
both sites, and `neo_theme`'s `composer.json` requires `jacerider/neo: ^1.0.139`. Committed to
`neo_theme`'s `develop` as `821053d`; **not yet released**.

The status stays **accepted**. The decision was carried out, not overturned — nothing below reverses
it, and there is no superseding ADR.

**There were two call sites, not one.** This ADR names `NeoBasePreRender.php:273`. The file carried
the call at both `:144` and `:357`, so a whole-site phpstan run reported **two** `function.deprecated`
findings rather than the one predicted. Both are gone; the file is clean, and the rule was confirmed
to still fire by re-running against the pre-change file rather than trusting the clean result alone.

**The release-ordering objection dissolved on its own.** The third alternative rejected above turned
on `neo_theme` needing to constrain against "a `neo` version that does not exist". By the time the
package was opened, `Helpers\TableProps` had shipped in `neo` **1.0.139** — the version this site
already runs — so the constraint could name a real number. Only the second reason, the detached
checkout, still stood, and a `pkg work neo_theme` cleared it.

**The deprecation tag names a version the shim predates.** The docblock says deprecated *in
neo:1.1.0*, but the shim and the static both shipped in **1.0.139**. A reader computing the removal
window from the tag will place the deprecation a minor version later than it happened. Left as-is
here: `neo`'s docblock is a change in a different package and a claim about its release history.

**What deleting the global still waits on.** Contract is unchanged in kind and still blocked, but on
a different fact than when this was written. It is no longer "`neo_theme` calls the global" — it is
that the migration sits on `develop` and is in no `neo_theme` tag, and every site would have to be
running a tag that carries it before `neo` 2.0.0 could drop the global without fataling the theme.
Order: release `neo_theme`, roll it out, then contract.
