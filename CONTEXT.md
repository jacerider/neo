# CONTEXT — neo

Terms specific to the `neo` base module: view tables, links and Linkit, slide menus,
visibility and smart tokens. One entry per term: what it IS, then the names not to use for it.

## View tables (`neo`)

**Table props** — the three presentation keys `neo` adds to a Views table style — style, size and
align — each with a label and, for the two that offer choices, a fixed option map. They are offered
per column in the Views UI, stored in the table style's own config through a schema alter, and
rendered as `style--heading`, `size--min` and their siblings on the cell. _Avoid:_ "the Neo table
settings", "table options", "column classes".

**Table props shim** — `neo_table_props()`, the global function that answers the **table props**
array. Its body now lives in a static under `src/Helpers/`; the global is one line of delegation,
marked deprecated in its docblock alone so no notice fires at runtime. `neo_theme` migrated to
`Helpers\TableProps::get()`, so `TablePropsShimTest` is now the only code that calls the global —
but released `neo_theme` tags still do, so deleting it waits on a `neo_theme` release rolling out
to every site. See `docs/adr/0010`. _Avoid:_ "the table props function", "the helper" unqualified.

## Links and Linkit (`neo`)

**Linkit seam** — the URI↔entity resolution `neo`'s link widget and link formatter share: turning
what an editor typed into a stored uri, turning a stored uri back into the entity behind it, and
turning that entity into the URL a page renders. _Avoid:_ "the link code", "the Linkit
integration", and naming only one of the two traits when the whole seam is meant.

**Linkit resolver** — the `neo.linkit_resolver` service that *is* the **Linkit seam**: one final,
interface-less object holding every URI↔entity step, which `NeoLinkitTrait` and
`NeoLinkitFormatterTrait` delegate to without changing a signature. _Avoid:_ "the URI helper",
"LinkitHelper" — that is Linkit's own upstream class, which the resolver replaces the use of —
and "the link service".

**Link write path** — the **Linkit seam** running in the save direction: the widget massages what
an editor typed into the uri that is stored, which is an `entity:`, `internal:`, `base:`, `route:`
or `mailto:` uri, or an external URL left as it stands. _Avoid:_ "input handling", "massaging",
"the widget half".

**Link read path** — the **Linkit seam** running in the render direction: the stored uri is
resolved back to an entity and handed to a Linkit substitution plugin, which yields the
**substituted URL** the page links to. _Avoid:_ "output", "formatting", "the formatter half".

**Substituted URL** — the `Url` a Linkit substitution plugin builds from the entity behind a link,
used in place of the stored uri so a renamed or re-aliased entity still resolves. It is built from
the entity alone, and therefore arrives without the stored uri's **uri tail**. _Avoid:_ "the
Linkit URL", "the generated URL".

**Uri tail** — the query string and fragment carried on a stored uri (`?market=1`, `#hello`). The
**link read path** re-applies it to the **substituted URL**, because the substitution drops it.
_Avoid:_ "query params", "the anchor", "the suffix".

**Special route token** — `<nolink>`, `<none>` or `<button>`: a deliberate "links to nothing"
choice, stored as `route:<nolink>` and shown back to the editor as the bare token, never resolved
to a path. _Avoid:_ "nolink", "empty link", "no-destination link".

