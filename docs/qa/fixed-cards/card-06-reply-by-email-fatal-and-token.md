# [Fixed 1.6.0] Reply-by-Email: fatal on every notification email + forgeable token expiry

**Severity:** Critical (broken feature + security) - **Status:** Fixed, ready for QA
**Area:** Pro - Reply-by-Email extension
**Files:** `jetonomy-pro/includes/extensions/reply-by-email/class-extension.php`, `jetonomy/includes/notifications/class-notifier.php`

## What was wrong
1. **Fatal:** Pro hooked the adapter-level filter `jetonomy_notification_email_headers(headers, to, subject)` with a callback typed `(array, int, object)`. With the extension enabled, **every notification email throw a TypeError** - all notification emails broke, not just replies.
2. **Security:** the reply token signed only `user:post:type` - the expiry field was attacker-controlled, so a captured token could be reused/extended indefinitely.

## What changed
1. Repointed Pro to the notifier-level filter `jetonomy_email_headers`, widened to 5 args (`+object_type, object_id`) so the callback gets the recipient + post context it needs. Adds `Reply-To: reply+<token>@<domain>` to reply notifications only.
2. Token HMAC now covers the expiry; `decode_token` verifies the signature BEFORE trusting expiry.

## QA test steps
1. Enable the **Reply-by-Email** extension (Pro).
2. Ensure a user has email notifications on; trigger a **reply to their post**.
   - **The notification email must send with no fatal / no error in the log** (this is the critical regression - before, enabling the extension broke ALL notification emails).
   - The email headers include `Reply-To: reply+<token>@<domain>`.
3. Also confirm non-reply notifications (mention, vote, accepted-answer, welcome, verification) still send fine.
4. (Full loop, needs inbound email configured) Reply from the email -> a forum reply is created on that post. A tampered/expired token -> rejected.

**Pass =** enabling Reply-by-Email no longer breaks notification emails; reply notifications carry a valid Reply-To; forged/expired tokens are refused.
