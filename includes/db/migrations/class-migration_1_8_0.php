<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.8.0 — purge user data the pre-1.7.1 delete gap left orphaned.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

use Jetonomy\Privacy_Backfill;

defined( 'ABSPATH' ) || exit;

/**
 * Remediates data, not schema. Nothing here touches a table definition.
 *
 * 1.7.1 fixed the purge going forward: `jt_pro_ai_log` joined the purge list,
 * and the two multisite deletion paths that never fired `delete_user`
 * (`wpmu_delete_user()`, `remove_user_from_blog()`) got listeners. From that
 * release on, deleting a user cleans up correctly.
 *
 * It did nothing for the users deleted BEFORE it. Their rows — including the
 * raw AI request/response payloads in `jt_pro_ai_log` — are still in the
 * database on every install that has ever deleted an account. The site owner
 * who ran an erasure request last month believes that data is gone because we
 * told them it was. It isn't. Closing a hole is not the same as remediating
 * what fell through it, and "no new violations" is not compliance.
 *
 * So the upgrade sweeps the leftovers. The sweep is queued, not run inline:
 * migrations execute in whatever request first notices the version bump, and
 * a big site's log table would hang or time out that request. See
 * {@see Privacy_Backfill} for the batching, the scale reasoning, and why
 * `user_id = 0` is deliberately left alone.
 */
class Migration_1_8_0 {

	public function up(): void {
		Privacy_Backfill::schedule();
	}
}
