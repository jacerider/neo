# 0008 — The **class list parser** never generates a key from a line's position

**Status:** accepted · **Date:** 2026-08-25
**Context:** `neo` — the **class list parser** and the **class key rule** it is handed
**Issue:** jacerider/neo#5

**Decision.** The **class list parser** accepts a list that mixes `class|Label` lines with bare
`class` lines, and it never generates a key from a line's position in the list. The guard that would
reject such a mix — `if ($explicit_keys && $generated_keys) { return; }` — and the comment above it,
"We generate keys only if the list contains no explicit key at all", are deleted rather than made to
work.

**Why it needs recording.** Both copies of the parser were taken from core's
`ListItemBase::extractAllowedValues()`, and both kept the comment and guard. In both the guard is
dead: `$generated_keys` is initialised to `FALSE` and never assigned again, which static analysis
reports as `Right side of && is always false`. That reads as a copy-paste mutation — a rule the
module meant to have and lost. It is not. Core sets `$generated_keys = TRUE` in exactly one place, a
third branch, `elseif (!$has_data) { $key = (string) $position; … }`, which invents a key from the
line's position when the line cannot supply one and the field has no stored data. `neo`'s copies
deliberately lack that branch: a **class list** key is a CSS class name written to rendered markup,
`0`, `1`, `2` are not CSS class names, and a widget setting has no `$has_data` to consult. With the
position branch gone nothing can generate a key, so the guard has nothing left to guard — the unused
`$position` in the `foreach` is the visible remnant of the branch that was dropped. The guard is not
unimplemented; it is inapplicable. Implementing it means re-introducing the one piece of core's
behaviour that makes no sense here.

**Rejected.**
- Make the guard fire — set `$generated_keys` in the bare-value branch so `primary|Primary` mixed
  with a bare `secondary` is rejected. Not core's rule: core sets `$explicit_keys` there too, so it
  accepts the mix. And it changes what roughly thirty sites render: any list authored with both
  forms would silently stop producing a select, the whole list dropped rather than one line, after
  working for the life of this code and being a reasonable thing for an editor to have done.

**Cost.** `neo` owns this parser's rules outright and no longer tracks core's. A future reader
comparing the two files finds the divergence described here rather than a comment that implied an
accident.
