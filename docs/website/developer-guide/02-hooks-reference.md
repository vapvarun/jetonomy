# Hooks Reference

Jetonomy is built to be extended cleanly - every hook below is a real, supported extension point, generated straight from the source so this page never drifts from the code. Each row says what the hook is for and where it fires. New here? Start with the **[developer cookbook](00-index.md)** for step-by-step recipes (add a profile tab, customise a card, theme with tokens, extend the REST API), and the **[Coming from BuddyPress / BuddyBoss](21-coming-from-buddypress-buddyboss.md)** guide.

**200 hooks**, 133 with a description. `filter` = return a modified value; `action` = run a side effect. Args are listed where documented; the source file always has the full signature.


## Posts

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_post`<br>_action_ | - | - | `includes/class-abilities.php` |
| `jetonomy_after_delete_post`<br>_action_ | Fires after a post is deleted. | - | `includes/api/class-posts-controller.php` |
| `jetonomy_after_post_article`<br>_action_ | Named `_article` (not `_content`) to avoid collision with the existing `jetonomy_after_post_content` FILTER that injects HTML inside the… | `post_id` | `templates/views/single-post.php` |
| `jetonomy_after_post_content`<br>_filter_ | - | `post_id, content` | `templates/views/single-post.php` |
| `jetonomy_after_update_post`<br>_action_ | Fires after a post is updated. | - | `includes/api/class-posts-controller.php` |
| `jetonomy_before_create_post`<br>_filter_ | Filter post data before creation. | `data` | `includes/models/class-post.php` |
| `jetonomy_before_delete_post`<br>_filter_ | Filter whether a post deletion should proceed. | `post_id` | `includes/models/class-post.php` |
| `jetonomy_new_post_submit_action`<br>_filter_ | - | `url` | `templates/views/new-post.php` |
| `jetonomy_post_actions`<br>_action_ | - | `post_id` | `templates/views/single-post.php` |
| `jetonomy_post_card_after_badges`<br>_action_ | Mirror the listing-card badge hook on the single-post header so Pro markers (notably the site-wide "Announcement" badge) show here too, not… | `$post, $space` | `templates/views/single-post.php` |
| `jetonomy_post_created`<br>_action_ | idea_status, slug, etc.) for the listener to disambiguate by. | - | `includes/models/class-post.php` |
| `jetonomy_post_deleted`<br>_action_ | - | `post_id` | `includes/api/class-posts-controller.php` |
| `jetonomy_post_merged`<br>_action_ | - | `primary_id, secondary_id` | `includes/models/class-post.php` |
| `jetonomy_post_meta_fields`<br>_action_ | Fires after the post body to display custom field values. | `post_id` | `templates/views/single-post.php` |
| `jetonomy_post_publish_transition`<br>_action_ | Fired here for posts created directly as publish; Post::update() fires it for later transitions (pending→publish approval, publish→trash,… | `post_id, delta, created_at` | `includes/models/class-post.php` |
| `jetonomy_post_response`<br>_filter_ | Alias filter matching the Pro custom-fields listener contract - lets extensions append per-post payload (custom field values, etc.) to the… | `response, post` | `includes/api/class-posts-controller.php` |
| `jetonomy_post_updated`<br>_action_ | - | `post_id` | `includes/api/class-posts-controller.php` |
| `jetonomy_posts_query_args`<br>_filter_ | Filter post query parameters before execution. | - | `includes/models/class-post.php` |
| `jetonomy_scheduled_post_published`<br>_action_ | - | `post_id` | `includes/models/class-post.php` |

## Replies

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_reply`<br>_action_ | - | - | `includes/class-abilities.php` |
| `jetonomy_after_delete_reply`<br>_action_ | Fires after a reply is deleted. | - | `includes/api/class-replies-controller.php` |
| `jetonomy_after_replies`<br>_action_ | Fires after the replies list renders. | `post_id` | `templates/views/single-post.php` |
| `jetonomy_after_update_reply`<br>_action_ | Fires after a reply is updated with the full reply object plus context. | - | `includes/api/class-replies-controller.php` |
| `jetonomy_before_create_reply`<br>_filter_ | Filter reply data before creation. | `data` | `includes/models/class-reply.php` |
| `jetonomy_before_delete_reply`<br>_filter_ | Filter whether a reply deletion should proceed. | `reply_id` | `includes/models/class-reply.php` |
| `jetonomy_before_replies`<br>_action_ | Fires before the replies list renders (above both empty state and populated list). | `post_id` | `templates/views/single-post.php` |
| `jetonomy_between_replies`<br>_action_ | Fires after each top-level reply in the replies list. | `post_id, reply` | `templates/views/single-post.php` |
| `jetonomy_replies_query_args`<br>_filter_ | Filter reply query parameters before execution. | - | `includes/models/class-reply.php` |
| `jetonomy_reply_accepted`<br>_action_ | - | `reply_id` | `includes/api/class-replies-controller.php` |
| `jetonomy_reply_actions`<br>_action_ | - | `reply_id` | `templates/partials/reply-card.php` |
| `jetonomy_reply_created`<br>_action_ | content, etc.). | - | `includes/models/class-reply.php` |
| `jetonomy_reply_deleted`<br>_action_ | - | `reply_id` | `includes/api/class-replies-controller.php` |
| `jetonomy_reply_publish_transition`<br>_action_ | Mirrors `jetonomy_post_publish_transition` for the reply path - fired here for replies created directly as publish; Reply::update() fires… | `reply_id, delta, created_at` | `includes/models/class-reply.php` |
| `jetonomy_reply_split`<br>_action_ | - | `new_post_id, reply_ids` | `includes/models/class-reply.php` |
| `jetonomy_reply_unaccepted`<br>_action_ | - | - | `includes/api/class-replies-controller.php` |
| `jetonomy_reply_updated`<br>_action_ | - | `reply_id` | `includes/api/class-replies-controller.php` |

