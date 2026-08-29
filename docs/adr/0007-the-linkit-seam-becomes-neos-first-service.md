# 0007 — The **Linkit seam** becomes `neo`'s first service

**Status:** accepted · **Date:** 2026-08-25
**Context:** `neo` — the **Linkit seam**, in a module with no services file, only static helpers
**Issue:** jacerider/neo#2

**Decision.** `neo` has never had a `neo.services.yml`; shared logic lives in statics under
`src/Helpers/` (`Str`, `NestedArray`, `Utilities`). The **Linkit seam** breaks that pattern: it
becomes the **Linkit resolver**, a final, interface-less class with constructor-injected
dependencies, registered as `neo.linkit_resolver` in a `neo.services.yml` this plan creates, public
from the moment it ships. The two link traits keep every public method and signature and delegate
into it; `NeoLinkitFormatterTrait`'s three vestigial injected properties become one typed resolver.

**Why it needs recording.** A reader finds 8,398 lines, 1,760 procedural, sixteen hooks in a
`.module` file, three static helpers — and exactly one service. The seam looks like it should have
been a fourth helper; the module already paid the static pattern's cost once and kept it
(`Helpers\Utilities::isAdmin()`: a `public static` process-lifetime cache over two `\Drupal::` calls
no test can reset). The seam is different. It reaches nine container services through nineteen
`\Drupal::` calls across two traits, and a static class can only move that count, not reduce it.
`NeoLinkitFormatterTrait` already carries three optional injected properties with fallback accessors
— roughly 35 lines nothing in the stack has ever set — because a trait has no constructor to inject
into and its consumers' constructors are core's. And the seam is where the bugs are: five fixes in
eighteen months, every one a URI losing information, every one found in production rather than by a
test. Constructing it with stubs is what lets this plan's first ticket write `neo`'s first tests.

**Rejected.**
- A fourth `src/Helpers/` static — consolidation without testability: nine dependencies fetched
  statically is untestable exactly as `isAdmin()` is; the same code with a better address.
- Leave the logic in the traits, only add types — consolidates nothing; the **link write path** and
  **link read path** would still resolve URIs through two implementations, so a fix lands twice.
- Inject the three optional properties from every consumer — scales with consumers, not the seam;
  `neo_alchemist`'s shapes and `neo_modal`'s block base would each need wiring in their own repos.
- An interface beside the class — `neo_toolbar`'s access gate is final with no interface; a final
  class is swapped by service override, and removing `final` later breaks nothing, adding it does.

**Cost.** `neo.linkit_resolver` is public permanently: renaming or removing it is a breaking release
for roughly thirty sites, and a service id invites dependence as a static helper does not.
`neo.services.yml` will not stay at one entry — the backlog's hook-migration candidate names making
this file and now inherits its shape. While the traits exist the seam has two doors: each method
keeps a container fallback for callers that cannot inject — one `\Drupal::` call each, but not zero.
