# 0010 — The **table props shim** is deprecated by docblock alone, and `neo_theme` still calls it

**Status:** accepted · **Date:** 2026-08-26
**Context:** `neo` — **table props**, the **table props shim** that answers them, and its one caller
**Issue:** jacerider/neo#8

**Decision.** `neo_table_props()`'s body moves to `Helpers\TableProps`, a static beside the **Neo
helpers** under `src/Helpers/`; the global stays in `neo.module` as a one-line **table props shim**
with a `@deprecated` tag naming the replacement and a major-version removal, no `@trigger_error`,
so nothing is logged or shown at runtime. `neo_theme`, the one caller outside this package
(`neo_base/src/NeoBasePreRender.php:273`), is not edited in this plan or as a condition of it. Every
call inside `neo` moves to the static in the same commit; a self-call fails **gate-level clean**.

**Why it needs recording.** It contradicts the nearest precedent: `neo_toolbar`'s **gate forwarder**
is the same shape, a surviving global delegating to moved code, and `neo-toolbar-hook-classes` left
it undeprecated because a forwarder called once per consulting block would log eleven deprecations
per admin page on sites whose maintainers did not ask for it. A reader who knows that will call
this an oversight. The globals differ in one way: nothing outside `neo_toolbar` calls the gate
forwarder, so a deprecation there had no audience; `neo_table_props()` has a known caller in another
repository, and expand–contract without a signal to the consumer is just an extra function. The
docblock is the smallest signal that reaches a tool and not an operator.

**Rejected.**
- A runtime `@trigger_error`, as Drupal's policy asks — once per Views table pre-render, on every
  admin listing, on every site, for a move nobody asked for; the one beneficiary reads the source.
- No deprecation, matching the **gate forwarder** — leaves `neo_theme` no reason to ever stop, and
  the backlog candidate asked for a deprecated shim; a second permanent path to the same data.
- Edit `neo_theme` now and delete the global — its `jacerider/neo: ^1` has no minimum and the `neo`
  version to name did not exist (a theme could fatal on old `neo`); its checkout also sat detached.
- Keep the global as the implementation, no static — a `#[Hook]` class depending on a `.module`
  file having loaded, the friction the plan exists to remove and what `FormAlterHook` always did.

**Cost.** A `function.deprecated` finding planted in a repository this plan does not open — the
site's `phpstan.neon` covers `neo_theme/neo_base` with deprecation rules on — that no ticket's
`lint` gate sees, nor any operator. `neo` carries the global until every site runs a `neo_theme` tag
that no longer calls it; dropping it sooner breaks every installing site. On 2026-08-27 `neo_theme`
did migrate (`821053d` on `develop`, unreleased, requiring `jacerider/neo: ^1.0.139`): the call sat
at `:144` and `:357`, not `:273`, so two findings, both cleared; the ordering objection dissolved
since `Helpers\TableProps` had already shipped in `neo` 1.0.139. The docblock says
`neo:1.1.0`; both shipped in 1.0.139, left as is. Contract waits on a `neo_theme` release.
