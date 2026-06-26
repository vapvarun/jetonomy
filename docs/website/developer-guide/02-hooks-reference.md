# Hooks Reference

> Generated from the plugin source (every `do_action` / `apply_filters` Jetonomy fires) - the authoritative list of names, types, and source files. Argument signatures come from the audit manifest where curated; read the source file for the full signature. Worked recipes: [profile tab](12-add-a-profile-tab.md) - [space tab](13-add-a-space-tab.md) - [nav item](14-add-a-nav-item.md) - [cards](15-customize-cards.md) - [tokens](16-theming-and-tokens.md) - [frontend](17-extend-the-frontend.md) - [REST](18-extend-the-rest-api.md) - [emails](19-customize-emails.md) - [admin](20-admin-extensions.md).

**200 hooks.**


## Posts

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_post` | action | - | `includes/class-abilities.php` |
| `jetonomy_after_delete_post` | action | - | `includes/api/class-posts-controller.php` |
| `jetonomy_after_post_article` | action | `post_id` | `templates/views/single-post.php` |
| `jetonomy_after_post_content` | filter | `post_id, content` | `templates/views/single-post.php` |
| `jetonomy_after_update_post` | action | - | `includes/api/class-posts-controller.php` |
| `jetonomy_before_create_post` | filter | `data` | `includes/models/class-post.php` |
| `jetonomy_before_delete_post` | filter | `post_id` | `includes/models/class-post.php` |
| `jetonomy_new_post_submit_action` | filter | `url` | `templates/views/new-post.php` |
| `jetonomy_post_actions` | action | `post_id` | `templates/views/single-post.php` |
| `jetonomy_post_card_after_badges` | action | `$post, $space` | `templates/views/single-post.php` |
| `jetonomy_post_created` | action | - | `includes/models/class-post.php` |
| `jetonomy_post_deleted` | action | `post_id` | `includes/api/class-posts-controller.php` |
| `jetonomy_post_merged` | action | `primary_id, secondary_id` | `includes/models/class-post.php` |
| `jetonomy_post_meta_fields` | action | `post_id` | `templates/views/single-post.php` |
| `jetonomy_post_publish_transition` | action | `post_id, delta, created_at` | `includes/models/class-post.php` |
| `jetonomy_post_response` | filter | `response, post` | `includes/api/class-posts-controller.php` |
| `jetonomy_post_updated` | action | `post_id` | `includes/api/class-posts-controller.php` |
| `jetonomy_posts_query_args` | filter | - | `includes/models/class-post.php` |
| `jetonomy_scheduled_post_published` | action | `post_id` | `includes/models/class-post.php` |

## Replies

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_reply` | action | - | `includes/class-abilities.php` |
| `jetonomy_after_delete_reply` | action | - | `includes/api/class-replies-controller.php` |
| `jetonomy_after_replies` | action | `post_id` | `templates/views/single-post.php` |
| `jetonomy_after_update_reply` | action | - | `includes/api/class-replies-controller.php` |
| `jetonomy_before_create_reply` | filter | `data` | `includes/models/class-reply.php` |
| `jetonomy_before_delete_reply` | filter | `reply_id` | `includes/models/class-reply.php` |
| `jetonomy_before_replies` | action | `post_id` | `templates/views/single-post.php` |
| `jetonomy_between_replies` | action | `post_id, reply` | `templates/views/single-post.php` |
| `jetonomy_replies_query_args` | filter | - | `includes/models/class-reply.php` |
| `jetonomy_reply_accepted` | action | `reply_id` | `includes/api/class-replies-controller.php` |
| `jetonomy_reply_actions` | action | `reply_id` | `templates/partials/reply-card.php` |
| `jetonomy_reply_created` | action | - | `includes/models/class-reply.php` |
| `jetonomy_reply_deleted` | action | `reply_id` | `includes/api/class-replies-controller.php` |
| `jetonomy_reply_publish_transition` | action | `reply_id, delta, created_at` | `includes/models/class-reply.php` |
| `jetonomy_reply_split` | action | `new_post_id, reply_ids` | `includes/models/class-reply.php` |
| `jetonomy_reply_unaccepted` | action | - | `includes/api/class-replies-controller.php` |
| `jetonomy_reply_updated` | action | `reply_id` | `includes/api/class-replies-controller.php` |

