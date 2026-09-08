# Changelog

## The Linkit seam is a service, and the read path resolves through neo's fork

_Released in 1.0.139 — 2026-08-27._

**The Linkit seam is a service, and the read path resolves through neo's
fork.** `neo.linkit_resolver` is declared in `neo.services.yml` with nine
arguments. `NeoLinkitTrait` and `NeoLinkitFormatterTrait` delegate to it
without a signature change. The **link read path** now resolves a stored
uri through neo's fork rather than upstream `LinkitHelper`, so a uri
whose first segment glues a colon to a host no longer resolves to an
entity. `drupal/linkit` became a hard Composer requirement and the inert
module guards came out. The same **link read path** first learned the
`internal:` and `base:` schemes in `1.0.130` — 2026-07-23. See
`docs/adr/0007-the-linkit-seam-becomes-neos-first-service.md`.

`NeoLinkitForkDivergenceTest`, `NeoLinkitFormatterUrlTest`,
`NeoLinkitUserInputTest`, `NeoLinkitEntityFromUriTest` and
`NeoLinkitEntityAttributesTest` are the kernel coverage under `tests/`.
`NeoLinkitUriStringTest` is the unit coverage.

## The quantity plus/minus widget lives in `neo_commerce` now

_Released in 1.0.139 — 2026-08-27._

**The quantity plus/minus widget lives in `neo_commerce` now.** A form
display still pointing at `quantity_plus_minus` loses its field
silently: the add to cart form renders, returns 200, and quietly has no
quantity input. `neo_update_11001()` throws an `UpdateException` naming
every affected display, with the remedy
`composer require jacerider/neo_commerce:^1.0 && drush en neo_commerce`.
A site that never runs the update still sees a `REQUIREMENT_ERROR` from
`neo_requirements()`, listing the same displays. Nothing is installed
automatically.

## Drupal 11.3 is the floor, and the menu-link submit fork survives 11.4

_Released in 1.0.139 — 2026-08-27._

**Drupal 11.3 is the floor, and the menu-link submit fork survives 11.4.**
`core_version_requirement: ^11.3` in `neo.info.yml`. The fork now
installs on `MenuUiHooks::formNodeFormSubmit` rather than the procedural
hook 11.4 removed, which is why the icon stopped being saved. See
`docs/adr/0009-neo-requires-drupal-11-3-and-so-does-the-stack.md`.

`MenuLinkFormHooksTest` pins the fork on both handler names.

## An invalid class list yields no options

_Released in 1.0.139 — 2026-08-27._

**An invalid class list yields no options.** One parser under
`src/Helpers/` sits behind both `getClassList()` methods. A list whose
only line breaks the class key rule still discards the whole list;
callers now receive `[]` instead of NULL, so the link widget's Style
select renders its placeholder instead of fataling the node form. The
class key rule stays overridable per surface. A saved CSS class is
cast to an array before the widget implodes it, so re-opening a link
with the class setting on no longer white-screens either branch. See
`docs/adr/0008-the-class-list-never-generates-a-key-from-a-lines-position.md`.

`ClassListHelperTest` pins the empty return. `ClassListDelegationTest`
pins that each surface still hands in its own rule.

## `neo_table_props()` is deprecated, and still called

_Released in 1.0.139 — 2026-08-27._

**`neo_table_props()` is deprecated, and still called.** The body moved
to `Helpers\TableProps::get()`. The global in `neo.module` is one line
of delegation, deprecated by docblock alone so no notice fires. Removal
waits on a `neo_theme` release. See
`docs/adr/0010-the-table-props-global-is-deprecated-but-never-fires.md`.

`TablePropsDelegationTest` pins the silent deprecation.
`TablePropsShimTest` pins that the global still answers the static.

## Every hook is a class, and the collector stops mistaking a helper for one

_Released in 1.0.139 — 2026-08-27._

**Every hook is a class, and the collector stops mistaking a helper for
one.** The observable half is `neo.skip_procedural_hook_scan: true`.
Without it, core's collector reads `neo_table_props()` as an
implementation of a `hook_table_props` that does not exist. Extension
loading is unaffected, so `neo.module` is still included.

`TokensHooksTest` pins the skip parameter.

## A table column can be pinned to an edge

_Released in 1.0.138 — 2026-08-25._

**The `neo_sticky` table prop offers `none`, `left` and `right`.** Every
Views table style gets a per-column select in the Views UI and a persisted
schema key, the same way `neo_style` and `neo_size` already did.

**It is flagged `'apply' => FALSE` and skipped by the table preprocessor.**
Pinning is cross-column: it implies pinning everything between the target
and the edge and needs a per-column offset, so the class is resolved
downstream rather than stamped per cell. The prop now lives in
`src/Helpers/TableProps.php`.

`TablePropsHelperTest` pins the option map and the apply flag.
`ThemeHooksTest` pins that the preprocessor does not stamp `sticky--left`.

## A foreign style or size value no longer reaches the options-buttons widget

_Released in 1.0.135 — 2026-08-10._

**`NeoOptionsButtonsWidget` now validates style and size against its own
option maps.** Switching widget types in the Manage form display UI leaves
the previous widget's settings behind, filtered by key only, so a shared
key name can carry a foreign value into this widget's settings form,
summary and element. `getStyle()` returns the empty default when the
stored style is not one of ours; `getSize()` returns the widget's default
size when the stored size is not.

