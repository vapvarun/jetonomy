<?php
/**
 * Connect-app approve screen — the last hop of the native-app connect bridge.
 *
 * Wbcom App Auth standard (docs/standards/app-auth.md, phase J3). The member
 * arrived signed in (Template_Loader's $auth_required_routes sends logged-out
 * visitors through wp_login_url() with THIS full URL as the destination, so
 * the site's whole auth stack — password, 2FA plugins — runs first). This
 * screen asks one question, mints the Application Password over REST, and
 * hands it to the app on its custom scheme.
 *
 * The credential is delivered by CLIENT-SIDE navigation plus a visible
 * tap-to-continue link, never a server 302: a Location header carrying a
 * password lands in proxy and access logs, and some in-app browsers suppress
 * scripted custom-scheme navigation — the manual link covers those.
 *
 * ONE DOOR PER SITE: when BuddyNext is active, its bridge is the site's
 * bridge — this route defers by redirecting there with the query intact, so
 * even a stale URL walks through the right door.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Integrations\App_Connect;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only render params; the one-time bridge token minted below gates the actual mint.
$jt_app_name = isset( $_GET['app_name'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['app_name'] ) ) : '';
$jt_app_id   = isset( $_GET['app_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['app_id'] ) ) : '';
$jt_scheme   = isset( $_GET['scheme'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['scheme'] ) ) : '';
$jt_state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

nocache_headers();

// One door: BuddyNext owns the bridge when present.
if ( class_exists( '\\BuddyNext\\App\\AppConnectService' ) ) {
	$jt_bn_query = array_filter(
		array(
			'app_name' => $jt_app_name,
			'app_id'   => $jt_app_id,
			'scheme'   => $jt_scheme,
			'state'    => $jt_state,
		)
	);
	wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $jt_bn_query ), \BuddyNext\App\AppConnectService::connect_url() ) );
	exit;
}

$jt_scheme_ok = App_Connect::allowed_scheme( $jt_scheme );
$jt_viewer    = wp_get_current_user();
$jt_app_label = '' !== $jt_app_name ? $jt_app_name : __( 'the app', 'jetonomy' );

if ( $jt_scheme_ok ) {
	wp_enqueue_script_module(
		'jetonomy-connect-app',
		JETONOMY_URL . 'assets/js/connect-app.js',
		array( array( 'id' => '@wordpress/interactivity' ) ),
		JETONOMY_VERSION
	);
}
?>

<div class="jt-app">
	<div class="jt-container jt-connect-app">

		<?php if ( ! $jt_scheme_ok ) : ?>
			<h1 class="jt-page-title"><?php esc_html_e( 'This connection link is not valid', 'jetonomy' ); ?></h1>
			<p><?php esc_html_e( 'The link that opened this page did not come from an app this site recognises. Go back to the app and try connecting again.', 'jetonomy' ); ?></p>
			<a class="jt-btn jt-btn-fill" href="<?php echo esc_url( \Jetonomy\base_url() ); ?>">
				<?php esc_html_e( 'Back to the community', 'jetonomy' ); ?>
			</a>
		<?php else : ?>
			<div
				data-wp-interactive="jetonomy/connect-app"
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
				echo wp_interactivity_data_wp_context(
					array(
						'restUrl'     => esc_url_raw( rest_url( 'jetonomy/v1/' ) ),
						'restNonce'   => wp_create_nonce( 'wp_rest' ),
						'bridgeToken' => App_Connect::issue_bridge_token(),
						'appName'     => $jt_app_name,
						'appId'       => $jt_app_id,
						'scheme'      => $jt_scheme,
						'state'       => $jt_state,
						'busy'        => false,
						'connected'   => false,
						'deepLink'    => '',
						'error'       => '',
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			>
				<h1 class="jt-page-title">
					<?php
					printf(
						/* translators: %s: the connecting app's name. */
						esc_html__( 'Connect %s to your account?', 'jetonomy' ),
						'<strong>' . esc_html( $jt_app_label ) . '</strong>'
					);
					?>
				</h1>
				<p>
					<?php
					printf(
						/* translators: 1: member display name, 2: member login. */
						esc_html__( 'You are signed in as %1$s (%2$s). The app gets its own access key for this account — you can see and revoke it any time from your profile in the dashboard.', 'jetonomy' ),
						'<strong>' . esc_html( \Jetonomy\user_display_name( $jt_viewer ) ) . '</strong>',
						esc_html( $jt_viewer->user_login )
					);
					?>
				</p>

				<p role="alert" aria-live="polite"
					data-wp-bind--hidden="!state.error"
					data-wp-text="state.error"></p>

				<div data-wp-bind--hidden="state.connected">
					<button type="button" class="jt-btn jt-btn-fill"
						data-wp-on--click="actions.approve"
						data-wp-bind--disabled="state.busy">
						<span data-wp-bind--hidden="state.busy"><?php esc_html_e( 'Yes, connect the app', 'jetonomy' ); ?></span>
						<span data-wp-bind--hidden="!state.busy"><?php esc_html_e( 'Connecting…', 'jetonomy' ); ?></span>
					</button>
					<p>
						<a href="<?php echo esc_url( wp_logout_url( \Jetonomy\current_url() ) ); ?>">
							<?php esc_html_e( 'Not you? Sign in as someone else', 'jetonomy' ); ?>
						</a>
					</p>
				</div>

				<div data-wp-bind--hidden="!state.connected">
					<p><?php esc_html_e( 'Connected. Opening the app…', 'jetonomy' ); ?></p>
					<a class="jt-btn jt-btn-fill" data-wp-bind--href="state.deepLink">
						<?php esc_html_e( 'Open the app', 'jetonomy' ); ?>
					</a>
					<p><?php esc_html_e( 'If nothing happens, tap the button above to return to the app.', 'jetonomy' ); ?></p>
				</div>
			</div>
		<?php endif; ?>

	</div>
</div>
