# Jetonomy Per-Feature Wiring Matrix

> Generated 2026-05-31 via a per-feature agentic wiring audit (1 agent per feature, 31 features,
> + 1 master synthesizer). Each feature verified across 6 dimensions — backend flow, admin options,
> frontend, data flow, member access, admin access — grounded in file:line. Verdict per layer:
> ✅ WIRED / 🟡 PARTIAL / ❌ BROKEN. Focus: what EXISTS is wired, not what could be added.

## 1. Executive Summary

Of the 31 features traced, **24 are 100% wired**, **3 are PARTIAL**, and **4 carry a real BROKEN wire**.
Overall wiring health is strong: the entire free core (16 features) is solid except for one missing
admin UI and one capability-name drift, while the Pro suite's defects cluster in newer integration
extensions (AI, Reply-by-Email, Web Push) where backend pipelines were built but the final hop — a JS
action handler, an action-hook listener, or a hook arity — was never connected. The wppqa
"unused_setting" warnings are confirmed false positives (those settings are read via the Settings
service class).

## 2. The Matrix

| Feature | Backend | Admin | Frontend | Data-flow | Member | Admin-access | Overall |
|---|---|---|---|---|---|---|---|
| 1. Spaces & membership (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 2. Categories (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 3. Posts & Discussions (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 4. Replies & Threading (free) | ✅ | ❌ | ✅ | ✅ | ✅ | 🟡 | ❌ BROKEN |
| 5. Votes (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 6. Tags & Discovery (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 7. Reputation & Trust (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 8. Notifications (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 9. Search (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 10. Moderation/flags/bans (free) | ✅ | 🟡 | ✅ | ✅ | ✅ | ✅ | 🟡 PARTIAL |
| 11. Bookmarks (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 12. Member profiles (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 13. Leaderboard & activity log (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 14. Invites/Join/Access-rules (free) | ✅ | 🟡 | 🟡 | ✅ | ✅ | 🟡 | ❌ BROKEN |
| 15. Admin wizard/dashboard/settings (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 16. SEO (free) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 17. Pro: Advanced Moderation | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 18. Pro: AI Integration | ✅ | ✅ | 🟡 | 🟡 | 🟡 | ✅ | ❌ BROKEN |
| 19. Pro: Analytics | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 20. Pro: Custom Badges | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 21. Pro: Custom Fields | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 22. Pro: Email Digest | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 23. Pro: Polls | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 24. Pro: Private Messaging | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | 🟡 PARTIAL |
| 25. Pro: Emoji Reactions | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 26. Pro: Reply by Email | 🟡 | ✅ | ✅ | 🟡 | ❌ | ✅ | ❌ BROKEN |
| 27. Pro: SEO Pro | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 28. Pro: Site Announcements | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 29. Pro: Web Push | ✅ | ✅ | ✅ | ❌ | 🟡 | ✅ | ❌ BROKEN |
| 30. Pro: Webhooks | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |
| 31. Pro: White Label | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ WIRED |

## 3. Broken Wires (fix now)

### A. Pro: Web Push — notification hook arity mismatch (most severe; feature 100% dead)
- **Dangling wire:** `class-extension.php:69` registers `add_action('jetonomy_notification_created', [$this,'on_notification_created'], 10, 1)` but the core Notifier fires with 6 args. Callback at `class-extension.php:304` reads `$notification->user_id` on what is actually an integer `$notification_id`, so the async push dispatch dies on first property access.
- **Impact:** No push is ever queued/sent. Subscribe/unsubscribe REST, admin settings, service worker all work; the send path is fully severed.
- **Fix:** Change line 69 to `..., 10, 6` and update the signature to `on_notification_created($notification_id, $user_id, $type, $object_type, $object_id)`, use the passed `$user_id` (load the notification by ID if the body is needed) instead of dereferencing an int.

### B. Pro: Reply by Email — action fired but never listened to
- **Dangling wire:** Both inbound paths fire `do_action('jetonomy_create_reply_from_email', $post_id, $user_id, $content, 'reply_by_email')` at `class-extension.php:340`, but a cross-plugin grep finds zero `add_action('jetonomy_create_reply_from_email', ...)`. The journey file (`class-reply-by-email-journey.php:39-44`) documents that free does not subscribe yet.
- **Impact:** Members can email a reply; IMAP/webhook parse, validate token, sanitize body — then the reply silently vanishes. Member layer BROKEN.
- **Fix:** In free, add the listener: `add_action('jetonomy_create_reply_from_email', fn($post_id,$user_id,$content)=>Reply::create(['post_id'=>$post_id,'author_id'=>$user_id,'content'=>$content,'status'=>'publish']), 10, 4);`

### C. Pro: AI Integration — "Use as reply" button has no handler or endpoint
- **Dangling wire:** Reply-suggestion button renders `data-wp-on--click="actions.useAiSuggestion"` (`class-suggester.php:148`), but `actions.useAiSuggestion` is registered nowhere in jetonomy/jetonomy-pro JS, and no REST endpoint turns a suggestion into a reply. Manifest also lists `/ai/suggestions`, `/ai/providers`, `/ai/spam-detection` that don't exist in code (only `/ai/usage` + `/ai/usage/summary` are real).
- **Impact:** Spam detection + suggestion generation work; the member-facing "Use as reply" click does nothing.
- **Fix:** Register an `useAiSuggestion` Interactivity action that POSTs to `POST /jetonomy/v1/posts/{post_id}/replies` (reuse core reply permissions), add `data-post-id` + nonce to the button context in `class-suggester.php`. Correct the manifest endpoint set.

### D. Replies & Threading (free) — `require_approval` has no admin UI
- **Dangling wire:** Controller reads + enforces per-space `require_approval` (`class-replies-controller.php:325-326`), queuing replies to pending, but no admin field renders it in `space-edit.php`. Settable only via direct SQL / hook injection.
- **Impact:** Admins cannot enable reply moderation through WP. Admin layer BROKEN.
- **Fix:** Add a `require_approval` checkbox to the Space Settings tab in `admin/views/space-edit.php` (alongside `who_can_post`/`who_can_reply`), persist via the existing `ajax_update_space()` settings JSON path — no backend change needed.

## 4. Partial Wires (should close)

- **Replies & Threading (free) — split-reply capability drift (Admin-access 🟡):** Space-mod role granted `split_replies` (`class-permission-engine.php:47`) but controller gates split on `move_posts` (`class-replies-controller.php:709`). Works for WP admins; a space-only mod with `split_replies` can be blocked. Align the controller check to `split_replies` (or grant both).
- **Moderation/flags/bans (free) — no admin control surface (Admin 🟡):** Moderation page works, but there are zero settings to toggle flagging, configure auto-trash on valid flag, or set thresholds (`class-admin.php:530-537`). Always-on for `jetonomy_moderate` holders. Not a break — add a settings tab if/when fine-grained control is needed.
- **Invites/Join/Access-rules (free) — capability scope drift (Admin 🟡, Frontend 🟡):** Admin AJAX approve/deny + access-rule handlers gate on global `current_user_can('jetonomy_manage_spaces')` (`class-spaces-handler.php:506,536`) instead of per-space `is_space_admin()` as the REST path correctly does. A per-space admin without the global cap can be blocked from managing their own space's join requests. Align the AJAX handlers to the per-space check. (Frontend join-request surface is also thinner than the REST capability.)
- **Pro: Private Messaging — admin-access 🟡:** No admin moderation/visibility surface over member DMs (expected for privacy, noted as a deliberate gap, not a break).

## 5. Confirmed fully-wired (24)
Spaces, Categories, Posts, Votes, Tags, Reputation/Trust, Notifications, Search, Bookmarks, Profiles,
Leaderboard, Admin wizard/dashboard/settings, SEO (free); Advanced Moderation, Analytics, Custom Badges,
Custom Fields, Email Digest, Polls, Emoji Reactions, SEO Pro, Site Announcements, Webhooks, White Label (pro).

## 6. Cross-cutting wiring risk
The recurring pattern in the 4 breaks is **"last hop never connected"** — backend built, but the final
listener/handler/arity missing: Web Push (hook arity), Reply-by-Email (no listener), AI (no JS action +
phantom manifest endpoints), require_approval (no admin field). All four are newer/integration code.
Recommend a standing check: when an extension fires `do_action`/registers a `data-wp-on` handler, a
test must assert the matching listener/route exists.
