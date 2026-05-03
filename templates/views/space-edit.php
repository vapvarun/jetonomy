<?php
/**
 * Front-end space edit (G5).
 *
 * Reuses PATCH /jetonomy/v1/spaces/{id} so a space admin can edit title,
 * description, type, visibility, join policy, icon, and cover image
 * without dropping into wp-admin. Permission gate: must be a space admin.
 *
 * Cover image upload runs through POST /jetonomy/v1/media so the wp-admin
 * `upload_files` cap is not required — non-author space admins can still
 * upload covers, matching the customer-control promise of G5.
 *
 * Icon uses a visual Lucide picker (no text input, no emoji input).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$slug  = (string) ( $data['slug'] ?? '' );
$space = '' !== $slug ? \Jetonomy\Models\Space::find_by_slug( $slug ) : null;

if ( ! $space ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Space not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

if ( ! \Jetonomy\Permissions\Permission_Engine::is_space_admin( get_current_user_id(), (int) $space->id ) ) {
	// 1.4.0: respond with 404 (not 403) so the URL existence isn't leaked
	// to non-admins, matching the pattern used by other gated views.
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Space not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$base   = \Jetonomy\base_url();
$crumbs = array(
	array(
		'label' => $space->title,
		'url'   => $base . '/s/' . $space->slug . '/',
	),
	array(
		'label' => __( 'Edit', 'jetonomy' ),
		'url'   => '',
	),
);

// Curated palette of Lucide icons that make sense as space icons. The
// first 16 entries render as 2 rows × 8 columns, icon-only. The
// 'extended' entries stay hidden until the viewer types in the search
// or clicks "Show more icons". `keywords` powers the live filter.
$icon_palette = array(
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

$current_icon   = (string) ( $space->icon ?? '' );
$space_settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
$categories     = \Jetonomy\Models\Category::list_top_level();
$posts_per_page = isset( $space_settings['posts_per_page'] ) && '' !== $space_settings['posts_per_page'] && (int) $space_settings['posts_per_page'] > 0
	? absint( $space_settings['posts_per_page'] )
	: '';
$prefixes       = ! empty( $space_settings['prefixes'] ) ? (array) $space_settings['prefixes'] : array();
$prefixes_on    = ! empty( $space_settings['enable_prefixes'] );
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<header class="jt-page-head">
			<h1 class="jt-page-title">
				<?php
				/* translators: %s: space title */
				echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), $space->title ) );
				?>
			</h1>
			<p class="jt-page-subtitle">
				<?php esc_html_e( 'Update the basics, swap the cover or icon, and tune who can join.', 'jetonomy' ); ?>
			</p>
		</header>

		<form id="jt-space-edit-form" class="jt-form jt-card"
			data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>"
			data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-jt-space-id="<?php echo (int) $space->id; ?>"
			data-jt-community-base="<?php echo esc_url( $base ); ?>">

			<div class="jt-form-row">
				<label for="jt-se-title"><?php esc_html_e( 'Space title', 'jetonomy' ); ?> <span class="jt-required" aria-hidden="true">*</span></label>
				<input type="text" id="jt-se-title" name="title" required maxlength="120" class="jt-input" value="<?php echo esc_attr( $space->title ); ?>">
			</div>

			<div class="jt-form-row">
				<label for="jt-se-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label>
				<textarea id="jt-se-description" name="description" rows="3" maxlength="280" class="jt-input"><?php echo esc_textarea( $space->description ?? '' ); ?></textarea>
			</div>

			<div class="jt-form-row">
				<label><?php esc_html_e( 'Cover image', 'jetonomy' ); ?></label>
				<div class="jt-cover-uploader" data-jt-cover>
					<div class="jt-cover-preview" data-jt-cover-preview <?php echo empty( $space->cover_image ) ? 'hidden' : ''; ?>>
						<?php if ( ! empty( $space->cover_image ) ) : ?>
							<img src="<?php echo esc_url( $space->cover_image ); ?>" alt="">
						<?php endif; ?>
					</div>
					<div class="jt-cover-actions">
						<label class="jt-btn jt-btn-ghost jt-cover-pick">
							<?php esc_html_e( 'Choose image', 'jetonomy' ); ?>
							<input type="file" accept="image/*" data-jt-cover-input hidden>
						</label>
						<button type="button" class="jt-btn jt-btn-ghost jt-cover-remove" data-jt-cover-remove <?php echo empty( $space->cover_image ) ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
						</button>
						<span class="jt-cover-status" data-jt-cover-status></span>
					</div>
				</div>
				<input type="hidden" name="cover_image" value="<?php echo esc_attr( $space->cover_image ?? '' ); ?>" data-jt-cover-value>
				<p class="jt-form-help"><?php esc_html_e( 'Wide banner shown at the top of the space page. Recommended 1500×400 px.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-icon-search"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
				<div class="jt-icon-picker-wrap" data-jt-icon-picker>
					<div class="jt-icon-picker-search">
						<span class="jt-icon-picker-search-icon" aria-hidden="true"><?php jetonomy_echo_icon( 'search', 16 ); ?></span>
						<input type="search" id="jt-se-icon-search" class="jt-input" data-jt-icon-search placeholder="<?php esc_attr_e( 'Search icons…', 'jetonomy' ); ?>" autocomplete="off">
					</div>
					<div class="jt-icon-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Choose a space icon', 'jetonomy' ); ?>">
						<?php foreach ( $icon_palette as $entry ) : ?>
							<?php $is_extended = ! empty( $entry['extended'] ); ?>
							<label class="jt-icon-option<?php echo $current_icon === $entry['name'] ? ' is-selected' : ''; ?>"
								data-jt-icon-keywords="<?php echo esc_attr( $entry['label'] . ' ' . $entry['keywords'] ); ?>"
								data-jt-icon-extended="<?php echo $is_extended ? '1' : '0'; ?>"
								title="<?php echo esc_attr( $entry['label'] ); ?>"
								<?php echo $is_extended && $current_icon !== $entry['name'] ? 'hidden' : ''; ?>>
								<input type="radio" name="icon" value="<?php echo esc_attr( $entry['name'] ); ?>" <?php checked( $current_icon, $entry['name'] ); ?> aria-label="<?php echo esc_attr( $entry['label'] ); ?>">
								<span class="jt-icon-option-svg" aria-hidden="true">
									<?php jetonomy_echo_icon( $entry['name'], 22 ); ?>
								</span>
							</label>
						<?php endforeach; ?>
						<p class="jt-icon-picker-empty" data-jt-icon-empty hidden><?php esc_html_e( 'No icons match.', 'jetonomy' ); ?></p>
					</div>
					<button type="button" class="jt-btn jt-btn-ghost jt-icon-picker-more" data-jt-icon-more>
						<?php esc_html_e( 'Show more icons', 'jetonomy' ); ?>
					</button>
				</div>
				<p class="jt-form-help"><?php esc_html_e( 'Pick the icon that matches what this space is about.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
				<select id="jt-se-type" name="type" class="jt-input">
					<option value="forum" <?php selected( $space->type, 'forum' ); ?>><?php esc_html_e( 'Forum: discussions and replies', 'jetonomy' ); ?></option>
					<option value="qa" <?php selected( $space->type, 'qa' ); ?>><?php esc_html_e( 'Q&A: questions with accepted answers', 'jetonomy' ); ?></option>
					<option value="ideas" <?php selected( $space->type, 'ideas' ); ?>><?php esc_html_e( 'Ideas: feedback voted by members', 'jetonomy' ); ?></option>
					<option value="feed" <?php selected( $space->type, 'feed' ); ?>><?php esc_html_e( 'Feed: short-form posts', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
				<select id="jt-se-visibility" name="visibility" class="jt-input">
					<option value="public" <?php selected( $space->visibility, 'public' ); ?>><?php esc_html_e( 'Public: anyone can read', 'jetonomy' ); ?></option>
					<option value="private" <?php selected( $space->visibility, 'private' ); ?>><?php esc_html_e( 'Private: members only', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-join-policy"><?php esc_html_e( 'Join policy', 'jetonomy' ); ?></label>
				<select id="jt-se-join-policy" name="join_policy" class="jt-input">
					<option value="open" <?php selected( $space->join_policy ?? 'open', 'open' ); ?>><?php esc_html_e( 'Open: anyone can join', 'jetonomy' ); ?></option>
					<option value="approval" <?php selected( $space->join_policy ?? '', 'approval' ); ?>><?php esc_html_e( 'Approval required', 'jetonomy' ); ?></option>
					<option value="invite" <?php selected( $space->join_policy ?? '', 'invite' ); ?>><?php esc_html_e( 'Invite only', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-category"><?php esc_html_e( 'Category', 'jetonomy' ); ?></label>
				<select id="jt-se-category" name="category_id" class="jt-input">
					<option value="0"><?php esc_html_e( 'No category', 'jetonomy' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->id ); ?>" <?php selected( (int) ( $space->category_id ?? 0 ), (int) $cat->id ); ?>>
							<?php echo esc_html( $cat->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="jt-form-help"><?php esc_html_e( 'Group this space under a top-level category on the community home.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-posts-per-page"><?php esc_html_e( 'Posts per page', 'jetonomy' ); ?></label>
				<input
					type="number"
					id="jt-se-posts-per-page"
					name="posts_per_page"
					min="1"
					max="100"
					class="jt-input jt-input-narrow"
					value="<?php echo esc_attr( (string) $posts_per_page ); ?>"
					placeholder="<?php esc_attr_e( 'Default', 'jetonomy' ); ?>">
				<p class="jt-form-help"><?php esc_html_e( 'How many topics to show per page in this space. Leave blank to use the site default.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row jt-prefixes-row">
				<label class="jt-prefix-toggle">
					<input type="checkbox" name="enable_prefixes" value="1" <?php checked( $prefixes_on ); ?> data-jt-prefix-toggle>
					<?php esc_html_e( 'Enable topic prefixes', 'jetonomy' ); ?>
				</label>
				<p class="jt-form-help"><?php esc_html_e( 'Colored labels members can pin to topics, e.g. Bug, Suggestion, Solved.', 'jetonomy' ); ?></p>

				<div class="jt-prefix-config" data-jt-prefix-config <?php echo $prefixes_on ? '' : 'hidden'; ?>>
					<div class="jt-prefix-list" data-jt-prefix-list>
						<?php
						if ( ! empty( $prefixes ) ) :
							foreach ( $prefixes as $pfx ) :
								?>
								<div class="jt-prefix-row">
									<input type="text" class="jt-input jt-prefix-name" value="<?php echo esc_attr( $pfx['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Label', 'jetonomy' ); ?>" maxlength="50">
									<input type="color" class="jt-prefix-color" value="<?php echo esc_attr( $pfx['color'] ?? '#3B82F6' ); ?>">
									<button type="button" class="jt-btn jt-btn-ghost jt-prefix-remove" aria-label="<?php esc_attr_e( 'Remove prefix', 'jetonomy' ); ?>">&times;</button>
								</div>
								<?php
							endforeach;
						endif;
						?>
					</div>
					<button type="button" class="jt-btn jt-btn-ghost" data-jt-prefix-add>
						<?php esc_html_e( '+ Add prefix', 'jetonomy' ); ?>
					</button>
				</div>
			</div>

			<div class="jt-form-actions">
				<button type="submit" class="jt-btn jt-btn-fill">
					<?php esc_html_e( 'Save changes', 'jetonomy' ); ?>
				</button>
				<a class="jt-btn jt-btn-ghost" href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
					<?php esc_html_e( 'Cancel', 'jetonomy' ); ?>
				</a>
				<span class="jt-form-saved" data-jt-saved hidden>
					<?php esc_html_e( 'Saved.', 'jetonomy' ); ?>
				</span>
			</div>
			<div class="jt-form-error" data-jt-error hidden></div>
		</form>

	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => $space ) ); ?>
</div>