## Autocomplete labels survive commas and their own encoding

_Released in 1.0.129 — 2026-07-23 / 1.0.133 — 2026-08-06._

**Both halves are tom-select mangling a label it was handed.** 1.0.129 has
`NeoEntityAutocompleteMatcher` decode HTML entities on the match label
before the results go out, so tom-select's own escaping does not turn an
apostrophe into the literal `&#039;`. Markup for display stays on the
separate `option` key.

**1.0.133 stops tom-select tearing a tag label on a comma.** The widget
now explodes, encodes and splits tags the way Drupal's `Tags` helper
does, seeds the initial options from the input value instead of letting
TomSelect split it, and keeps stored values encoded, decoding them only
for display.

## A vocabulary can require a term description

_Released in 1.0.132 — 2026-07-31._

**`neo_taxonomy` gains a `description_required` third-party setting.** A
"Require Description" checkbox on the vocabulary form persists it, and
the bundle-field hook marks the taxonomy term description base field
required when that vocabulary has asked. The hook clones the base
definition so one vocabulary's setting does not leak into every other.

`TaxonomyHooksTest` pins that the description is required only where the
vocabulary asked; `TaxonomyFormHooksTest` pins the checkbox and the save.

## Slide menus respect link access and bubble their tree's cacheability

_Released in 1.0.131 — 2026-07-28._

**Access is the correctness half; cacheability is the half that decides
whether a cached page shows a link it should not.** The slide menu
element now skips tree elements whose access is denied. Core's
`checkAccess()` keeps those in the tree as an `InaccessibleMenuLink`
whose title is the literal string "Inaccessible", which used to leak a
placeholder row. It collects the access and link cacheability of every
element, including the ones it skips, and merges that metadata with the
element's own cache rather than calling `applyTo()` alone, which would
drop the `config:system.menu.*` tags added in the same loop. Previously
the menu render-cached with no user variance, so the first request to
build it fixed that markup for every later viewer.

`SlideMenuElementTreeWalkTest` pins the skip, the collection and the
merge.

## Slide menus gain an item alter hook and inline mega-menu expansion

_Released in 1.0.127 — 2026-07-15._

**`hook_neo_slide_menu_item_alter` runs once per slide menu item** so a
module can enrich, replace or drop a row (NULL drops it). A row can
carry `content` and render a render array in place of a link.

**Expand depth renders children inline as grouped headings past a given
depth** instead of opening a new slide level. A **special route token**
with children becomes a focusable button, and the **view-all row** is
skipped for it.

`SlideMenuItemBuilderTest` and `SlideMenuControlRowsTest` pin the item
builder, expand depth and control rows. `SlideMenuElementTreeWalkTest`
pins that the alter hook can enrich or drop an item.

## A link can point at a special route token

_Released in 1.0.126 — 2026-07-09._

**`<nolink>`, `<none>` and `<button>` are stored as `route:<nolink>` and
shown back as the bare token.** Treating them as internal paths
URL-encoded the angle brackets into a 404. The widget displays the token
form so the value is not lost on edit. The formatter schema gains
`linkit_profile`.

`NeoLinkitUriStringTest` pins the three tokens becoming `route:` uris.

## A substituted URL keeps its query string and fragment

_Released in 1.0.125 — 2026-05-19 / 1.0.129 — 2026-07-23._

**The substituted URL is built from the entity alone, and therefore
arrives without the stored uri's uri tail.** The **link read path**
re-applies that **uri tail**: 1.0.125 the fragment, 1.0.129 the query
string. Both compose, so a uri carrying `?market=1#hello` keeps both.
The same day's 1.0.130 taught the **link read path** to resolve
`internal:` and `base:` uris the way it already resolved `entity:`
ones, and to refuse an external URL whose scheme colon would otherwise
fatal the entity type manager.

`NeoLinkitFormatterUrlTest` pins the uri tail on a substituted URL.
`NeoLinkitUriStringTest` pins extracting it. `NeoLinkitEntityFromUriTest`
pins the three on-site schemes and the external-URL refusal.

## Three libraries declare the dependencies they were relying on

_Released in 1.0.128 — 2026-07-16._

**`jquery`, `drupal` and `once` are now declared on the libraries that
were already calling them.** The `disable` library gains `once`. The
`autocomplete` library gains all three; it previously only pulled in
Popper, despite the chunk being invoked with `(jQuery, Drupal, once,
Popper)`.

## Releases before 1.0.125 are not recorded

This file starts at `1.0.125` (2026-05-19) deliberately, and the omission
is not a truncation. History below it runs unbroken to `1.0.0` across
more than a year, and reconstructing it honestly would be archaeology
whose early entries would be guesses dressed as record. `1.0.125` is the
line because it is the fragment half of the uri-tail fix whose
query-string half landed in `1.0.129`, so one entry has to reach back
that far regardless. The releases skipped inside the covered range
changed nothing a site can point at: `1.0.140` (five documentation
commits), `1.0.137` (a coding-standard pass), `1.0.136`
(two config-schema declarations plus a docblock — `ee52c6e`,
`a3d6c69`, `e56b037`) and `1.0.134` (one component's border).