**Class list** — the newline-delimited setting that fills the CSS-class select an editor picks from:
one line per choice, either `class|Label` or a bare `class` used as both key and label. It is stored
as a string, on `neo`'s link widget as a widget setting and on `neo_menu_link`'s settings as a
settings value. _Avoid:_ "allowed values" (that is core's list-field setting this was copied from),
"the class options", "style list".

**Class list parser** — the one static under `neo`'s `src/Helpers/` that turns a **class list**
string into the options array both surfaces render, returning an empty list for an empty or invalid
string. The two `getClassList()` methods that used to hold a copy each now do nothing but read their
string and delegate. _Avoid:_ "getClassList" (both methods keep that name and neither is the
parser), "the allowed-values extractor", "the class-list trait" — it is a static, not a trait.

**Class key rule** — the one condition a **class list** line must meet to be usable: its key is at
most 255 characters. A line that fails it and carries no `|` makes the whole list invalid. Each of
the two surfaces still owns its own copy of the rule as an overridable method and hands it to the
**class list parser**, so a subclass that tightens it still governs what parses. _Avoid:_ "validation"
(nothing validates the setting at save time — the rule is only consulted at parse time), "the length
check".

## Slide menus, visibility and tokens (`neo`)

**Slide menu** — `neo`'s hierarchical drill-down menu: a `SlideMenu` value object that turns
**slide menu items** into a render array, paired with the `slide_menu` render element that fills
it from one or more Drupal menus. _Avoid:_ "the mobile menu", "the off-canvas menu", and naming
only the class or only the element when the pair is meant.

**Slide menu item** — one row of a **slide menu**: an array carrying a title, a url, children,
trailing content and per-row attribute overrides. A row carrying `content` renders a render array
in place of a link. _Avoid:_ "menu link" — a row is derived from one but is not one — and "entry".

**Expand depth** — the depth at which a **slide menu**'s children stop opening a new slide level
and render inline beneath their parent as a group heading; `0` always slides. _Avoid:_ "mega menu
mode", "inline depth".

**Back row** — the control row a **slide menu** prepends to every submenu whose parent has
children: it carries the back icon and a label naming the parent, and returns the reader to the
level above. Disabling it hides it with a screen-reader class rather than omitting it. _Avoid:_
"back link", "back button".

**View-all row** — the control row a **slide menu** prepends above the **back row**, linking to the
parent's own url so a reader can reach the parent page as well as its children. It is omitted
entirely when the parent has no url or its url is a **special route token**. _Avoid:_ "view all
link", "parent link".

**Slide menu option** — one of the fourteen named keys a **slide menu** accepts in the options array
its constructor takes: the items, the five attribute bags, and the eight scalars naming the child
icon, the **back row**'s icon, label and status, the **view-all row**'s status, prefix and suffix,
and the **expand depth**. The set is closed and stated once, as `SlideMenuOption`'s cases; the
`slide_menu` element spells the same keys with a leading `#`. _Avoid:_ "setting", "config key",
"property".

**Option accessor** — the protected read/write pair on `SlideMenu` that answers a **slide menu
option**'s value for a case and seeds it from the case's default on first use. It is the only code
in the class that knows where an option is stored. _Avoid:_ "getter", "the options bag".

**Option forwarder** — one of the twenty-eight public `get`/`set` methods on `SlideMenu` that do
nothing but call the **option accessor** for their own case, each keeping the name, signature,
visibility and return type it has always had. _Avoid:_ "accessor" alone (that is the protected
pair), "wrapper".

**Visibility** — the condition-plugin contract `neo` lends the rest of the stack: a `visibility`
key on a config entity holding condition plugin configuration, a form that builds, validates and
submits those plugins, and an access handler that resolves them into an access result carrying
their cacheability. _Avoid:_ "conditions", "access rules", "context conditions".

**Visibility consumer** — a class that gains **visibility** or the **values contract** by using
one of `neo`'s four visibility-and-values traits; every one that exists lives in `neo_settings` or
`neo_toolbar`, none in `neo` itself. _Avoid:_ "trait user", "implementer".

**Values contract** — `neo`'s get/set/unset/has interface over a nested array that the consuming
class exposes by reference, so callers address settings by key path rather than by array index.
_Avoid:_ "settings API", "config values".

**Smart token** — a `[neo:*]` token that resolves the best title, description, logo or image for
whatever entity is in scope, falling back to site settings and to a module's alter hook. _Avoid:_
"meta token", and "Alchemist token" — the file's own docblock says Alchemist, the tokens are
`neo`'s.

**Slug** — `neo`'s `slug` form element and its field widget: a text value constrained to lowercase
letters, digits and dashes, optionally mirrored from another element on the same form. _Avoid:_
"machine name" — core's `machine_name` element is a different thing — and "path alias".

**Neo helpers** — the static classes under `neo`'s `src/Helpers/`: string casing and machine
names, strict deep array merge, intersect and diff, an admin-context check, and the **class list
parser**. _Avoid:_ "utilities", "the helper service" — none of them is a service.

**Neo test fixtures** — the hidden `neo_test` module: the **visibility consumer** classes that
give `neo`'s four traits something to be exercised through, the menu links and routes a **slide
menu** tree is built from, and the alter-hook implementations the **slide menu** and **smart
token** paths invoke. _Avoid:_ "the test module", "stubs".

