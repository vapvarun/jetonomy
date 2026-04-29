# A4 + A5 — Moderation REST additions (combined)

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** low — additive only, parallel to existing AJAX paths
**Estimated time:** 1.5 days combined
**Reference:** `plan/REST_ACCESS_MATRIX.md` (moderation routes section)

## Why combined

Both endpoints live in `includes/api/class-moderation-controller.php`. Bundling avoids merge conflicts and keeps verification on one route per commit. Two consecutive commits, one work session.

---

## A4 — `POST /moderation/bulk`

### Problem

Bulk moderation today is AJAX-only (`wp_ajax_jetonomy_bulk_content_action`). Frontend custom moderation tools that talk REST can't bulk-approve / -spam / -trash. Adds REST parity without removing AJAX.

### Endpoint contract

```
POST /wp-json/jetonomy/v1/moderation/bulk
Headers: X-WP-Nonce + jetonomy_moderate cap
Body: {
  "action": "approve" | "spam" | "trash",
  "items":  [ { "type": "post"|"reply", "id": 123 }, ... ]
}
Response 200: { "results": [ { "type":"post", "id":123, "status":"ok" }, { "type":"reply", "id":456, "status":"already_approved" }, ... ] }
Response 403: jetonomy_moderate not held
Response 400: invalid action / empty items / item type unknown
```

### Implementation

In `Moderation_Controller`, add:

```php
register_rest_route( $this->ns, '/moderation/bulk', [
    'methods'             => WP_REST_Server::CREATABLE,
    'callback'            => [ $this, 'bulk_action' ],
    'permission_callback' => [ $this, 'moderator_only' ],  // existing helper, asserts jetonomy_moderate
    'args' => [
        'action' => [ 'required' => true, 'enum' => [ 'approve', 'spam', 'trash' ] ],
        'items'  => [ 'required' => true, 'type' => 'array' ],
    ],
] );
```

Handler iterates `items`, dispatches per-item to existing single-item handlers (`approve_item`, `spam_item`, `trash_item`), returns aggregated result. **Don't bypass the per-item logic** — call the existing methods so any future per-item business rule (e.g., notify-reporter) runs identically.

### Parity test (the safety check)

Reuse existing AJAX path side-by-side. Flag 5 comments. Approve via REST. Reset, re-flag 5, approve via AJAX. Compare `jt_flags.status_after_action` rows — must be byte-identical.

```bash
wp jetonomy flag list --format=ids | head -5 | xargs -I {} curl -X POST \
  http://forums.local/wp-json/jetonomy/v1/moderation/bulk \
  --cookie "$(wp user generate-cookie test_moderator)" \
  -d '{"action":"approve","items":[{"type":"post","id":{}}]}'
```

### Access matrix update

Add to `plan/REST_ACCESS_MATRIX.md` under "Moderation routes":

```
| `/moderation/bulk` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
```

---

## A5 — `GET /posts/{id}/flags`

### Problem

Moderators can list ALL flags via `/moderation/flags`. They can resolve a specific flag via `/moderation/flags/{id}/resolve`. But there's **no way to ask "show me the flags for THIS post"** — the workflow of "I'm looking at post X, are there flags I should see?" requires the mod to manually filter the global flag list.

### Endpoint contract

```
GET /wp-json/jetonomy/v1/posts/{id}/flags
Headers: X-WP-Nonce + jetonomy_moderate cap
Response 200: [ { "id": 1, "post_id": 123, "user_id": 456, "reason": "spam", "created_at": "...", "status": "open" }, ... ]
Response 403: jetonomy_moderate not held
Response 200 + []: post has no flags (empty array, not 404)
```

### Implementation

Add to `Moderation_Controller` (or `Posts_Controller` — your call; moderation-controller is more cohesive):

```php
register_rest_route( $this->ns, '/posts/(?P<id>\d+)/flags', [
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => [ $this, 'get_post_flags' ],
    'permission_callback' => [ $this, 'moderator_only' ],
    'args' => [
        'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
    ],
] );
```

Handler reuses existing `Flag::find_for_post( $post_id )` model method. **Don't write new SQL** — match what `/moderation/flags` returns row-shape-wise so frontend can swap without re-mapping fields.

### Access matrix update

```
| `/posts/{id}/flags` | GET | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
```

---

## Shared safety checks

For both A4 and A5:

1. **PRE baseline:** `bin/access-matrix-check.sh --baseline` (already saved from A3 — just verify same)
2. **Implement** — one commit per route (A4 first, then A5)
3. **POST runner:** `bin/access-matrix-check.sh --diff-baseline` — must be 72/72 with new routes added (after extending the runner to cover them)
4. **POST mode test:** `bin/access-matrix-check.sh --mode=private` — new routes still pass; mod cookie required regardless of mode
5. **Smoke:** `wp jetonomy qa-actions run` — 210/210 green
6. **Manifest refresh:** next `/wp-plugin-onboard --refresh` will pick up the 2 new endpoints; coverage gate must stay ≥95%

## Commits

```
1. feat(rest-mod): POST /moderation/bulk parity with bulk AJAX action (A4)
2. feat(rest-mod): GET /posts/{id}/flags for per-post flag inspection (A5)
3. test(matrix): extend runner with 2 new moderation rows (A4/A5)
```

## Done criteria

- [ ] Both endpoints registered, callable, return correct shapes
- [ ] AJAX bulk path still works (parity)
- [ ] Runner extended; passes in both modes
- [ ] Access matrix updated with the new rows
- [ ] Smoke 210/210
- [ ] CHECKLIST marks A4 + A5 done with SHAs
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't remove the existing AJAX `wp_ajax_jetonomy_bulk_content_action` handler — frontend admin UI uses it
- ❌ Don't introduce a new permission check; reuse `moderator_only` (or whatever the existing class helper is named)
- ❌ Don't return 404 for "no flags" — return `[]` with 200 (HTTP semantics: resource exists, just no related items)