## Voting

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_after_vote` | action | `user_id, object_type, object_id, vote_value` | `includes/class-abilities.php` |
| `jetonomy_allow_self_upvote` | filter | `allowed, user_id, type, id` | `includes/api/class-votes-controller.php` |
| `jetonomy_before_vote` | filter | `user_id, object_type, object_id, vote_value` | `includes/models/class-vote.php` |
| `jetonomy_pro_poll_unvoted` | action | `$poll_id, $user_id` | `includes/extensions/polls/class-extension.php` *(Pro)* |
| `jetonomy_pro_poll_voted` | action | `$poll_id, $user_id, $option_ids` | `includes/extensions/polls/class-extension.php` *(Pro)* |
| `jetonomy_vote_cast` | action | - | `includes/models/class-vote.php` |
| `jetonomy_vote_retracted` | action | - | `includes/models/class-vote.php` |

## Spaces

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_space` | action | `space_id, request` | `includes/api/class-spaces-controller.php` |
| `jetonomy_after_update_space` | action | `space, context{space_id,user_id,request}` | `includes/api/class-spaces-controller.php` |
| `jetonomy_before_join_space` | filter | `user_id, space_id` | `includes/models/class-space-member.php` |
| `jetonomy_import_space_visibility` | filter | `access, source, forum` | `includes/import/class-asgaros-importer.php` |
| `jetonomy_join_request_approved` | action | - | `includes/models/class-join-request.php` |
| `jetonomy_join_request_created` | action | `request_id` | `includes/cli/journeys/class-member-journey.php` |
| `jetonomy_join_request_denied` | action | - | `includes/models/class-join-request.php` |
| `jetonomy_max_space_pins` | filter | - | `includes/api/class-posts-controller.php` |
| `jetonomy_membership_activated` | action | `user_id, level_id, adapter` | `includes/adapters/class-member-press-adapter.php` |
| `jetonomy_membership_deactivated` | action | `user_id, level_id, adapter` | `includes/adapters/class-member-press-adapter.php` |
| `jetonomy_new_space_fields` | action | - | `templates/views/new-space.php` |
| `jetonomy_post_list_results_for_space` | filter | `$results, $space_id, $user_id, $is_privileged, $sort, $offset` | `includes/models/class-post.php` |
| `jetonomy_pro_ai_member_hourly_limit` | filter | - | `includes/extensions/ai/class-extension.php` *(Pro)* |
| `jetonomy_pro_ai_member_min_trust` | filter | - | `includes/extensions/ai/class-extension.php` *(Pro)* |
| `jetonomy_pro_ai_suggestion_space_types` | filter | - | `includes/extensions/ai/class-suggester.php` *(Pro)* |
| `jetonomy_show_sidebar_top_members` | filter | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_top_members` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_top_members` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_space_display_fields` | action | `space` | `templates/views/space.php` |
| `jetonomy_space_edit_fields` | action | `space` | `templates/views/space-edit.php` |
| `jetonomy_space_feed_posts` | filter | `posts, space` | `includes/class-feed.php` |
| `jetonomy_space_listing_visibility_sql` | filter | `result, user_id, alias` | `includes/models/class-space.php` |
| `jetonomy_space_member_joined` | action | - | `includes/models/class-space-member.php` |
| `jetonomy_space_member_left` | action | - | `includes/models/class-space-member.php` |
| `jetonomy_space_members_per_page` | filter | - | `templates/views/space-members.php` |
| `jetonomy_space_pending_requests_shown` | filter | - | `templates/views/space-members.php` |
| `jetonomy_space_role_permissions` | filter | `permissions, role` | `includes/permissions/class-permission-engine.php` |
| `jetonomy_space_tabs` | filter | `$tabs, $space, $show_members` | `templates/views/space.php` |
| `jetonomy_spaces_query_args` | filter | - | `includes/models/class-space.php` |
| `jetonomy_use_frontend_space_edit` | filter | `use_frontend` | `includes/functions.php` |
| `jetonomy_user_joined_space` | action | `user_id, space_id` | `includes/models/class-space-member.php` |
| `jetonomy_user_left_space` | action | - | `includes/models/class-space-member.php` |

## Profiles

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_profile_after_stats` | action | `user_id` | `templates/views/user-profile.php` |
| `jetonomy_profile_display_fields` | action | `user_id` | `templates/views/user-profile.php` |
| `jetonomy_profile_edit_fields` | action | `user_id` | `templates/views/edit-profile.php` |
| `jetonomy_profile_response` | filter | `response, user` | `includes/api/class-users-controller.php` |
| `jetonomy_profile_tabs` | filter | `$tabs, $user, $is_own` | `templates/views/user-profile.php` |
| `jetonomy_profile_url` | filter | `url, user_id` | `includes/functions.php` |

