# A10 — Verification reminder cron

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** low — additive cron + new email template
**Estimated time:** 1 day

## Problem

When a user registers and email verification is required (`jetonomy_settings.require_email_verification = true`), they get one verification email. If they don't click it within ~24 hours, they go silent forever — there's no nudge. The free plugin already fires `jetonomy_user_pending_verification` action (orphan hook from the audit) but no consumer.

## Implementation

### Files

```
includes/notifications/class-verification-reminder.php  (new)
includes/class-cron.php                                  (add the schedule)
```

### Cron registration

Add to existing cron bootstrap:

```php
add_action( 'jetonomy_verification_reminder', [ Verification_Reminder::class, 'run' ] );

if ( ! wp_next_scheduled( 'jetonomy_verification_reminder' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'jetonomy_verification_reminder' );
}
```

### Reminder logic

```php
class Verification_Reminder {
    public static function run(): void {
        $threshold = (int) ( get_option( 'jetonomy_settings' )['verification_reminder_hours'] ?? 24 );
        $cutoff    = current_time( 'mysql' );
        
        // Find users registered ≥ N hours ago, still unverified, not yet reminded
        $users = User_Profile::query()
            ->where( 'email_verified', false )
            ->where( 'created_at', '<', $cutoff_minus_threshold )
            ->where( 'verification_reminder_sent_at', null )
            ->limit( 50 )  // batch — avoid running for too long
            ->get();

        foreach ( $users as $user ) {
            self::send_reminder( $user );
            User_Profile::touch( $user->user_id, [ 'verification_reminder_sent_at' => current_time( 'mysql' ) ] );
        }
    }

    private static function send_reminder( $user ): void {
        $template = Email_Template::render( 'verification_reminder', [
            'user'        => $user,
            'verify_url'  => Auth::get_verify_url( $user ),
            'resend_url'  => Auth::get_resend_url( $user ),
        ] );
        wp_mail( $user->email, $template->subject, $template->html );
    }
}
```

### New email template

Add to default `jetonomy_email_templates` option migration: `verification_reminder` with placeholder content (admin can customize via A8 once it ships).

### DB migration

If `verification_reminder_sent_at` column doesn't exist on `jt_user_profiles`, add it via a new migration class (`includes/db/migrations/class-migration_1_4_1.php`).

### Rate-limiting

The query filter (`verification_reminder_sent_at IS NULL`) is the rate limit — once sent, never sends again. **Do not add a "second reminder at T+72h"** in v1; that's a separate UX call.

### Subscribe to existing orphan hook (optional bonus)

The `jetonomy_user_pending_verification` action is fired from `Auth_Controller::register_user()`. We could subscribe to it as a back-up trigger (covers the edge case where cron is disabled), but cron is the primary path.

## Safety checks

1. **PRE:**
   ```bash
   wp cron event list --fields=hook,next_run | grep jetonomy_   # snapshot existing crons
   wp db query "SELECT COUNT(*) FROM wp_jt_user_profiles WHERE email_verified=0"  # unverified users count
   ```

2. **Implement, run cron once manually:**
   ```bash
   wp cron event run jetonomy_verification_reminder
   ```

3. **POST:**
   - New cron `jetonomy_verification_reminder` registered, schedule `hourly`
   - Create unverified user (no email confirmation): row in `jt_user_profiles` with `email_verified=0`
   - Run cron: reminder email sent ONCE; `verification_reminder_sent_at` populated
   - Run cron again immediately: NO duplicate email (rate-limit honored)
   - Verify the user manually → run cron → no email
   - Existing crons unchanged: `jetonomy_trust_evaluation`, `_cleanup_expired`, `_prune_activity`, etc., still in event list

4. **Smoke** 210/210; runner `--diff-baseline` no drift (REST unchanged)

## Commits

```
1. feat(db): add verification_reminder_sent_at to user_profiles (1.4.1 migration)
2. feat(cron): jetonomy_verification_reminder hourly schedule + Verification_Reminder class (A10)
3. feat(email): default verification_reminder template (admin can customize via A8)
```

## Done criteria

- [ ] Migration adds the column without breaking existing rows
- [ ] Cron registered + scheduled hourly
- [ ] Run sends one email per unverified user, never duplicates
- [ ] Verified users get no email
- [ ] Existing crons unchanged
- [ ] CHECKLIST marks A10 done
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't disable existing crons
- ❌ Don't send 2nd / 3rd reminders (single nudge in v1)
- ❌ Don't bypass the user's email-notification preferences if they exist (e.g., `jetonomy_email_opt_out` user meta)
