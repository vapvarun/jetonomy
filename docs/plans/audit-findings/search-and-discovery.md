# Audit fixes — search-and-discovery (9 findings)

## 1. [CRITICAL] `search-and-discovery/01-search-filters.md` — wrong-hook
- **Issue:** Doc documents jetonomy_search_filters as a value-returning add_filter that injects query params; it is actually a do_action UI hook. The query-args filter is jetonomy_search_query_args.
- **Fix:** Rewrite the developer section to use add_filter('jetonomy_search_query_args', fn($args){ ... return $args; }) for modifying the search query, and describe jetonomy_search_filters separately as a do_action UI hook (args: $q, $filter, $filters_array) for rendering extra controls in the filter bar.
- **Evidence:** Doc 01-search-filters.md:71-81 uses add_filter('jetonomy_search_filters', fn($filters,$query_args){...return $filters;}, 10, 2). Code templates/views/search.php:263 is do_action('jetonomy_search_filters', $q, $filter, compact(...)) (no return, 3 positional args, renders extra UI). Manifest.json:2092-2098 declares it type 'action', args 'none'. The real query-args filter is includes/api/class-search-controller.php:280 apply_filters('jetonomy_search_query_args', compact('q','space_id','date_from','date_to','author_id','tag_slug','sort')) and manifest.json:2447-2451 type 'filter'. Using add_filter on jetonomy_search_filters as documented does nothing to the query.

## 2. [CRITICAL] `search-and-discovery/02-tags.md` — missing-feature
- **Issue:** Doc documents a 'Jetonomy: Popular Tags' selectable WP widget with Title/Count/Show-count options and hourly transient; no such widget is registered. Popular Tags is only a hardcoded sidebar section.
- **Fix:** Remove the 'Appearance -> Widgets / Jetonomy: Popular Tags' widget section and its config table; instead describe the built-in Popular Tags sidebar block (shows up to 15 tags, toggle via the jetonomy_show_sidebar_popular_tags filter). Do not claim configurable Title/Count/Show-count or hourly transient.
- **Evidence:** Doc 02-tags.md:37-49. Code includes/class-widgets.php:21-24 registers only Recent_Posts_Widget, Leaderboard_Widget, Active_Spaces_Widget, User_Stats_Widget. templates/partials/sidebar.php:76 $popular_tags = Tag::list_popular(15) and :363/:365 hardcoded 'Popular Tags' heading; gated by filter jetonomy_show_sidebar_popular_tags (:358) with no Title/Count/Show-count options and no transient in this code path.

## 3. [MAJOR] `search-and-discovery/01-search-filters.md` — inaccurate
- **Issue:** Doc claims date presets (Last 7 days / 30 days / year); UI has only two date inputs.
- **Fix:** Remove the preset sentence; describe only the Date from / Date to date inputs.
- **Evidence:** Doc 01-search-filters.md:45 'Choose a preset (Last 7 days, Last 30 days, Last year) or set a custom From / To date.' Code templates/views/search.php:236-241 renders only two <input type="date"> fields (Date from / Date to). No preset controls exist in the filter form markup (lines 235-255).

## 4. [MAJOR] `search-and-discovery/01-search-filters.md` — inaccurate
- **Issue:** Doc claims author field auto-suggests members as you type; it is a plain text input resolved server-side on submit.
- **Fix:** Reword to: type a name or username; Jetonomy resolves it to the matching member when you submit the search (no live typeahead).
- **Evidence:** Doc 01-search-filters.md:49 'Jetonomy auto-suggests matching members as you type.' Code templates/views/search.php:246 <input type="text" name="author" ... autocomplete="off"> with no datalist/typeahead. Name resolution happens server-side on submit in search.php:34-50 (get_user_by login, then get_users display-name match). No client-side suggestion JS.

## 5. [MAJOR] `search-and-discovery/01-search-filters.md` — inaccurate
- **Issue:** Doc says Filters is a button with an active-filter count badge; it is a native details/summary disclosure with no badge.
- **Fix:** Replace 'button' with the Filters disclosure/summary, drop the count-badge claim; optionally note it auto-expands when filters are active.
- **Evidence:** Doc 01-search-filters.md:67 'the pill count badge on the Filters button shows how many filters are currently active.' Code templates/views/search.php:226-231 uses <details class="jt-search-filters"> with <summary class="jt-search-filters-toggle">Filters <chevron-down icon></summary>; the <details> only auto-opens (line 228-230) when any filter is set. No button element and no count badge in the markup.

## 6. [MAJOR] `search-and-discovery/01-search-filters.md` — inaccurate
- **Issue:** Doc tells users to quote a phrase for exact-phrase matching; the boolean builder strips quotes and AND-prefixes each token, so it does not do phrase matching.
- **Fix:** Remove the quoted-phrase tip; explain that all words are required (AND) with prefix matching, and that short words (<4 chars) are ignored.
- **Evidence:** Doc 01-search-filters.md:24 Tip about wrapping query in quotes for exact phrase. Code includes/search/class-fulltext-search.php:181 $cleaned = preg_replace('/[+\-<>()~*"@]/',' ',$query) strips double-quotes and operators, then 184-191 tokenizes on whitespace and builds '+'.$t.'*' per token (AND-required prefix), with tokens under 4 chars dropped. A quoted phrase becomes individual required prefix terms, not an adjacent-phrase match.

## 7. [MAJOR] `search-and-discovery/02-tags.md` — inaccurate
- **Issue:** Doc describes a pill/typeahead tags UI with Enter-to-add, X-to-remove, live dropdown, and a 5-tag cap; actual field is a plain comma-separated text input with no cap.
- **Fix:** Rewrite to: type comma-separated tag names in the Tags text field; remove the pill/Enter-to-add/X-to-remove/live-dropdown/five-tag-cap claims (none exist).
- **Evidence:** Doc 02-tags.md:15-19. Code templates/partials/compose-fields.php:116-118 renders <input type="text" name="tags"> with hint '(optional, comma-separated)' and placeholder 'e.g. python, django, architecture'. assets/js/view.js:2500-2501 reads [name="tags"] and splits on commas only. includes/api/class-posts-controller.php:576-587 foreach over provided tags with find_or_create + attach_to_post and no count limit.

## 8. [MAJOR] `search-and-discovery/02-tags.md` — inaccurate
- **Issue:** Doc says tag pages offer a 'Most Voted' sort and a date filter; actual tag page has only Latest/Popular pills and no date filter.
- **Fix:** Change 'Most Voted' to 'Popular' (orders by vote score) and remove the 'date filter' claim — the tag page has no date filter.
- **Evidence:** Doc 02-tags.md:33. Code templates/views/tag.php:32-36 sort enum ['latest','popular']; :120-123 pill labels 'Latest','Popular'; :128 $order_by = 'popular' === $sort ? 'p.vote_score DESC' : 'p.created_at DESC'. No 'Most Voted' label and no date-filter control on the tag page.

## 9. [MINOR] `search-and-discovery/01-search-filters.md` — inaccurate
- **Issue:** Doc lists 'Reply content' among what the search-results page searches; the customer-facing results page queries only posts, spaces, and tags.
- **Fix:** Remove 'Reply content' from the results-page list, OR clarify that reply search is available via the REST API only and not surfaced on the /community/search/ results page.
- **Evidence:** Doc 01-search-filters.md:13-18 lists 'Reply content'. Code templates/views/search.php:75-162 queries posts (75-144), spaces (146-148), and tags (150-162) only; no reply query and the filter pills (196-200) are All/Posts/Spaces/Tags with no Replies. Reply search exists only via REST (type=reply) per the search adapter, not on the results page.
