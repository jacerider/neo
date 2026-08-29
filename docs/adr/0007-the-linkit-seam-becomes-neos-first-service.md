# 0007 — The **Linkit seam** becomes `neo`'s first service

**Status:** accepted
**Date:** 2026-08-25
**Context:** `neo` — the **Linkit seam**, the module's complete absence of a `neo.services.yml`, and
the `src/Helpers/` static classes it uses instead
**Plan:** `docs/plans/neo-linkit-seam/`

## Decision

`neo` registers no services at all. It is the base package of the stack — every other Neo package
depends on it — and it has never had a `neo.services.yml`. Where it needs shared logic it uses a
static class under `src/Helpers/`: `Helpers\Str`, `Helpers\NestedArray`, `Helpers\Utilities`.

The **Linkit seam** does not follow that pattern. It becomes the **Linkit resolver**, a final class
with its dependencies injected through a constructor, registered as `neo.linkit_resolver` in a
`neo.services.yml` this plan creates. The two link traits keep every public method they have, with
today's signatures, and delegate into it.

The service id is public from the moment it ships.

## Why this is surprising

A reader who opens `neo` for the first time finds 8,398 lines, 1,760 of them procedural, sixteen
hooks in a `.module` file, three static helper classes — and exactly one registered service, for
Linkit URI parsing. Nothing about the module's shape predicts that. The obvious question is why the
seam did not become a fourth `src/Helpers/` class like the three that came before it, and the answer
is not visible in the diff.

It is more surprising because the module's static helpers are not an accident of age. `Helpers\Str`
and `Helpers\NestedArray` are pure functions and belong exactly where they are; `Helpers\Utilities`
is not pure, and its `isAdmin()` carries a `public static` process-lifetime cache over two `\Drupal::`
calls that no test can reset. The module has therefore already met the cost of the static pattern
once, in the place where it hurts, and kept it.

## Why the seam is different from the three helpers

The **Linkit seam** reaches nine container services — entity type manager, entity repository,
module handler, stream wrapper manager, path alias manager, language manager, config factory, the
request, the Linkit profile storage and the Linkit substitution manager. Nineteen `\Drupal::` static
calls across two traits is the current form of that. A static helper class cannot reduce the count;
it can only move it.

The evidence that this matters is in the code already. `NeoLinkitFormatterTrait` declares three
optional injected properties with fallback accessors — roughly 35 lines whose only purpose is to let
a test substitute a double — and nothing in the stack has ever set one. Somebody wanted the seam
injectable, built the scaffolding for it, and could not finish the job inside a trait, because a
trait has no constructor to inject into and its consumers are field plugins whose constructors are
core's.

The seam is also where the module's bugs are: five fixes in eighteen months, every one of them a URI
losing information, every one found in production rather than by a test. It is the one part of `neo`
where being able to construct the thing under test with stub dependencies is worth a services file.

## What it costs

**A public service id, permanently.** `neo.linkit_resolver` is callable from every site the moment
it ships, and a site that calls it constrains what the class may do next. Renaming or removing it is
a breaking release for roughly thirty sites. A `src/Helpers/` class carries the same exposure through
its class name, but a service id invites use in a way a static helper does not — it is the thing
Drupal tells people to depend on.

**The module gains a file it has done without for its whole life.** `neo.services.yml` will not stay
at one entry. `neo`'s backlog already carries a candidate that wants to move sixteen procedural hooks
into `src/Hook/` classes and register the logic they call, and it names creating this exact file as
part of its own change. This ADR gets there first, with one entry, which makes that candidate's job
smaller and its shape decided rather than open.

**Two ways to reach the seam, for as long as the traits exist.** The traits stay — they are the
public surface `neo_modal` and `neo_alchemist` consume — so every method has a container fallback
behind it for callers that cannot inject. That is one `\Drupal::` call per entry point instead of
nineteen scattered ones, but it is not zero, and a reader will find both forms in the same file.

## Alternatives considered

**A fourth `src/Helpers/` static class.** The module's own pattern, and the cheapest change: no
services file, no new concept, and the traits delegate to statics exactly as `NeoLinkWidget` already
calls `self::getLinkitUriFromUserInput()`. Rejected because it delivers the consolidation and not the
testability. Nine dependencies fetched statically inside a static method is untestable in exactly the
way `Helpers\Utilities::isAdmin()` is untestable, and the candidate that produced this plan is about
a seam nothing can see into. Moving nineteen `\Drupal::` calls into one file and calling it a seam
would be the same code with a better address.

**Leave the logic in the traits and only add types.** Rejected: it does not consolidate anything. The
**link write path** and the **link read path** would still resolve URIs through two different
implementations — one of them Linkit's own upstream helper — so a fix would still have to be applied
twice, which is the failure mode the five bug fixes describe.

**Use the three optional properties as they stand and inject them from every consumer.** Rejected.
It scales with consumers rather than with the seam: `NeoLinkFormatter` can set them from its
`create()`, but `neo_alchemist`'s shape classes and `neo_modal`'s block base would each need their own
wiring, in their own repositories, for a plan scoped to one package. One resolver with one fallback
gives every consumer the injectable seam without any of them changing.

**Register an interface alongside the class.** Rejected on the precedent this stack already set:
`neo_toolbar`'s access gate is final, one public method, no interface, and the glossary records that
shape. An interface is public surface that has to be honoured; a final class can gain methods freely
and be swapped wholesale by a service override, which is how Drupal replaces behaviour anyway. Final
is also the reversible direction — removing `final` later breaks nothing, adding it does.

## Consequences

The **Linkit seam** becomes constructible in a unit test with stubs, which is what lets this plan's
first ticket write `neo`'s first tests at all.

The three vestigial injected properties on `NeoLinkitFormatterTrait` go, replaced by one typed
optional resolver. The scaffolding stops being scaffolding.

The next `neo` plan that needs a service has a file to add a line to and a settled answer about
whether the module may have one. The hook-migration candidate in particular no longer has to argue
the point; it inherits it.

Nothing about the traits' public surface moves, so no consuming package has to change in step. The
service is an addition, and every method that existed still exists with the signature it had.
