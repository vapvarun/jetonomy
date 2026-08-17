<?php
/**
 * Static guard: admin tables must go through the responsive contract.
 *
 * Part of the root-cause remediation for Basecamp 10146443346: hand-rolled
 * `<table>` markup in admin views kept omitting the core small-screen
 * semantics, so mobile/iPad rendering broke again with every new screen.
 * The shared renderer (jetonomy_admin_table) and the settings-matrix class
 * make a table responsive by construction; this script makes bypassing them
 * a BUILD FAILURE instead of a QA bounce.
 *
 * A raw `<table` in an admin view is allowed only when the file is listed in
 * the baseline below (legacy tables queued for migration - shrink-only, same
 * discipline as phpstan-baseline) or the table carries one of the compliant
 * markers on the same line: `jt-settings-matrix` or
 * `jetonomy-audit-table-ok` (an explicit, greppable opt-out comment for the
 * rare genuinely-static table; every opt-out must say why).
 *
 * Usage:
 *   php bin/audit-admin-tables.php includes/admin/views [more dirs...]
 * Exit 0 = clean, 1 = new unbaselined offender (fails the release build).
 *
 * @package Jetonomy
 */

// Shrink-only baseline: legacy files with raw tables, queued for migration.
// REMOVE entries as views migrate to jetonomy_admin_table() / the matrix
// class. Adding an entry requires the same justification as baselining a
// phpstan error - a card reference, not convenience.
$baseline = array(
	// Free (tranche 2 of Basecamp 10146443346):
	'includes/admin/views/revisions.php',
	'includes/admin/views/users.php', // already core-compliant by hand; migrate for uniformity.
	// Pro (tranche 3 - paths relative to the DIRECTORY ARGUMENT given):
	'includes/extensions/anonymous-posting/views/space-setting.php',
	'includes/extensions/ai/class-extension.php',
	'includes/extensions/attachments/views/settings.php',
	'includes/extensions/email-digest/class-extension.php',
	'includes/extensions/private-messaging/class-admin-page.php',
	'includes/extensions/reactions/class-extension.php',
	'includes/extensions/reply-by-email/class-extension.php',
	'includes/extensions/seo-pro/class-extension.php',
	'includes/extensions/web-push/class-extension.php',
	'includes/extensions/white-label/class-extension.php',
	'includes/adapters/class-tag-space-integration.php',
	'includes/class-jetonomy-pro.php',
);

$dirs = array_slice( $argv, 1 );
if ( ! $dirs ) {
	$dirs = array( 'includes/admin/views' );
}

$offenders  = array();
$baselined  = 0;
$root       = getcwd();

foreach ( $dirs as $dir ) {
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iter as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		$path = str_replace( '\\', '/', $file->getPathname() );
		// CLI surfaces have no admin markup; `--format=<table|json>` docblocks
		// are not tables.
		if ( false !== strpos( $path, '/cli/' ) ) {
			continue;
		}
		$src = (string) file_get_contents( $path );
		if ( ! preg_match( '/<table[\s>]/', $src ) ) {
			continue;
		}

		// Balance check: an unmatched </table> means a migration left stale
		// closers behind (the helper emits its own) - broken nesting that can
		// close a tab container early (Basecamp 10146440826 regression).
		$opens  = preg_match_all( '/<table[\s>]/i', $src );
		$closes = substr_count( strtolower( $src ), '</table>' );
		if ( $opens !== $closes ) {
			$offenders[] = $path . ': unbalanced <table> (' . $opens . ' open / ' . $closes . ' close)';
			continue;
		}

		// Per-line: raw <table without a compliant marker on that line.
		$raw_lines = array();
		foreach ( explode( "\n", $src ) as $n => $line ) {
			if ( ! preg_match( '/<table[\s>]/', $line ) ) {
				continue;
			}
			if ( false !== strpos( $line, 'jt-settings-matrix' )
				|| false !== strpos( $line, 'jetonomy-audit-table-ok' ) ) {
				continue;
			}
			$raw_lines[] = $n + 1;
		}
		if ( ! $raw_lines ) {
			continue;
		}

		$is_baselined = false;
		foreach ( $baseline as $b ) {
			if ( substr( $path, -strlen( $b ) ) === $b ) {
				$is_baselined = true;
				break;
			}
		}
		if ( $is_baselined ) {
			++$baselined;
			continue;
		}
		$offenders[] = $path . ':' . implode( ',', $raw_lines );
	}
}

if ( $offenders ) {
	fwrite( STDERR, "audit-admin-tables: FAIL - raw <table> outside the responsive contract:\n" );
	foreach ( $offenders as $o ) {
		fwrite( STDERR, "    {$o}\n" );
	}
	fwrite( STDERR, "    Render through jetonomy_admin_table(), use class jt-settings-matrix for\n" );
	fwrite( STDERR, "    editable config grids, or add `jetonomy-audit-table-ok` with a reason.\n" );
	exit( 1 );
}

printf( "audit-admin-tables: OK (%d legacy file(s) baselined - shrink this list as views migrate)\n", $baselined );
exit( 0 );
