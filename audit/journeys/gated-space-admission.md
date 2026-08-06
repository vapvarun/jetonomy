# Journey: gated space admission

**Standing record. Not version-stamped, not a plan, never archived.**

The 1.4.4 journey map died because it was `journey-map-1.4.4.md` in `docs/plans/`
- a document per release, filed as a plan, archived when the plan finished. This
one is updated, never re-authored.

## What this journey is

A site owner gates a space - on a plan, a course, a role, a capability, a trust
level - and expects the right people in, doing the right things, and nobody else.

## Where the expectations come from

The admin screen, verbatim: `includes/admin/views/space-edit.php`, the "How
access rules work" panel. Plus `AccessRule::cap_space_role()`'s docblock.

**Expectations are never derived from `Permission_Engine`.** A test written from
the implementation encodes the implementation's bugs - it would have asserted
that enrolling in a course grants moderation, because that is what the code did
until 1.9.1. The promise is the spec; the code is the thing on trial.

## Promises, and where each is asserted

`tests/functional/AccessRulePromisesTest.php`

| # | Promise (quoted from the screen) | Test |
|---|---|---|
| 1 | "A rule lets people in. It never locks anyone out on its own - visibility and join policy do that." | `test_a_rule_never_takes_away_something_that_worked_before` |
| 2 | "Read ... on a public space that anyone may join, a signed-in member can still post" | `test_a_read_rule_does_not_stop_posting_on_a_public_open_space` |
| 3 | Read, read the other way: on a gated space it admits to reading only | `test_a_read_rule_on_a_gated_space_admits_to_reading_only` |
| 4 | "Participate - Read, plus post, reply, vote and report." | `test_participate_grants_exactly_the_five_it_names` |
| 5 | "Full ... for an ordinary member this behaves exactly like Participate." | `test_full_behaves_exactly_like_participate_for_an_ordinary_member` |
| 6 | "A rule can never hand out moderation." | `test_no_grant_level_hands_out_moderation_to_a_role_without_it` |
| 7 | "To give one person a different role, change it on the Members tab ... rather than a side effect of a rule." | `test_no_rule_writes_moderator_or_admin_to_the_roster` |
| 8 | "'Sync Members' is only needed if you also want these people listed on the roster." | `test_a_rule_admits_without_putting_anyone_on_the_roster` |
| 9 | "Access begins the moment a plan becomes active and ends when it lapses - nothing to undo by hand." | `test_access_ends_by_itself_when_the_rule_no_longer_matches` |

## Proven to bite

Run against the pre-fix adapters (`f201dc8~1`), promise 7 fails alone:

```
✘ No rule writes moderator or admin to the roster
  a rule put an elevated role on the roster; roles are a per-person
  decision on the Members tab
```

That is the real escalation from Basecamp 10169081143, caught by a sentence the
admin screen had been showing owners for months.

Note promise 6 stays GREEN against the same broken code. It uses a `role` rule,
so the adapter path never runs. Two different mechanisms reach the roster and
only promise 7 covers the enrolment one - which is exactly why "we test
permissions" was not the same as "we test this".

## Open product question

Promise, same screen: *"Read is listed as Viewer, Participate as Member, **Full
as Moderator**."*

But `cap_space_role()` caps **membership** rules at `member`, deliberately -
"nobody should become a space moderator by buying something". So a membership
rule with Full grants puts `member` on the roster while the screen says
`Moderator`. The panel does not distinguish rule types, so one of the two is
wrong.

Not asserted until the owner decides which. Deliberately left as a gap rather
than encoded either way.

## Coverage gaps in this journey

Not yet asserted, in rough priority:

- subscribe: a non-member can subscribe to a space they get 403 on, and then
  receive its titles and reply excerpts by email. Verified by hand 2026-08-05,
  not yet guarded.
- suspension and refund paths - "ends when it lapses" is only tested by deleting
  the rule, which is the closest free-only analogue, not the real lapse.
- hidden spaces: every row here uses public or private.
- the banned / silenced / space-banned actors.
- surfaces other than `Permission_Engine::can()` - search, feed, sidebar, RSS,
  digest email. Admission is answered in more than one place
  (`Space::admitted()` is cap-independent), and a promise kept by one surface
  and broken by another is the shape of several past bugs.