## Roadmap

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_idea_status_changed` | action | - | `includes/models/class-post.php` |

## Moderation

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_after_create_flag` | action | - | `includes/api/class-moderation-controller.php` |
| `jetonomy_after_resolve_flag` | action | - | `includes/moderation/class-moderation-service.php` |
| `jetonomy_content_moderated` | action | `action, type, id, moderator_id` | `includes/moderation/class-moderation-service.php` |
| `jetonomy_flag_created` | action | `flag_id` | `includes/api/class-posts-controller.php` |
| `jetonomy_flag_resolved` | action | `flag_id, action` | `includes/moderation/class-moderation-service.php` |
| `jetonomy_moderation_flag_resolution` | filter | `flag_status, action, type, id, user_id` | `includes/moderation/class-moderation-service.php` |
| `jetonomy_moderation_per_page` | filter | - | `templates/views/moderation.php` |

## Trust & Reputation

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_pro_ai_review_exempt_trust_level` | filter | - | `includes/extensions/ai/class-batch-reviewer.php` *(Pro)* |
| `jetonomy_pro_badge_earned` | action | `$user_id, $badge_id, $badge` | `includes/extensions/custom-badges/class-extension.php` *(Pro)* |
| `jetonomy_reputation_changed` | action | `user_id, points, reason` | `includes/trust/class-reputation.php` |
| `jetonomy_reputation_points_for` | filter | `$points, $action` | `includes/trust/class-reputation.php` |
| `jetonomy_reputation_points_map` | filter | - | `includes/trust/class-reputation.php` |
| `jetonomy_reputation_pre_change` | filter | - | `includes/trust/class-reputation.php` |
| `jetonomy_trust_level_changed` | action | `user_id, old_level, new_level` | `includes/class-cli.php` |
| `jetonomy_trust_level_pre_change` | filter | - | `includes/class-cli.php` |
| `jetonomy_trusted_proxies` | filter | `proxies` | `includes/functions.php` |

## Notifications

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_notification_created` | action | `notification_id, user_id, type, object_type, object_id, message, link` | `includes/class-mentions.php` |
| `jetonomy_pro_message_notified` | action | `$conversation_id, $sender_id, $preview` | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |

## Email

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_create_reply_from_email` | action | `$post_id, $user_id, $content, 'reply_by_email'` | `includes/extensions/reply-by-email/class-extension.php` *(Pro)* |
| `jetonomy_disposable_email_domains` | filter | `domains` | `includes/api/class-auth-controller.php` |
| `jetonomy_email_accent_color` | filter | `color` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_body` | filter | `body, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_headers` | filter | `headers` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_html` | filter | `html, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_logo_url` | filter | `url` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_subject` | filter | `subject, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_template_context` | filter | `context, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_template_path` | filter | `path, template` | `includes/notifications/class-notifier.php` |
| `jetonomy_email_verified` | action | `user_id` | `includes/api/class-auth-controller.php` |
| `jetonomy_notification_email_headers` | filter | `headers` | `includes/adapters/class-wp-mail-adapter.php` |

