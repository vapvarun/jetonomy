# Audit fixes — integrations (7 findings)

## 1. [CRITICAL] `docs/website/integrations/06-buddynext.md` — inaccurate
- **Issue:** Doc cites a non-existent bridge file includes/adapters/class-buddynext-bridge.php and a do_action('jetonomy_buddynext_ready') hook that is never fired.
- **Fix:** Delete the Developer Notes block (lines 46-55) or replace it with the real extension surface: the did_action('buddynext_loaded') detection guard. There is no class-buddynext-bridge.php and no jetonomy_buddynext_ready action; do not document either.
- **Evidence:** doc 06-buddynext.md:48-53. Code: `find . -name '*buddynext*'` in the free plugin returns only the doc itself (no class-buddynext-bridge.php anywhere). `grep -rn 'jetonomy_buddynext_ready' includes/ templates/` returns zero hits. The only real BuddyNext wiring is did_action('buddynext_loaded') guards (templates/partials/header.php:86,97,187; templates/partials/sidebar.php:80; includes/integrations/class-fluent-community.php:772) that suppress Jetonomy's own header/nav.

## 2. [CRITICAL] `docs/website/integrations/03-woocommerce.md` — inaccurate
- **Issue:** Doc claims plain WooCommerce product purchase gates spaces ('works with WooCommerce alone', 'Access granted on order complete', auto-revoke on Refunded order status). Adapter never activates on plain WooCommerce and registers no order-status hooks.
- **Fix:** Correct line 23 ('Simple product gating works with WooCommerce alone' is false - Memberships OR Subscriptions is required). Rewrite the 'Auto-Activate on Purchase' (40-42) and 'Auto-Revoke on Refund' (54-58) sections: activation/revocation is driven by WC Memberships status changes and WC Subscriptions status changes, not by raw order Completed/Refunded transitions. Fix the Troubleshooting 'Member not removed after refund' note (72) accordingly.
- **Evidence:** doc 03-woocommerce.md:18,23,42,54-56,72. Code class-woocommerce-adapter.php:16-20 - is_active() returns true only if class_exists('WooCommerce') AND (function_exists('wc_memberships') OR class_exists('WC_Subscriptions')). register_hooks() at :121-127 hooks only wc_memberships_user_membership_status_changed and woocommerce_subscription_status_active/on-hold/cancelled/expired. There is no woocommerce_order_status_completed or woocommerce_order_status_refunded listener in the file.

## 3. [MAJOR] `docs/website/integrations/06-buddynext.md` — inaccurate
- **Issue:** Doc claims Jetonomy adds a Forum tab to each BuddyNext space via jetonomy_template_map, injects the BuddyNext community name into the breadcrumb, and auto-overrides jetonomy_profile_url to BuddyNext member profiles. No such code exists.
- **Fix:** Remove the 'Forum Tab in BuddyNext Spaces' section (30-34) and the breadcrumb / profile-url-override claims (38-40). The accurate behavior is header/nav suppression and shared CSS token cascade only. Also drop the Troubleshooting 'Forum tab not appearing' entry (line 59) since the feature does not exist.
- **Evidence:** doc 06-buddynext.md:30-40. Code: jetonomy_template_map is applied at includes/class-template-loader.php:138 and jetonomy_profile_url at includes/functions.php:65, but neither is hooked on BuddyNext detection anywhere. grep of buddynext_loaded shows only nav/header suppression (header.php:86,97,187; sidebar.php:80) and a Fluent Community check (class-fluent-community.php:772) - no forum-tab registration, no breadcrumb injection, no profile-url override.

## 4. [MAJOR] `docs/website/integrations/03-woocommerce.md` — inaccurate
- **Issue:** Supported Product Types table lists Simple product = Yes and Variable product = Yes. get_all_levels() only enumerates WC Memberships plans and subscription/variable-subscription products, so plain simple/variable products can never be selected as a gate.
- **Fix:** Change the Simple product and (non-subscription) Variable product rows to 'No' - or reframe the table around what is actually selectable: WooCommerce Memberships plans and WooCommerce Subscription (incl. variable-subscription) products.
- **Evidence:** doc 03-woocommerce.md:18-19. Code class-woocommerce-adapter.php:59-95 - get_all_levels() returns only 'wc_membership_*' plans (from wc_memberships_get_membership_plans) and products of type array('subscription','variable-subscription') (from wc_get_products). No simple or ordinary variable products are offered as selectable levels.

## 5. [MAJOR] `docs/website/integrations/01-memberpress.md` — wrong-hook
- **Issue:** Doc says the MemberPress_Adapter hooks 'mepr-event-transaction-completed' and 'mepr-event-subscription-expired'. The adapter hooks different actions entirely.
- **Fix:** Replace the hook names on line 38 with the real ones: mepr-txn-status-complete (activation), mepr-txn-status-refunded / mepr-txn-expired / mepr_subscription_transition_status (deactivation).
- **Evidence:** doc 01-memberpress.md:38. Code class-member-press-adapter.php register_hooks() :69-74 hooks mepr-txn-status-complete (activate), mepr-txn-status-refunded + mepr-txn-expired (deactivate), and mepr_subscription_transition_status. Neither mepr-event-transaction-completed nor mepr-event-subscription-expired appears in the file.

## 6. [MAJOR] `docs/website/integrations/05-rcp.md` — wrong-hook
- **Issue:** Troubleshooting says Jetonomy listens to the legacy RCP 2.x 'rcp_set_status' action. The RCP adapter uses the modern RCP 3.x membership API hooks.
- **Fix:** Replace 'rcp_set_status' on line 63 with the actual hooks: rcp_membership_post_activate / rcp_membership_post_cancel / rcp_transition_membership_status (RCP 3.x membership API). Note the status-transition handler removes on 'expired','cancelled','pending'.
- **Evidence:** doc 05-rcp.md:63. Code class-rcp-adapter.php register_hooks() :87-89 hooks rcp_membership_post_activate, rcp_membership_post_cancel, and rcp_transition_membership_status. rcp_set_status does not appear in the adapter.

## 7. [MAJOR] `docs/website/integrations/07-theme-compatibility.md` — wrong-hook
- **Issue:** Doc names two extension filters jetonomy_theme_integration_accent and jetonomy_theme_integration_dark_mode that do not exist. The real filters are named differently.
- **Fix:** Replace the two filter names on line 50 with jetonomy_theme_light_tokens and jetonomy_theme_dark_tokens. Note these filter token arrays (light/dark token maps), not single accent/dark-mode scalar values, so the surrounding sentence ('return your own values') should be reworded to 'return your own token maps'.
- **Evidence:** doc 07-theme-compatibility.md:50. Code: grep 'jetonomy_theme_integration' across includes/ + templates/ returns zero hits. class-theme-integration.php actually defines apply_filters('jetonomy_theme_light_tokens', $light) at line 94 and apply_filters('jetonomy_theme_dark_tokens', $dark) at line 105.
