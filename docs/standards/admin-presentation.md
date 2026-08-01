# Admin Presentation Standard (Free + Pro)

**Normative.** Every Jetonomy wp-admin surface — free plugin, Pro extensions, and future screens — follows this ONE spec. It exists because wave after wave of QA found per-screen drift: the same table pattern built five slightly different ways, touch targets ranging 22–44px, cards with three padding rhythms. Fix classes of problems here, not instances there.

Enforced by: `bin/audit-admin-tables.php` (release gate, shrink-only baseline), the shared renderer/primitives below, and the smoke runbook's admin viewport sweep. The reference implementations live in free `assets/css/admin.css` and `includes/helpers.php`.

## 1. Page shell

- Every screen renders inside `<div class="wrap jetonomy-admin">`. **No exceptions** — the wrapper is what delivers every rule below to the page. (Wave-6 lesson: Analytics and Extensions missed it, so none of the shared rules reached them.)
- Screens must be verified at **320 / 390 / 768 / 1024 / 1440**, populated AND empty. 1024 matters because the open admin menu leaves ~820px of container — never key layout on the viewport when the container is what constrains you (the width classes use container queries for exactly this).

## 2. Cards

- Sections render as `jt-settings-card` with a `__head` (`__title` + `__desc`). One padding rhythm — the card primitive's own. Never hand-roll a bordered `<div>` section.
- **Expert/secondary sections** use the disclosure variant: `<details class="jt-settings-card jt-settings-card--disclosure">` with the head as `<summary>`. Open state reflects whether the section is active (an enabled provider/feature opens; dormant collapses). Native `<details>` gives keyboard semantics for free. Reference: the AI settings tab.
- Core `.form-table`s inside cards stay stacked through the 783–1100px squeeze band (shipped rule) — never let a label+input row clip against the card edge.

## 3. Tables — exactly four sanctioned patterns

| Pattern | When | What you get |
|---|---|---|
| `jetonomy_admin_table()` | Any data list the helper can express | Card wrap, scroll containment at every width, core collapse contract (`column-primary`, `data-colname`, `toggle-row` + `aria-expanded`), width classes, empty-state handling — all by construction |
| Hand-rolled + full contract | Sortable headers / bulk check-column / inline edit the helper can't express | You implement the SAME collapse contract by hand (see `users.php`, `tags.php`, `content.php`, `replies.php`) and mark the `<table>` line `jetonomy-audit-table-ok` with the reason |
| `jt-settings-matrix` | Editable config grids (permissions, email toggles) | Stacked labelled field rows ≤782px; wide role-per-column matrices additionally wrap in `.jt-matrix-scroll` |
| `.jt-content-table-wrap` around a plain table | Legacy/transition only | Card chrome + scroll containment; **queue it for migration** (guard baseline is shrink-only) |

Hard rules for all four: no inline pixel widths (width classes only — they yield to `auto` when the container can't afford them); no data reachable only at some widths (hidden columns need the expander); no table that can widen the document at any width; empty states use `jetonomy_admin_empty_state()` and never render under a meaningless header row.

## 4. Forms

- Grouped sibling inputs use `.jt-field-pair` — each field gets a visible programmatic `<label>` (placeholder text is NEVER the only name), 12px gap, stacks on narrow cards.
- Every control has an accessible name: a real `<label for>`, or `aria-label` that names the CONTEXT ("Enable or disable Polls", "Condition metric") — repeated rows get per-row names, not one shared string.

## 5. Target ladder (measured, not aspirational)

| Context | Floor |
|---|---|
| Mobile (≤782px) interactive rows/controls | **44×44** |
| Desktop regular controls (buttons, tabs, pagination, selects) | **40px** |
| Desktop dense in-table actions (documented exception) | **34px** |
| Inline prose links inside data cells | text line-height (WCAG inline exception) |

The floors are shipped as shared CSS on `.jetonomy-admin` — a new screen inherits them by using the shell. When a floor changes an element's box (the 40px pagination pills), align its whole row on one flex baseline; never leave siblings on the old baseline.

## 6. Text and color

- Metadata/secondary text: ≥11px and ≥4.5:1 on its background (`#64748b`-on-white is the floor of the slate ramp; `#94a3b8` fails).
- No raw hex in NEW CSS — `--jt-admin-*` tokens (existing hexes migrate opportunistically).

## 7. Overlays

- One modal primitive for every admin overlay: `role="dialog"` + `aria-modal`, labelled title, initial focus on the Close control, trapped Tab order, Escape closes (bound inside any iframe the dialog hosts), opener focus restored, phone edge gutter, action buttons on the ladder. Reference: the email-preview dialog in `admin-settings.js`. (Consolidating Category/Tag/Badge modals onto it is tracked on Basecamp 10150582851.)

## 8. Expander (collapse) contract details

- The toggle is a real `<button class="toggle-row" aria-expanded="false">` with a screen-reader label; `admin.js` stamps the attribute onto core-rendered toggles (WP_List_Table screens) and syncs it on toggle for everything.
- Expanded rows render label/value in SEPARATE lanes (label is a static flex item — never core's absolute label lane over our flex values).

## 9. Definition of done for any admin change

1. Uses the primitives above (or extends THEM — improvements land in the primitive, not on one screen).
2. `php bin/audit-admin-tables.php` passes on both trees; baseline never grows.
3. Browser-verified at the five widths, populated + empty, with measurements (document width at viewport, targets on the ladder, no clipped/letter-wrapped headings).
4. Screens changed adjacent to yours re-checked — presentation drift is a class bug, not a screen bug.