## Voting

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_after_vote`<br>_action_ | - | `user_id, object_type, object_id, vote_value` | `includes/class-abilities.php` |
| `jetonomy_allow_self_upvote`<br>_filter_ | - | `allowed, user_id, type, id` | `includes/api/class-votes-controller.php` |
| `jetonomy_before_vote`<br>_filter_ | Filter whether a vote should proceed. | `user_id, object_type, object_id, vote_value` | `includes/models/class-vote.php` |
| `jetonomy_pro_poll_unvoted`<br>_action_ ·_Pro_ | Fires after a user's votes are removed from a poll. | `$poll_id, $user_id` | `includes/extensions/polls/class-extension.php` |
| `jetonomy_pro_poll_voted`<br>_action_ ·_Pro_ | Fires after a vote is cast or changed on a poll. | `$poll_id, $user_id, $option_ids` | `includes/extensions/polls/class-extension.php` |
| `jetonomy_vote_cast`<br>_action_ | Lets gamification reward the voter directly (the receiver is already covered by reputation). | - | `includes/models/class-vote.php` |
| `jetonomy_vote_retracted`<br>_action_ | Fires after a voter retracts their existing vote. | - | `includes/models/class-vote.php` |

## Spaces & Membership

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_space`<br>_action_ | Fires after a space is created. | `space_id, request` | `includes/api/class-spaces-controller.php` |
| `jetonomy_after_update_space`<br>_action_ | Fires after a space is updated. | `space, context{space_id,user_id,request}` | `includes/api/class-spaces-controller.php` |
| `jetonomy_before_join_space`<br>_filter_ | Filter whether a user should be allowed to join a space. | `user_id, space_id` | `includes/models/class-space-member.php` |
| `jetonomy_import_space_visibility`<br>_filter_ | - | `access, source, forum` | `includes/import/class-asgaros-importer.php` |
| `jetonomy_join_request_approved`<br>_action_ | - | - | `includes/models/class-join-request.php` |
| `jetonomy_join_request_created`<br>_action_ | - | `request_id` | `includes/cli/journeys/class-member-journey.php` |
| `jetonomy_join_request_denied`<br>_action_ | - | - | `includes/models/class-join-request.php` |
| `jetonomy_max_space_pins`<br>_filter_ | - | - | `includes/api/class-posts-controller.php` |
| `jetonomy_membership_activated`<br>_action_ | - | `user_id, level_id, adapter` | `includes/adapters/class-member-press-adapter.php` |
| `jetonomy_membership_deactivated`<br>_action_ | - | `user_id, level_id, adapter` | `includes/adapters/class-member-press-adapter.php` |
| `jetonomy_new_space_fields`<br>_action_ | Fires inside the create-space form, after the built-in fields. | - | `templates/views/new-space.php` |
| `jetonomy_post_list_results_for_space`<br>_filter_ | Lets Pro extensions (site-announcements / super-sticky) inject cross-space pinned posts at the top of every space's view without each space… | `$results, $space_id, $user_id, $is_privileged, $sort, $offset` | `includes/models/class-post.php` |
| `jetonomy_pro_ai_member_hourly_limit`<br>_filter_ ·_Pro_ | Filter the per-user hourly AI request limit. | - | `includes/extensions/ai/class-extension.php` |
| `jetonomy_pro_ai_member_min_trust`<br>_filter_ ·_Pro_ | Filter the minimum trust level for member-facing AI. | - | `includes/extensions/ai/class-extension.php` |
| `jetonomy_pro_ai_suggestion_space_types`<br>_filter_ ·_Pro_ | - | - | `includes/extensions/ai/class-suggester.php` |
| `jetonomy_show_sidebar_top_members`<br>_filter_ | - | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_top_members`<br>_action_ | Insert a custom widget or ad after the Top Members section. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_top_members`<br>_action_ | Insert a custom widget or ad before the Top Members section. | - | `templates/partials/sidebar.php` |
| `jetonomy_space_display_fields`<br>_action_ | Fires in the space header to display custom field values (context = space). | `space` | `templates/views/space.php` |
| `jetonomy_space_edit_fields`<br>_action_ | Fires inside the edit-space form, after the built-in fields. | `space` | `templates/views/space-edit.php` |
| `jetonomy_space_feed_posts`<br>_filter_ | Filters the posts included in a space RSS feed. | `posts, space` | `includes/class-feed.php` |
| `jetonomy_space_listing_visibility_sql`<br>_filter_ | Filter the space-listing visibility SQL predicate. | `result, user_id, alias` | `includes/models/class-space.php` |
| `jetonomy_space_member_joined`<br>_action_ | Alias of `jetonomy_user_joined_space` without the role arg - matches the Pro webhooks listener contract. | - | `includes/models/class-space-member.php` |
| `jetonomy_space_member_left`<br>_action_ | Alias matching the Pro webhooks listener contract. | - | `includes/models/class-space-member.php` |
| `jetonomy_space_members_per_page`<br>_filter_ | - | - | `templates/views/space-members.php` |
| `jetonomy_space_pending_requests_shown`<br>_filter_ | - | - | `templates/views/space-members.php` |
| `jetonomy_space_role_permissions`<br>_filter_ | Filter the permissions granted to a space role. | `permissions, role` | `includes/permissions/class-permission-engine.php` |
| `jetonomy_space_tabs`<br>_filter_ | Add, remove, reorder, or relabel the tabs on a space page. | `$tabs, $space, $show_members` | `templates/views/space.php` |
| `jetonomy_spaces_query_args`<br>_filter_ | Filter space query parameters before execution. | - | `includes/models/class-space.php` |
| `jetonomy_use_frontend_space_edit`<br>_filter_ | Default true since G5 shipped in 1.4.0. | `use_frontend` | `includes/functions.php` |
| `jetonomy_user_joined_space`<br>_action_ | - | `user_id, space_id` | `includes/models/class-space-member.php` |
| `jetonomy_user_left_space`<br>_action_ | Fires when a user is removed from a space. | - | `includes/models/class-space-member.php` |

