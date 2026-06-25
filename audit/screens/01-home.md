# Community home — `/community/`

Reached by:        anyone (public). Anonymous visitor is the make-or-break first impression.
Renders via:       Template_Loader 'home' route → spaces grouped by category + sidebar (login/trending/top-members/tags).
Assessed:          anonymous, desktop, Reign theme, populated demo data (structural snapshot; pixel pass pending).

## What a real first-time visitor expects here
A reason to care in the first 5 seconds: what is this community, who's in it, is it active, and where do I start. Then a scannable, *curated* set of active places to go.

## What's actually here (good)
- Spaces grouped by category, each card showing type badge (Q&A / Forum / Feed / Ideas), description, post + member counts.
- Sidebar: login/register, Trending (votes·replies), Top Members (reputation), Popular Tags.
- Clear community nav + mobile bottom-nav; skip-to-content; footer.

## Gaps found (expectation vs delivered)

1. **No welcome / value proposition for newcomers.** An anonymous visitor lands on a wall of ~30 space cards with no hero, no one-line "what this is," no community pulse ("X members · Y posts this week"). Discourse/Circle/Skool all greet a first-timer. This is the single biggest first-impression gap. *(High)*
2. **Empty + abandoned spaces render identically to active ones.** Many cards show "0 posts · 0 members". There's no de-emphasis, collapse, or "hide empty spaces" curation — the page is dominated by dead space. A visitor reads "unmaintained." *(High)*
3. **No activity-recency signal.** Cards show *total* posts/members but never "last active 2h ago." A newcomer can't tell which spaces are alive — the most important signal for deciding where to engage. *(High)*
4. **Demo/test cruft is public.** "Smoke Test Category / Smoke Test Space", "Collector Space 1780498579980", "Space CF Test 1780494563913", "Test Forum Alpha", "Main Category / Main Forum", "Imported from Asgaros / wpForo". *(Demo-data hygiene — but it also proves #2: there's no owner curation/ordering to push these down.)* *(Med — data; the underlying "no curation" is High)*
5. **No sort or filter on the space list.** ~30 spaces in fixed category order; a newcomer can't sort by active/popular or filter by type (show me Q&A only). *(Med)*
6. **Duplicate space titles.** Two spaces both render as "Show & Tell" (slugs `showcase` + `show-and-tell`). Confusing to a visitor. *(Low — data, but UI offers no disambiguation)*
7. **Generic "Community" H1.** No community identity/branding in the content area beyond the theme chrome. *(Low)*

## Visual contract (to confirm in the pixel pass — screenshot tooling was flaky this run)
- Space-card grid must not overflow at 390px; type badges legible; counts not clipped.
- Login widget title contrast (fixed this session) holds on this page too.

## Breaks when
- Owner has many empty/seeded spaces (this site) → page reads as dead. Gaps #2–#4.
- Brand-new community (no posts) → home is an empty wall with no onboarding. Gap #1 (worst case).