## Templates

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_header_logo` | filter | `url` | `includes/functions.php` |
| `jetonomy_header_nav_items` | action | `user_id` | `templates/partials/header.php` |
| `jetonomy_member_card_after` | action | `$member, $space` | `templates/views/space-members.php` |
| `jetonomy_show_sidebar` | filter | `show` | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_about` | filter | - | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_managed_by` | filter | - | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_popular_tags` | filter | - | `templates/partials/sidebar.php` |
| `jetonomy_show_sidebar_trending` | filter | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_about_after_meta` | action | `space_id` | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_about` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_managed_by` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_popular_tags` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_after_trending` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_auth_card` | filter | `html` | `includes/class-blocks.php` |
| `jetonomy_sidebar_before` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_about` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_managed_by` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_popular_tags` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_sidebar_before_trending` | action | - | `templates/partials/sidebar.php` |
| `jetonomy_space_card_after` | action | `$space` | `templates/views/category.php` |
| `jetonomy_template_map` | filter | `map` | `includes/class-template-loader.php` |

## Theme & CSS

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_dynamic_css` | filter | `$css, $settings` | `includes/class-template-loader.php` |

## SEO

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_seo_meta` | filter | - | `includes/class-template-loader.php` |

## Query

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_search_query_args` | filter | `args` | `includes/api/class-search-controller.php` |
| `jetonomy_users_query_args` | filter | - | `includes/api/class-leaderboards-controller.php` |

## Admin

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_admin_dashboard_after_stats` | action | - | `includes/admin/views/dashboard.php` |
| `jetonomy_admin_dashboard_widgets` | action | - | `includes/admin/views/dashboard.php` |
| `jetonomy_admin_footer_text` | filter | `text` | `includes/admin/class-admin.php` |
| `jetonomy_admin_license_tab_content` | action | - | `includes/admin/views/settings.php` |
| `jetonomy_admin_menu_icon` | filter | `icon` | `includes/admin/class-admin.php` |
| `jetonomy_admin_menu_label` | filter | `label` | `includes/admin/class-admin.php` |
| `jetonomy_admin_moderation_tab_content` | action | `active_tab` | `includes/admin/views/moderation.php` |
| `jetonomy_admin_moderation_tabs` | action | - | `includes/admin/views/moderation.php` |
| `jetonomy_admin_render_extensions` | action | - | `includes/admin/class-admin.php` |
| `jetonomy_admin_settings_tab_content` | action | `tab, group` | `includes/admin/views/settings.php` |
| `jetonomy_admin_settings_tabs` | action | - | `includes/admin/views/settings.php` |
| `jetonomy_admin_space_edit_tab_content` | action | `space_id, tab` | `includes/admin/views/space-edit.php` |
| `jetonomy_admin_space_edit_tabs` | action | `space_id` | `includes/admin/views/space-edit.php` |

## REST

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_rest_prepare_notification` | filter | `response, notification` | `includes/api/class-notifications-controller.php` |
| `jetonomy_rest_prepare_post` | filter | `response, post` | `includes/api/class-posts-controller.php` |
| `jetonomy_rest_prepare_reply` | filter | `response, reply` | `includes/api/class-replies-controller.php` |
| `jetonomy_rest_prepare_space` | filter | `response, space` | `includes/api/class-spaces-controller.php` |
| `jetonomy_rest_prepare_user` | filter | `response, user` | `includes/api/class-users-controller.php` |

## Integrations

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_companions` | filter | - | `includes/integrations/class-companion-registry.php` |