## Profiles

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_profile_after_stats`<br>_action_ | Fires after the user stats bar on the profile page. | `user_id` | `templates/views/user-profile.php` |
| `jetonomy_profile_display_fields`<br>_action_ | Fires after the stats bar to display custom profile field values. | `user_id` | `templates/views/user-profile.php` |
| `jetonomy_profile_edit_fields`<br>_action_ | Fires after the standard profile edit fields, before submit. | `user_id` | `templates/views/edit-profile.php` |
| `jetonomy_profile_response`<br>_filter_ | Filter the user profile REST response. | `response, user` | `includes/api/class-users-controller.php` |
| `jetonomy_profile_tabs`<br>_filter_ | Add, remove, reorder, or relabel the tabs shown under a member's profile header. | `$tabs, $user, $is_own` | `templates/views/user-profile.php` |
| `jetonomy_profile_url`<br>_filter_ | Allows third-party plugins (BuddyPress, BuddyBoss, Ultimate Member) to override where user profile links point to. | `url, user_id` | `includes/functions.php` |

## Roadmap

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_idea_status_changed`<br>_action_ | Re-fires from the REST controller are tolerated: the controller snapshots `$previous_status` before this call, so a duplicate fire would… | - | `includes/models/class-post.php` |

## Moderation

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_flag`<br>_action_ | - | - | `includes/api/class-moderation-controller.php` |
| `jetonomy_after_resolve_flag`<br>_action_ | - | - | `includes/moderation/class-moderation-service.php` |
| `jetonomy_content_moderated`<br>_action_ | - | `action, type, id, moderator_id` | `includes/moderation/class-moderation-service.php` |
| `jetonomy_flag_created`<br>_action_ | - | `flag_id` | `includes/api/class-posts-controller.php` |
| `jetonomy_flag_resolved`<br>_action_ | - | `flag_id, action` | `includes/moderation/class-moderation-service.php` |
| `jetonomy_moderation_flag_resolution`<br>_filter_ | Filter the status applied to an object's pending flags when it is moderated directly (outside the flag-resolution flow). | `flag_status, action, type, id, user_id` | `includes/moderation/class-moderation-service.php` |
| `jetonomy_moderation_per_page`<br>_filter_ | - | - | `templates/views/moderation.php` |

## Trust & Reputation

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_pro_ai_review_exempt_trust_level`<br>_filter_ ·_Pro_ | Filter the trust level at which members skip AI review. | - | `includes/extensions/ai/class-batch-reviewer.php` |
| `jetonomy_pro_badge_earned`<br>_action_ ·_Pro_ | Fires when a user earns a badge. | `$user_id, $badge_id, $badge` | `includes/extensions/custom-badges/class-extension.php` |
| `jetonomy_pro_badge_revoked`<br>_action_ ·_Pro_ | Fires when a badge is revoked from a user (`$removed` is the removed row). | `$user_id, $badge_id, $removed` | `includes/extensions/custom-badges/class-extension.php` |
| `jetonomy_reputation_changed`<br>_action_ | of {@see award_custom()}. | `user_id, points, reason` | `includes/trust/class-reputation.php` |
| `jetonomy_reputation_points_for`<br>_filter_ | Runs after the admin override + hardcoded fallback resolve, so filter listeners see the final number that would apply. | `$points, $action` | `includes/trust/class-reputation.php` |
| `jetonomy_reputation_points_map`<br>_filter_ | Use this to add new action keys or wholesale-replace the scoring table (e.g. | - | `includes/trust/class-reputation.php` |
| `jetonomy_reputation_pre_change`<br>_filter_ | Use this to scale deltas during campaigns ("double points weekend"), veto for sandboxed users (return 0), or redirect rep to an external… | - | `includes/trust/class-reputation.php` |
| `jetonomy_trust_level_changed`<br>_action_ | - | `user_id, old_level, new_level` | `includes/class-cli.php` |
| `jetonomy_trust_level_pre_change`<br>_filter_ | - | - | `includes/class-cli.php` |
| `jetonomy_trusted_proxies`<br>_filter_ | Trusted reverse-proxy / CDN addresses (exact REMOTE_ADDR match). | `proxies` | `includes/functions.php` |

## Notifications

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_notification_created`<br>_action_ | - | `notification_id, user_id, type, object_type, object_id, message, link` | `includes/class-mentions.php` |
| `jetonomy_pro_message_notified`<br>_action_ ·_Pro_ | Fires after message notifications are dispatched. | `$conversation_id, $sender_id, $preview` | `includes/extensions/private-messaging/class-extension.php` |

## Email

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_create_reply_from_email`<br>_action_ ·_Pro_ | Fire action for core Jetonomy to create the reply. | `$post_id, $user_id, $content, 'reply_by_email'` | `includes/extensions/reply-by-email/class-extension.php` |
| `jetonomy_disposable_email_domains`<br>_filter_ | Filter the list of disposable email domains rejected on register. | `domains` | `includes/api/class-auth-controller.php` |
| `jetonomy_email_accent_color`<br>_filter_ | Filter the accent color used in the email header accent-bar and CTA. | `color` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_body`<br>_filter_ | Filter the email body/intro text. | `body, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_headers`<br>_filter_ | Filter the headers before sending. | `headers` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_html`<br>_filter_ | Final filter on the rendered HTML. | `html, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_logo_url`<br>_filter_ | Filter the logo URL shown in the email header. | `url` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_subject`<br>_filter_ | Filter the email subject before sending. | `subject, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_template_context`<br>_filter_ | Filter the full context passed into email templates. | `context, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_template_path`<br>_filter_ | Filter the resolved email template path. | `path, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_verified`<br>_action_ | - | `user_id` | `includes/api/class-auth-controller.php` |
| `jetonomy_notification_email_headers`<br>_filter_ | - | `headers` | `includes/adapters/class-wp-mail-adapter.php` |

## Templates & Layout

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_after_content`<br>_action_ | Fires after the main content container closes, before the app wrapper closes. | `type, object_id` | `includes/class-template-loader.php` |
| `jetonomy_before_content`<br>_action_ | Fires inside the Jetonomy app wrapper, before the header partial and content container. | `type, object_id` | `includes/class-template-loader.php` |
| `jetonomy_check_content`<br>_filter_ | Check content against moderation rules before insertion. | `content, type` | `includes/api/class-posts-controller.php` |
| `jetonomy_header_logo`<br>_filter_ | Filter the header logo URL used by Jetonomy-rendered surfaces. | `url` | `includes/functions.php` |
| `jetonomy_header_nav_items`<br>_action_ | - | `user_id` | `templates/partials/header.php` |
| `jetonomy_member_card_after`<br>_action_ | Append a per-member badge, link, or action here. | `$member, $space` | `templates/views/space-members.php` |
| `jetonomy_show_sidebar`<br>_filter_ | Allow bridge plugins to suppress Jetonomy's sidebar (e.g. | `show` | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_about`<br>_filter_ | - | - | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_managed_by`<br>_filter_ | - | - | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_popular_tags`<br>_filter_ | - | - | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_trending`<br>_filter_ | - | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_about_after_meta`<br>_action_ | Fires inside the sidebar About card, after the meta tags. | `space_id` | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after`<br>_action_ | Fires at the bottom of the Jetonomy sidebar, after all widgets render. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_about`<br>_action_ | Fires in the sidebar immediately after the "About" space card closes. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_managed_by`<br>_action_ | Insert a custom widget or ad after the Managed-by card. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_popular_tags`<br>_action_ | Insert a custom widget or ad after the Popular Tags section. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_trending`<br>_action_ | Insert a custom widget or ad after the Trending section. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_auth_card`<br>_filter_ | Allow integrations to suppress the auto-rendered auth card. | `html` | `includes/class-blocks.php` |
| `jetonomy_sidebar_before`<br>_action_ | Fires at the top of the Jetonomy sidebar, before any widgets render. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_about`<br>_action_ | Insert a custom widget or ad before the About card. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_managed_by`<br>_action_ | Insert a custom widget or ad before the Managed-by card. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_popular_tags`<br>_action_ | Insert a custom widget or ad before the Popular Tags section. | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_trending`<br>_action_ | Insert a custom widget or ad before the Trending section. | - | `templates/partials/sidebar.php` |
| `jetonomy_space_card_after`<br>_action_ | Fires after each space card. Append a per-space badge, link, or action here. | `$space` | `templates/views/category.php`, `templates/views/home.php` |
| `jetonomy_template_map`<br>_filter_ | Values may be relative (resolved against plugin_dir/theme_dir) or absolute paths (starting with /). | `map` | `includes/class-template-loader.php` |

## Theme & CSS

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_dynamic_css`<br>_filter_ | The string already contains the container width, palette tokens, font-inherit rules, the host-theme colour-adoption chain, density rules… | `$css, $settings` | `includes/class-template-loader.php` |

## SEO

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_seo_meta`<br>_filter_ | @type string $title         OG/Twitter title. | - | `includes/class-template-loader.php` |

## Query filters

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_search_query_args`<br>_filter_ | Filters the search query args before the query is built. | `args` | `includes/api/class-search-controller.php` |
| `jetonomy_users_query_args`<br>_filter_ | Filter user/leaderboard query parameters before execution. | - | `includes/api/class-leaderboards-controller.php` |

## Admin

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_admin_dashboard_after_stats`<br>_action_ | Fires after the dashboard stat cards. | - | `includes/admin/views/dashboard.php` |
| `jetonomy_admin_dashboard_widgets`<br>_action_ | Fires to render additional dashboard widgets. | - | `includes/admin/views/dashboard.php` |
| `jetonomy_admin_footer_text`<br>_filter_ | Filter the admin footer text shown on Jetonomy admin pages. | `text` | `includes/admin/class-admin.php` |
| `jetonomy_admin_license_tab_content`<br>_action_ | - | - | `includes/admin/views/settings.php` |
| `jetonomy_admin_menu_icon`<br>_filter_ | - | `icon` | `includes/admin/class-admin.php` |
| `jetonomy_admin_menu_label`<br>_filter_ | - | `label` | `includes/admin/class-admin.php` |
| `jetonomy_admin_moderation_tab_content`<br>_action_ | Fires to render additional moderation tab content. | `active_tab` | `includes/admin/views/moderation.php` |
| `jetonomy_admin_moderation_tabs`<br>_action_ | Fires to render additional moderation tabs. | - | `includes/admin/views/moderation.php` |
| `jetonomy_admin_render_extensions`<br>_action_ | Fires to render the Extensions page content. | - | `includes/admin/class-admin.php` |
| `jetonomy_admin_settings_tab_content`<br>_action_ | - | `tab, group` | `includes/admin/views/settings.php` |
| `jetonomy_admin_settings_tabs`<br>_action_ | - | - | `includes/admin/views/settings.php` |
| `jetonomy_admin_space_edit_tab_content`<br>_action_ | Fires to render additional space edit tab content. | `space_id, tab` | `includes/admin/views/space-edit.php` |
| `jetonomy_admin_space_edit_tabs`<br>_action_ | Fires to render additional space edit tabs. | `space_id` | `includes/admin/views/space-edit.php` |

## REST

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_app_config`<br>_filter_ | Filter the mobile app config payload (branding + feature flags) served by `GET /app/config`. Pro consumes it to set `app_enabled` and inject white-label branding. Since 1.6.0. | `data, request` | `includes/api/class-app-config-controller.php` |
| `jetonomy_rest_prepare_notification`<br>_filter_ | Filter the REST response data for a single notification. | `response, notification` | `includes/api/class-notifications-controller.php` |
| `jetonomy_rest_prepare_post`<br>_filter_ | Filter the REST response data for a single post. | `response, post` | `includes/api/class-posts-controller.php` |
| `jetonomy_rest_prepare_reply`<br>_filter_ | Filter the REST response data for a single reply. | `response, reply` | `includes/api/class-replies-controller.php` |
| `jetonomy_rest_prepare_space`<br>_filter_ | Filter the REST response data for a single space. | `response, space` | `includes/api/class-spaces-controller.php` |
| `jetonomy_rest_prepare_user`<br>_filter_ | Filter the REST response data for a single user. | `response, user` | `includes/api/class-users-controller.php` |

## Integrations

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_automator_space_options_limit`<br>_filter_ ·_Pro_ | Filter the number of spaces offered in the Uncanny Automator dropdowns (default 500). | - | `includes/integrations/class-automator-integration.php` |
| `jetonomy_client_ip`<br>_filter_ | Final resolved client IP. | `ip, remote_addr` | `includes/functions.php` |
| `jetonomy_companions`<br>_filter_ | Filter the Wbcom stack companion catalog. | - | `includes/integrations/class-companion-registry.php` |

## Other

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_composer_toolbar`<br>_action_ | Fires inside the composer toolbar, after the built-in formatting buttons. | - | `templates/partials/composer.php` |
| `jetonomy_cron_batch_size`<br>_filter_ | - | - | `includes/class-cron.php` |
| `jetonomy_erase_batch_size`<br>_filter_ ·_Pro_ | Filter the batch size used by the GDPR personal-data eraser (default 1000). | - | `includes/class-privacy.php` |
| `jetonomy_footer_text`<br>_filter_ | Filter the footer text used by Jetonomy-rendered surfaces. | `text` | `includes/functions.php` |
| `jetonomy_home_welcome_heading`<br>_filter_ | - | - | `templates/views/home.php` |
| `jetonomy_home_welcome_subheading`<br>_filter_ | - | - | `templates/views/home.php` |
| `jetonomy_import_wpforo_guest_group`<br>_filter_ | - | `guest_group` | `includes/import/class-wpforo-importer.php` |
| `jetonomy_importers`<br>_filter_ | - | `importers` | `includes/import/class-import-manager.php` |
| `jetonomy_leaderboard_items`<br>_filter_ | Lets host plugins enrich each row with cross-engine totals (badge count, level name, alternate currency) without a second REST round-trip. | - | `includes/api/class-leaderboards-controller.php` |
| `jetonomy_learnomy_max_levels`<br>_filter_ ·_Pro_ | - | - | `includes/adapters/class-learnomy-adapter.php` |
| `jetonomy_link_preview_cache_ttl`<br>_filter_ | - | `ttl` | `includes/services/links/class-preview-service.php` |
| `jetonomy_link_preview_data`<br>_filter_ | - | `data, url` | `includes/services/links/class-preview-service.php` |
| `jetonomy_link_preview_providers`<br>_filter_ | - | `providers` | `includes/services/links/class-preview-service.php` |
| `jetonomy_link_preview_user_agent`<br>_filter_ | - | `ua` | `includes/services/links/class-html-fetcher.php` |
| `jetonomy_oembed_response`<br>_filter_ | Filter the oEmbed response payload for a Jetonomy thread. | `response, post` | `includes/api/class-oembed-controller.php` |
| `jetonomy_search_filters`<br>_action_ | - | - | `templates/views/search.php` |
| `jetonomy_show_community_nav`<br>_filter_ | Filter whether to show the Jetonomy community navigation bar. | `show` | `templates/partials/header.php` |
| `jetonomy_theme_dark_tokens`<br>_filter_ | Return an empty array to disable the dark override. | `tokens` | `includes/integrations/class-theme-integration.php` |
| `jetonomy_theme_light_tokens`<br>_filter_ | Return an empty array to disable the light override. | `tokens` | `includes/integrations/class-theme-integration.php` |
| `jetonomy_user_pending_verification`<br>_action_ | - | `user_id` | `includes/api/class-auth-controller.php` |
| `jetonomy_user_registered`<br>_action_ | - | `user_id` | `includes/api/class-auth-controller.php` |
| `jetonomy_verification_reminder_sent`<br>_action_ | Fires after a verification reminder email is dispatched. | `user_id, user` | `includes/notifications/class-verification-reminder.php` |

## Pro

Pro-only hooks (marked _Pro_ above and listed below) fire from the Jetonomy Pro extensions, and only when the corresponding extension is active. Each hook's source extension is noted in the Source column.

| Hook | What it does | Args | Source |
|---|---|---|---|
| `jetonomy_pro_ai_all_providers_failed`<br>_action_ ·_Pro_ | - | `$feature, $exception` | `includes/extensions/ai/class-spam-detector.php` |
| `jetonomy_pro_ai_review_batch_cap`<br>_filter_ ·_Pro_ | Filter the maximum number of items inspected per content type (posts and replies are each capped at this) per sweep. | - | `includes/extensions/ai/class-batch-reviewer.php` |
| `jetonomy_pro_ai_review_chunk_size`<br>_filter_ ·_Pro_ | Filter how many items are classified per model call. | - | `includes/extensions/ai/class-batch-reviewer.php` |
| `jetonomy_pro_ai_review_interval`<br>_filter_ ·_Pro_ | Filter the batch review interval in seconds. | - | `includes/extensions/ai/class-batch-reviewer.php` |
| `jetonomy_pro_ai_suggestions_only_unanswered`<br>_filter_ ·_Pro_ | - | - | `includes/extensions/ai/class-suggester.php` |
| `jetonomy_pro_ai_summary_heading`<br>_filter_ ·_Pro_ | - | - | `includes/extensions/ai/class-summarizer.php` |
| `jetonomy_pro_analytics_dual_path_verify`<br>_filter_ ·_Pro_ | Dual-path verification is an internal diagnostics tool (direct query vs. | - | `includes/extensions/analytics/views/dashboard.php` |
| `jetonomy_pro_conversation_archived`<br>_action_ ·_Pro_ | Fires after a conversation is archived or unarchived. | - | `includes/extensions/private-messaging/class-extension.php` |
| `jetonomy_pro_conversation_blocked`<br>_action_ ·_Pro_ | Fires after a user blocks or unblocks the other party in a direct conversation. | - | `includes/extensions/private-messaging/class-extension.php` |
| `jetonomy_pro_conversation_created`<br>_action_ ·_Pro_ | Fires after a new conversation is created. | `$conversation_id, $user_id, $participants` | `includes/extensions/private-messaging/class-extension.php` |
| `jetonomy_pro_conversation_left`<br>_action_ ·_Pro_ | Fires after a user leaves a group conversation. | - | `includes/extensions/private-messaging/class-extension.php` |
| `jetonomy_pro_conversation_purged`<br>_action_ ·_Pro_ | Fires after a site admin purges a conversation from wp-admin. | `conversation_id, admin_id` | `includes/extensions/private-messaging/class-admin-page.php` |
| `jetonomy_pro_dm_received`<br>_action_ ·_Pro_ | Counterpart to `jetonomy_pro_message_sent`. | - | `includes/extensions/private-messaging/class-extension.php` |
| `jetonomy_pro_field_created`<br>_action_ ·_Pro_ | Fires after a custom field is created. | `$field_id, $context` | `includes/extensions/custom-fields/class-extension.php` |
| `jetonomy_pro_first_reaction`<br>_action_ ·_Pro_ | Fires the first time an object receives any reaction. | `$object_type, $object_id, $user_id` | `includes/extensions/reactions/class-extension.php` |
| `jetonomy_pro_message_sent`<br>_action_ ·_Pro_ | Fires after a message is sent. | `$message_id, $conversation_id, $sender_id` | `includes/extensions/private-messaging/class-extension.php` |
| `jetonomy_pro_poll_created`<br>_action_ ·_Pro_ | Fires after a poll is created. | `$poll_id, $post_id, $user_id` | `includes/extensions/polls/class-extension.php` |
| `jetonomy_pro_reaction_icon_renderer`<br>_filter_ ·_Pro_ | Sites that want the SVG look can opt in via the filter below. | `$renderer, $slug, $size` | `includes/extensions/reactions/class-extension.php` |
| `jetonomy_pro_reaction_toggled`<br>_action_ ·_Pro_ | Fires after a reaction is toggled. | `$object_type, $object_id, $emoji, $user_id, $action` | `includes/extensions/reactions/class-extension.php` |
