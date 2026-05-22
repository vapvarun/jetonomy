<?php
/**
 * WP Admin Bar integration.
 *
 * Adds a "Community" menu to the admin bar with quick links to the public
 * community surfaces (home, notifications, profile) for any logged-in user,
 * plus admin-only shortcuts to the Jetonomy management pages (spaces,
 * categories, moderation, settings) for users who can see the wp-admin
 * Jetonomy menu.
 *
 * Mirrors the bbPress "Forums" admin bar menu pattern so site admins have
 * a one-click jump from any front-end page into the management views.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Community" entry on the wp-admin bar across the front-end and
 * wp-admin. Stateless — every method is static and the class is wired by
 * Jetonomy::init() at plugins_loaded.
 */
class Admin_Bar {

	/**
	 * Register the admin_bar_menu hook. Called once from Jetonomy::init().
	 */
	public static function register(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'render_menu' ), 60 );
	}

	/**
	 * Render the Community menu and its child nodes.
	 *
	 * Logged-in users get the public links (home, notifications, profile).
	 * Users with `manage_options` or `jetonomy_manage_spaces` additionally
	 * get the wp-admin shortcuts.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function render_menu( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user     = wp_get_current_user();
		$base     = base_url();
		$is_admin = current_user_can( 'manage_options' ) || current_user_can( 'jetonomy_manage_spaces' );

		// Parent — always rendered for logged-in users.
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'jetonomy-community',
				'title' => '<span class="ab-icon dashicons dashicons-groups" style="margin-top:2px;"></span><span class="ab-label">' . esc_html__( 'Community', 'jetonomy' ) . '</span>',
				'href'  => esc_url( $base . '/' ),
				'meta'  => array(
					'title' => __( 'Open the community home', 'jetonomy' ),
				),
			)
		);

		// Public sub-links.
		$wp_admin_bar->add_menu(
			array(
				'parent' => 'jetonomy-community',
				'id'     => 'jetonomy-community-home',
				'title'  => __( 'Browse spaces', 'jetonomy' ),
				'href'   => esc_url( $base . '/' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'jetonomy-community',
				'id'     => 'jetonomy-community-notifications',
				'title'  => __( 'My notifications', 'jetonomy' ),
				'href'   => esc_url( $base . '/notifications/' ),
			)
		);

		if ( ! empty( $user->user_login ) ) {
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'jetonomy-community',
					'id'     => 'jetonomy-community-profile',
					'title'  => __( 'My profile', 'jetonomy' ),
					'href'   => esc_url( $base . '/u/' . rawurlencode( $user->user_login ) . '/' ),
				)
			);

			$wp_admin_bar->add_menu(
				array(
					'parent' => 'jetonomy-community',
					'id'     => 'jetonomy-community-edit-profile',
					'title'  => __( 'Edit profile', 'jetonomy' ),
					'href'   => esc_url( $base . '/u/' . rawurlencode( $user->user_login ) . '/edit/' ),
				)
			);
		}

		// Context-aware "Edit this space" entry — shown on any space-context
		// route to a member who can administer THIS space. Gated by the same
		// Permission_Engine::is_space_admin check as the /edit/ template, so
		// non-admins never see a link they can't open. Space admins who are
		// not site admins still get the shortcut without having to go to
		// wp-admin first.
		$route        = (string) get_query_var( 'jetonomy_route' );
		$space_routes = array( 'space', 'space-members', 'space-roadmap', 'space-moderation', 'new-post', 'post' );
		if ( in_array( $route, $space_routes, true ) ) {
			$space_slug = (string) get_query_var(
				'post' === $route ? 'jetonomy_space_slug' : 'jetonomy_slug'
			);
			if ( '' !== $space_slug && class_exists( '\Jetonomy\Models\Space' ) ) {
				$space = \Jetonomy\Models\Space::find_by_slug( $space_slug );
				if ( $space && class_exists( '\Jetonomy\Permissions\Permission_Engine' )
					&& \Jetonomy\Permissions\Permission_Engine::is_space_admin( (int) $user->ID, (int) $space->id )
				) {
					$wp_admin_bar->add_menu(
						array(
							'parent' => 'jetonomy-community',
							'id'     => 'jetonomy-community-edit-space',
							'title'  => __( 'Edit this space', 'jetonomy' ),
							'href'   => esc_url( $base . '/s/' . rawurlencode( $space->slug ) . '/edit/' ),
						)
					);
				}
			}
		}

		if ( ! $is_admin ) {
			return;
		}

		// Admin-only shortcuts. Group under a separator so they read as a
		// distinct cluster from the public links above.
		$wp_admin_bar->add_group(
			array(
				'parent' => 'jetonomy-community',
				'id'     => 'jetonomy-community-admin',
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);

		$admin_links = array(
			'spaces'     => array(
				'label' => __( 'Manage spaces', 'jetonomy' ),
				'url'   => admin_url( 'admin.php?page=jetonomy-spaces' ),
			),
			'new-space'  => array(
				'label' => __( 'Add new space', 'jetonomy' ),
				'url'   => admin_url( 'admin.php?page=jetonomy-spaces&action=new' ),
			),
			'categories' => array(
				'label' => __( 'Categories', 'jetonomy' ),
				'url'   => admin_url( 'admin.php?page=jetonomy-categories' ),
			),
			'moderation' => array(
				'label' => __( 'Moderation queue', 'jetonomy' ),
				'url'   => admin_url( 'admin.php?page=jetonomy-moderation' ),
			),
			'content'    => array(
				'label' => __( 'Posts &amp; replies', 'jetonomy' ),
				'url'   => admin_url( 'admin.php?page=jetonomy-content' ),
			),
			'settings'   => array(
				'label' => __( 'Settings', 'jetonomy' ),
				'url'   => admin_url( 'admin.php?page=jetonomy-settings' ),
			),
		);

		foreach ( $admin_links as $slug => $entry ) {
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'jetonomy-community-admin',
					'id'     => 'jetonomy-community-admin-' . $slug,
					'title'  => $entry['label'],
					'href'   => esc_url( $entry['url'] ),
				)
			);
		}
	}
}
