# Wbcom Date/Time & Timezone Standard

**Status:** Normative. Applies to every Wbcom WordPress plugin/theme that stores,
schedules, compares, or displays a date/time (scheduled publishing, backdating,
"X ago" stamps, digests, expiry windows, audit logs).
**Version:** 1.0 (2026-06-24). Distilled from the Jetonomy scheduled-posts
timezone fix; reference implementation in Section 6.

This is the single source of truth. Each plugin keeps a synced copy at
`docs/standards/datetime-timezone.md` and a one-line pointer in its `CLAUDE.md`.
Do not re-derive the rules per plugin — link here.

## 1. The one principle

**Store UTC. Interpret and display in the WordPress site timezone
(Settings → General). Never trust the server clock or the browser clock as the
source of truth.** A user who picks "3 PM" means 3 PM in the site's timezone,
the same as the core post scheduler — regardless of where the author is or where
the server runs.

## 2. The contract

| Stage | Rule |
|---|---|
| **Storage** | All datetime columns store **UTC** (`Y-m-d H:i:s`). Never store site-local in the canonical column. |
| **Input — naive value** (`2026-06-19T15:00:00`, no offset) | Interpret in the **site timezone** via `wp_timezone()`, then convert to UTC for storage. |
| **Input — offset/Zulu value** (`...+05:30`, `...Z`) | Honour the explicit offset (it is an absolute instant); convert to UTC. |
| **Input — date-only** (`2026-06-19`) | Midnight **in the site timezone** (use a `!`-prefixed format so unparsed time fields reset to 00:00:00, not the server's current time). |
| **Comparison** (cron "is it due yet") | Compare UTC-to-UTC. `now()` must be `current_time( 'mysql', true )` (UTC). |
| **Display** | Convert UTC → site-local with `get_date_from_gmt()` or `wp_date()`. **Never** `date_i18n( $fmt, strtotime( $utc ) )` — it prints the raw UTC clock. |
| **Client (JS)** | Send the plain wall-clock value the user picked. Do **not** stamp the browser timezone — the server owns timezone interpretation. |

## 3. Why these exact helpers

- `wp_timezone()` — the site timezone as a `DateTimeZone`. The authority for "what does a naive time mean".
- `get_date_from_gmt( $utc_string, $format )` — canonical UTC→site-local formatter. The display counterpart of `get_gmt_from_date()`.
- `wp_date( $format, $timestamp )` — timezone-aware formatter when you already hold an absolute timestamp.
- **Leading `!` in `DateTimeImmutable::createFromFormat`** — resets every field not present in the format to the Unix epoch, so a date-only input yields `00:00:00` instead of leaking the server's current time-of-day.
- `setTimezone( new DateTimeZone( 'UTC' ) )` **before** `format()` — when the parsed value carries an offset, formatting without this keeps the local wall-clock and stores the wrong instant.

## 4. Anti-patterns (delete on sight)

1. `date_i18n( $fmt, strtotime( $utc_string ) )` to display a stored datetime → prints UTC, not site-local.
2. Parsing a naive scheduler value as UTC → publishes early/late by the site offset.
3. Stamping the **browser** timezone in JS and treating it as the scheduled timezone → wrong for any author not in the site's zone, and non-deterministic across devices.
4. `createFromFormat( 'Y-m-d', $date )` without `!` → time-of-day leaks the server clock.
5. Comparing a UTC column against a site-local `now()` (or vice-versa) in a cron due-check.

## 5. Verification checklist (run before marking any date/time work done)

- [ ] Set the site to a non-UTC zone (e.g. `America/New_York`) and confirm a scheduled "3 PM" stores as the correct UTC instant and **displays back as 3 PM**.
- [ ] Confirm an explicit-offset payload (`...+05:30`) and a `Z` payload both store the correct UTC.
- [ ] Confirm a date-only input stores midnight **site-local**, not the server time.
- [ ] Confirm the cron due-check fires at the intended wall-clock moment in the site zone.
- [ ] Restore the site timezone after testing.

## 6. Reference implementation (Jetonomy)

- **Input normalization:** `includes/api/class-base-controller.php` → `sanitize_backdate()` (naive → `wp_timezone()`, offset/Z honoured, `!`-reset, `setTimezone( UTC )`).
- **Storage/compare:** `includes/functions.php` → `now()` = `current_time( 'mysql', true )`; `Post::get_due_scheduled()` compares UTC-to-UTC.
- **Display:** `templates/views/user-profile.php` scheduled badge → `get_date_from_gmt()`.
- **Client:** `assets/js/view.js` composer sends the plain wall-clock string; no browser-offset stamping.
