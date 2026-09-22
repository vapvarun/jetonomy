<?php
/**
 * Space sub-navigation tabs.
 *
 * The single source of the space tab strip. It lived inline in space.php while
 * space-roadmap.php hand-rolled its own two links and space-members.php had
 * none at all, so the tabs vanished on Members (no way back, no active state)
 * and a tab added via `jetonomy_space_tabs` appeared on the topic listing but
 * not on Roadmap. One partial keeps the three views honest.
 *
 * @package Jetonomy
 *
 * @var object $space     The space being viewed.
 * @var string $space_url Trailing-slashed URL of the space.
 * @var string $active    Slug of the tab representing this view: 'primary',
 *                        'roadmap' or 'members'.
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

if ( empty( $space ) || empty( $space_url ) ) {
	return;
}

$jt_active    = $active ?? 'primary';
$jt_space_url = $space_url;

$jt_primary_labels = array(
	'forum' => __( 'Discussions', 'jetonomy' ),
	'qa'    => __( 'Questions', 'jetonomy' ),
	'ideas' => __( 'Ideas', 'jetonomy' ),
	'feed'  => __( 'Posts', 'jetonomy' ),
);

// Built-in tabs as an ordered, filterable map. Each entry:
// slug => [ 'label' => string, 'url' => string, 'active' => bool ].
$jt_space_tabs = array(
	'primary' => array(
		'label'  => $jt_primary_labels[ $space->type ?? 'forum' ] ?? __( 'Discussions', 'jetonomy' ),
		'url'    => $jt_space_url,
		'active' => 'primary' === $jt_active,
	),
);

if ( 'ideas' === ( $space->type ?? '' ) ) {
	$jt_space_tabs['roadmap'] = array(
		'label'  => __( 'Roadmap', 'jetonomy' ),
		'url'    => $jt_space_url . 'roadmap/',
		'active' => 'roadmap' === $jt_active,
	);
}

// Space moderators can reach the members page (and its pending join-request
// approval panel) without knowing the direct URL. (#10013900410)
$jt_show_members = is_user_logged_in();

if ( $jt_show_members ) {
	$jt_space_tabs['members'] = array(
		'label'  => jetonomy_label( 'member', true ),
		'url'    => $jt_space_url . 'members/',
		'active' => 'members' === $jt_active,
	);
}

/**
 * Filters the space sub-navigation tabs.
 *
 * Add, remove, reorder, or relabel the tabs on a space page. Each tab
 * is `slug => [ 'label' => string, 'url' => string, 'active' => bool ]`.
 * Set 'active' on the tab representing the current view. A custom tab
 * typically links to a route registered via `jetonomy_template_map`.
 * The nav renders when there is more than one tab.
 *
 * @since 1.5.0
 *
 * @param array<string,array{label:string,url:string,active?:bool}> $jt_space_tabs Ordered tab map.
 * @param object $space           The space being viewed.
 * @param bool   $jt_show_members Whether the Members tab is shown (viewer logged in).
 */
$jt_space_tabs = apply_filters( 'jetonomy_space_tabs', $jt_space_tabs, $space, $jt_show_members );

if ( ! is_array( $jt_space_tabs ) || count( $jt_space_tabs ) < 2 ) {
	return;
}
?>
<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
<nav class="jt-space-tabs" aria-label="<?php echo esc_attr( sprintf( __( '%s sections', 'jetonomy' ), space_label() ) ); ?>">
	<?php
	foreach ( $jt_space_tabs as $jt_space_tab ) :
		if ( empty( $jt_space_tab['label'] ) || ! isset( $jt_space_tab['url'] ) ) {
			continue;
		}
		$jt_tab_on = ! empty( $jt_space_tab['active'] );
		?>
		<a href="<?php echo esc_url( $jt_space_tab['url'] ); ?>" class="jt-space-tab <?php echo $jt_tab_on ? 'on' : ''; ?>"<?php echo $jt_tab_on ? ' aria-current="page"' : ''; ?>>
			<?php echo esc_html( $jt_space_tab['label'] ); ?>
		</a>
	<?php endforeach; ?>
</nav>