## Other

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_after_content` | action | `type, object_id` | `includes/class-template-loader.php` |
| `jetonomy_before_content` | action | `type, object_id` | `includes/class-template-loader.php` |
| `jetonomy_check_content` | filter | `content, type` | `includes/api/class-posts-controller.php` |
| `jetonomy_client_ip` | filter | `ip, remote_addr` | `includes/functions.php` |
| `jetonomy_composer_toolbar` | action | - | `templates/partials/composer.php` |
| `jetonomy_cron_batch_size` | filter | - | `includes/class-cron.php` |
| `jetonomy_footer_text` | filter | `text` | `includes/functions.php` |
| `jetonomy_home_welcome_heading` | filter | - | `templates/views/home.php` |
| `jetonomy_home_welcome_subheading` | filter | - | `templates/views/home.php` |
| `jetonomy_import_wpforo_guest_group` | filter | `guest_group` | `includes/import/class-wpforo-importer.php` |
| `jetonomy_importers` | filter | `importers` | `includes/import/class-import-manager.php` |
| `jetonomy_leaderboard_items` | filter | - | `includes/api/class-leaderboards-controller.php` |
| `jetonomy_learnomy_max_levels` | filter | - | `includes/adapters/class-learnomy-adapter.php` *(Pro)* |
| `jetonomy_link_preview_cache_ttl` | filter | `ttl` | `includes/services/links/class-preview-service.php` |
| `jetonomy_link_preview_data` | filter | `data, url` | `includes/services/links/class-preview-service.php` |
| `jetonomy_link_preview_providers` | filter | `providers` | `includes/services/links/class-preview-service.php` |
| `jetonomy_link_preview_user_agent` | filter | `ua` | `includes/services/links/class-html-fetcher.php` |
| `jetonomy_oembed_response` | filter | `response, post` | `includes/api/class-oembed-controller.php` |
| `jetonomy_search_filters` | action | - | `templates/views/search.php` |
| `jetonomy_show_community_nav` | filter | `show` | `templates/partials/header.php` |
| `jetonomy_theme_dark_tokens` | filter | `tokens` | `includes/integrations/class-theme-integration.php` |
| `jetonomy_theme_light_tokens` | filter | `tokens` | `includes/integrations/class-theme-integration.php` |
| `jetonomy_user_pending_verification` | action | `user_id` | `includes/api/class-auth-controller.php` |
| `jetonomy_user_registered` | action | `user_id` | `includes/api/class-auth-controller.php` |
| `jetonomy_verification_reminder_sent` | action | `user_id, user` | `includes/notifications/class-verification-reminder.php` |

## Pro

| Hook | Type | Args | Source |
|---|---|---|---|
| `jetonomy_pro_ai_all_providers_failed` | action | `$feature, $exception` | `includes/extensions/ai/class-spam-detector.php` *(Pro)* |
| `jetonomy_pro_ai_review_batch_cap` | filter | - | `includes/extensions/ai/class-batch-reviewer.php` *(Pro)* |
| `jetonomy_pro_ai_review_chunk_size` | filter | - | `includes/extensions/ai/class-batch-reviewer.php` *(Pro)* |
| `jetonomy_pro_ai_review_interval` | filter | - | `includes/extensions/ai/class-batch-reviewer.php` *(Pro)* |
| `jetonomy_pro_ai_suggestions_only_unanswered` | filter | - | `includes/extensions/ai/class-suggester.php` *(Pro)* |
| `jetonomy_pro_ai_summary_heading` | filter | - | `includes/extensions/ai/class-summarizer.php` *(Pro)* |
| `jetonomy_pro_analytics_dual_path_verify` | filter | - | `includes/extensions/analytics/views/dashboard.php` *(Pro)* |
| `jetonomy_pro_conversation_archived` | action | - | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |
| `jetonomy_pro_conversation_blocked` | action | - | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |
| `jetonomy_pro_conversation_created` | action | `$conversation_id, $user_id, $participants` | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |
| `jetonomy_pro_conversation_left` | action | - | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |
| `jetonomy_pro_conversation_purged` | action | `conversation_id, admin_id` | `includes/extensions/private-messaging/class-admin-page.php` *(Pro)* |
| `jetonomy_pro_dm_received` | action | - | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |
| `jetonomy_pro_field_created` | action | `$field_id, $context` | `includes/extensions/custom-fields/class-extension.php` *(Pro)* |
| `jetonomy_pro_message_sent` | action | `$message_id, $conversation_id, $sender_id` | `includes/extensions/private-messaging/class-extension.php` *(Pro)* |
| `jetonomy_pro_poll_created` | action | `$poll_id, $post_id, $user_id` | `includes/extensions/polls/class-extension.php` *(Pro)* |
| `jetonomy_pro_reaction_icon_renderer` | filter | `$renderer, $slug, $size` | `includes/extensions/reactions/class-extension.php` *(Pro)* |
| `jetonomy_pro_reaction_toggled` | action | `$object_type, $object_id, $emoji, $user_id, $action` | `includes/extensions/reactions/class-extension.php` *(Pro)* |
