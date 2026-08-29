# 0008 — The class list never generates a key from a line's position

**Status:** accepted
**Date:** 2026-08-25
**Context:** `neo` — the **class list parser** (`docs/plans/neo-class-list-parser/`)

## Decision

`neo`'s **class list parser** accepts a list that mixes `class|Label` lines with bare `class`
lines, and it never generates a key from a line's position in the list. The guard that would
reject such a mix — and the comment above it, "We generate keys only if the list contains no
explicit key at all" — are deleted rather than made to work.

## Why this needs recording

Both copies of the parser were taken from core's
`ListItemBase::extractAllowedValues()`, and both kept that comment and its
`if ($explicit_keys && $generated_keys) { return; }` guard. In both copies the guard is dead:
`$generated_keys` is initialised to `FALSE` and never assigned again, which static analysis
reports as `Right side of && is always false`.

Read on its own, that looks like a copy-paste mutation — a rule the module meant to have and
lost. It is not. Core sets `$generated_keys = TRUE` in exactly one place: a **third** branch,
`elseif (!$has_data) { $key = (string) $position; … }`, which invents a key from the line's
position in the list when the line cannot supply one and the field has no stored data yet.
`neo`'s copies deliberately do not have that branch. A **class list** key is a CSS class name
that gets written into rendered markup; `0`, `1`, `2` are not CSS class names, and a widget
setting has no `$has_data` to consult. With the position branch gone, nothing can generate a
key, so the rule about mixing generated and explicit keys has nothing left to guard — and the
unused `$position` in the `foreach` is the visible remnant of the branch that was dropped.

So the guard is not unimplemented; it is **inapplicable**. Implementing it would mean
re-introducing core's position-key branch, which is the one piece of core's behaviour that
makes no sense here.

## The alternative that was rejected

Make the guard fire — set `$generated_keys` where the bare-value branch runs, so that a list
mixing `primary|Primary` with a bare `secondary` is rejected as invalid.

Rejected for two reasons. It is not core's rule: core sets `$explicit_keys` in that branch too,
so core also accepts the mix. And it changes what roughly thirty sites render: any **class list**
authored with both forms would stop producing a select, silently, with the whole list dropped
rather than one line. Mixing the two forms has worked for the life of this code and is a
reasonable thing for an editor to have done.

## Consequence

`neo` owns this parser's rules outright and no longer tracks core's. A future reader comparing
the two files will find the divergence described here rather than the comment that used to
imply an accident.
