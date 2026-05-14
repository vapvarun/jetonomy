<?php
/**
 * Icon picker partial — single source of truth for Lucide-icon selection.
 *
 * Renders a searchable grid of icons from <code>assets/icons/</code>. Used by
 * the frontend new-space + space-edit forms and by every admin form that needs
 * to pick a Lucide icon (admin spaces, admin categories, Pro badges).
 *
 * Args passed via Template_Loader::partial:
 *
 *   field_name   string  Name attribute for the hidden / radio input.
 *                        Default 'icon'.
 *   current_value string  Currently saved icon slug. Drives the initial
 *                        selection. Default 'users'.
 *   id_prefix    string  Unique prefix for the search input + radio ids so
 *                        the partial can be rendered multiple times on the
 *                        same page (e.g. categories has create + edit forms
 *                        in the same DOM). Default 'jt-ip'.
 *   palette      array   Optional override of the icon set. Each entry:
 *                        { name: string (must match an SVG in assets/icons/),
 *                          label: string (translatable),
 *                          keywords: string (space-separated search terms),
 *                          extended?: bool (hidden until "Show more icons") }.
 *                        Falls back to the canonical 24-icon palette.
 *   label        string  Optional visible label rendered above the picker.
 *                        Pass empty string to suppress (forms that supply
 *                        their own label markup pass '').
 *   help         string  Optional help text rendered below the picker.
 *
 * The picker is wired by assets/js/jetonomy-icon-picker.js (auto-discovers
 * every <code>[data-jt-icon-picker]</code> on the page).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$jt_ip_field_name = isset( $field_name ) && '' !== (string) $field_name ? (string) $field_name : 'icon';
$jt_ip_current    = isset( $current_value ) && '' !== (string) $current_value ? (string) $current_value : 'users';
$jt_ip_id_prefix  = isset( $id_prefix ) && '' !== (string) $id_prefix ? sanitize_html_class( (string) $id_prefix ) : 'jt-ip';
$jt_ip_label      = isset( $label ) ? (string) $label : __( 'Icon', 'jetonomy' );
$jt_ip_help       = isset( $help ) ? (string) $help : '';
$jt_ip_palette    = isset( $palette ) && is_array( $palette ) && ! empty( $palette )
	? $palette
	: array(
		array(
			'name'     => 'users',
			'label'    => __( 'Users', 'jetonomy' ),
			'keywords' => 'users people group community members',
		),
		array(
			'name'     => 'hand',
			'label'    => __( 'Welcome', 'jetonomy' ),
			'keywords' => 'hand wave welcome hi hello onboarding',
		),
		array(
			'name'     => 'megaphone',
			'label'    => __( 'Announcements', 'jetonomy' ),
			'keywords' => 'megaphone announcements news broadcast',
		),
		array(
			'name'     => 'message-circle',
			'label'    => __( 'Discussion', 'jetonomy' ),
			'keywords' => 'message chat discussion talk thread forum',
		),
		array(
			'name'     => 'help-circle',
			'label'    => __( 'Q&A', 'jetonomy' ),
			'keywords' => 'help question qa answer faq support',
		),
		array(
			'name'     => 'lightbulb',
			'label'    => __( 'Ideas', 'jetonomy' ),
			'keywords' => 'lightbulb ideas suggestion brainstorm feedback',
		),
		array(
			'name'     => 'star',
			'label'    => __( 'Tips', 'jetonomy' ),
			'keywords' => 'star tips favorite featured highlight best',
		),
		array(
			'name'     => 'rocket',
			'label'    => __( 'Showcase', 'jetonomy' ),
			'keywords' => 'rocket showcase launch projects releases',
		),
		array(
			'name'     => 'book-open',
			'label'    => __( 'Tutorials', 'jetonomy' ),
			'keywords' => 'book tutorials guide learn docs documentation',
		),
		array(
			'name'     => 'award',
			'label'    => __( 'Achievements', 'jetonomy' ),
			'keywords' => 'award achievements badge medal trophy',
		),
		array(
			'name'     => 'shield',
			'label'    => __( 'Moderation', 'jetonomy' ),
			'keywords' => 'shield moderation security trust safe staff',
		),
		array(
			'name'     => 'pin',
			'label'    => __( 'Pinned', 'jetonomy' ),
			'keywords' => 'pin pinned sticky important highlight',
		),
		array(
			'name'     => 'bookmark',
			'label'    => __( 'Resources', 'jetonomy' ),
			'keywords' => 'bookmark resources save library reference',
		),
		array(
			'name'     => 'home',
			'label'    => __( 'Home', 'jetonomy' ),
			'keywords' => 'home main lobby general default',
		),
		array(
			'name'     => 'hash',
			'label'    => __( 'Topic', 'jetonomy' ),
			'keywords' => 'hash topic channel tag feed',
		),
		array(
			'name'     => 'folder',
			'label'    => __( 'Category', 'jetonomy' ),
			'keywords' => 'folder category group section bucket',
		),
		array(
			'name'     => 'user',
			'label'    => __( 'Profile', 'jetonomy' ),
			'keywords' => 'user profile member account',
			'extended' => true,
		),
		array(
			'name'     => 'settings',
			'label'    => __( 'Settings', 'jetonomy' ),
			'keywords' => 'settings config options gear admin',
			'extended' => true,
		),
		array(
			'name'     => 'bell',
			'label'    => __( 'Alerts', 'jetonomy' ),
			'keywords' => 'bell alerts notifications updates',
			'extended' => true,
		),
		array(
			'name'     => 'flag',
			'label'    => __( 'Reports', 'jetonomy' ),
			'keywords' => 'flag reports complaint mark issue',
			'extended' => true,
		),
		array(
			'name'     => 'image',
			'label'    => __( 'Gallery', 'jetonomy' ),
			'keywords' => 'image gallery photo media picture',
			'extended' => true,
		),
		array(
			'name'     => 'eye',
			'label'    => __( 'Watch', 'jetonomy' ),
			'keywords' => 'eye watch view see preview observe',
			'extended' => true,
		),
		array(
			'name'     => 'lock',
			'label'    => __( 'Private', 'jetonomy' ),
			'keywords' => 'lock private secure restricted closed',
			'extended' => true,
		),
		array(
			'name'     => 'smile-plus',
			'label'    => __( 'Reactions', 'jetonomy' ),
			'keywords' => 'smile reactions emoji feedback emotion',
			'extended' => true,
		),
	);

// If the currently saved value isn't already in the palette (e.g. legacy
// emoji or a custom slug), prepend a synthetic entry so the field round-trips
// without resetting silently. The synthetic entry is hidden behind the more
// button so it doesn't pollute the default grid.
$jt_ip_palette_names = array_map(
	static function ( $entry ) {
		return isset( $entry['name'] ) ? (string) $entry['name'] : '';
	},
	$jt_ip_palette
);
if ( ! in_array( $jt_ip_current, $jt_ip_palette_names, true ) ) {
	array_unshift(
		$jt_ip_palette,
		array(
			'name'     => $jt_ip_current,
			/* translators: %s: icon identifier kept from a legacy save */
			'label'    => sprintf( __( 'Saved: %s', 'jetonomy' ), $jt_ip_current ),
			'keywords' => $jt_ip_current,
			'extended' => true,
		)
	);
}

$jt_ip_search_id = $jt_ip_id_prefix . '-search';
?>
<?php if ( '' !== $jt_ip_label ) : ?>
	<label for="<?php echo esc_attr( $jt_ip_search_id ); ?>" class="jt-icon-picker-label"><?php echo esc_html( $jt_ip_label ); ?></label>
<?php endif; ?>
<div class="jt-icon-picker-wrap" data-jt-icon-picker>
	<div class="jt-icon-picker-search">
		<span class="jt-icon-picker-search-icon" aria-hidden="true"><?php jetonomy_echo_icon( 'search', 16 ); ?></span>
		<input type="search"
			id="<?php echo esc_attr( $jt_ip_search_id ); ?>"
			class="jt-input"
			data-jt-icon-search
			placeholder="<?php esc_attr_e( 'Search icons…', 'jetonomy' ); ?>"
			autocomplete="off">
	</div>
	<div class="jt-icon-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Choose an icon', 'jetonomy' ); ?>">
		<?php foreach ( $jt_ip_palette as $jt_ip_entry ) : ?>
			<?php
			$jt_ip_name        = isset( $jt_ip_entry['name'] ) ? (string) $jt_ip_entry['name'] : '';
			$jt_ip_entry_label = isset( $jt_ip_entry['label'] ) ? (string) $jt_ip_entry['label'] : $jt_ip_name;
			$jt_ip_keywords    = isset( $jt_ip_entry['keywords'] ) ? (string) $jt_ip_entry['keywords'] : '';
			$jt_ip_is_extended = ! empty( $jt_ip_entry['extended'] );
			$jt_ip_is_selected = ( $jt_ip_name === $jt_ip_current );
			?>
			<label class="jt-icon-option<?php echo $jt_ip_is_selected ? ' is-selected' : ''; ?>"
				data-jt-icon-keywords="<?php echo esc_attr( $jt_ip_entry_label . ' ' . $jt_ip_keywords ); ?>"
				data-jt-icon-extended="<?php echo $jt_ip_is_extended ? '1' : '0'; ?>"
				title="<?php echo esc_attr( $jt_ip_entry_label ); ?>"
				<?php echo $jt_ip_is_extended && ! $jt_ip_is_selected ? 'hidden' : ''; ?>>
				<input type="radio"
					name="<?php echo esc_attr( $jt_ip_field_name ); ?>"
					value="<?php echo esc_attr( $jt_ip_name ); ?>"
					<?php checked( $jt_ip_is_selected ); ?>
					aria-label="<?php echo esc_attr( $jt_ip_entry_label ); ?>">
				<span class="jt-icon-option-svg" aria-hidden="true">
					<?php jetonomy_echo_icon( $jt_ip_name, 22 ); ?>
				</span>
			</label>
		<?php endforeach; ?>
		<p class="jt-icon-picker-empty" data-jt-icon-empty hidden><?php esc_html_e( 'No icons match.', 'jetonomy' ); ?></p>
	</div>
	<button type="button" class="jt-btn jt-btn-ghost jt-icon-picker-more" data-jt-icon-more>
		<?php esc_html_e( 'Show more icons', 'jetonomy' ); ?>
	</button>
</div>
<?php if ( '' !== $jt_ip_help ) : ?>
	<p class="jt-form-help"><?php echo esc_html( $jt_ip_help ); ?></p>
<?php endif; ?>
