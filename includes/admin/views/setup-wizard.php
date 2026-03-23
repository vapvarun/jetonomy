<?php
defined( 'ABSPATH' ) || exit;

$settings   = get_option( 'jetonomy_settings', [] );
$base_slug  = $settings['base_slug'] ?? 'community';
$site_url   = trailingslashit( home_url() );
$nonce      = wp_create_nonce( 'jetonomy_setup' );
$ajax_url   = admin_url( 'admin-ajax.php' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e( 'Jetonomy Setup', 'jetonomy' ); ?></title>
<?php wp_head(); ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body.jt-setup-body {
	background: #f0f4ff;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	color: #1e293b;
	min-height: 100vh;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: flex-start;
	padding: 40px 16px 80px;
}

.jt-setup-wrap {
	width: 100%;
	max-width: 640px;
}

/* Header */
.jt-setup-header {
	text-align: center;
	margin-bottom: 40px;
}

.jt-setup-logo {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 56px;
	height: 56px;
	background: #3B82F6;
	border-radius: 14px;
	margin-bottom: 16px;
	box-shadow: 0 4px 12px rgba(59,130,246,.35);
}

.jt-setup-logo svg {
	width: 32px;
	height: 32px;
	fill: #fff;
}

.jt-setup-title {
	font-size: 28px;
	font-weight: 700;
	color: #0f172a;
	letter-spacing: -.5px;
}

.jt-setup-subtitle {
	font-size: 15px;
	color: #64748b;
	margin-top: 6px;
}

/* Progress */
.jt-setup-progress {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0;
	margin-bottom: 32px;
}

.jt-setup-step-dot {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
}

.jt-setup-step-dot__circle {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: #e2e8f0;
	color: #94a3b8;
	font-size: 13px;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background .2s, color .2s;
}

.jt-setup-step-dot--active .jt-setup-step-dot__circle {
	background: #3B82F6;
	color: #fff;
	box-shadow: 0 0 0 4px rgba(59,130,246,.2);
}

.jt-setup-step-dot--done .jt-setup-step-dot__circle {
	background: #22c55e;
	color: #fff;
}

.jt-setup-step-dot__label {
	font-size: 11px;
	color: #94a3b8;
	font-weight: 500;
	white-space: nowrap;
}

.jt-setup-step-dot--active .jt-setup-step-dot__label,
.jt-setup-step-dot--done .jt-setup-step-dot__label {
	color: #334155;
}

.jt-setup-step-connector {
	height: 2px;
	width: 80px;
	background: #e2e8f0;
	margin-bottom: 18px;
	transition: background .3s;
}

.jt-setup-step-connector--done {
	background: #22c55e;
}

/* Card */
.jt-setup-card {
	background: #fff;
	border-radius: 16px;
	box-shadow: 0 4px 24px rgba(15,23,42,.08);
	padding: 40px;
}

.jt-setup-card h2 {
	font-size: 20px;
	font-weight: 700;
	color: #0f172a;
	margin-bottom: 6px;
}

.jt-setup-card .jt-setup-card__desc {
	font-size: 14px;
	color: #64748b;
	margin-bottom: 28px;
	line-height: 1.6;
}

/* Form fields */
.jt-form-group {
	margin-bottom: 20px;
}

.jt-form-group label {
	display: block;
	font-size: 13px;
	font-weight: 600;
	color: #374151;
	margin-bottom: 6px;
}

.jt-form-group input[type="text"],
.jt-form-group textarea {
	width: 100%;
	padding: 10px 14px;
	border: 1.5px solid #e2e8f0;
	border-radius: 8px;
	font-size: 14px;
	color: #0f172a;
	background: #f8fafc;
	transition: border-color .2s, box-shadow .2s;
	outline: none;
}

.jt-form-group input[type="text"]:focus,
.jt-form-group textarea:focus {
	border-color: #3B82F6;
	box-shadow: 0 0 0 3px rgba(59,130,246,.15);
	background: #fff;
}

.jt-form-group textarea {
	resize: vertical;
	min-height: 80px;
}

.jt-form-group .jt-form-hint {
	font-size: 12px;
	color: #94a3b8;
	margin-top: 5px;
}

.jt-url-preview {
	display: flex;
	align-items: center;
	gap: 6px;
	background: #f1f5f9;
	border: 1.5px solid #e2e8f0;
	border-radius: 8px;
	padding: 9px 14px;
	font-size: 13px;
	color: #475569;
	margin-top: 8px;
}

.jt-url-preview__icon {
	color: #22c55e;
	flex-shrink: 0;
}

.jt-url-preview__url {
	font-family: ui-monospace, monospace;
	font-size: 12px;
}

.jt-url-preview__slug {
	font-weight: 700;
	color: #0f172a;
}

/* Radio group */
.jt-radio-group {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.jt-radio-option {
	position: relative;
}

.jt-radio-option input[type="radio"] {
	position: absolute;
	opacity: 0;
	width: 0;
	height: 0;
}

.jt-radio-option label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 14px 16px;
	border: 2px solid #e2e8f0;
	border-radius: 10px;
	cursor: pointer;
	transition: border-color .2s, background .2s;
	font-weight: 400;
}

.jt-radio-option input[type="radio"]:checked + label {
	border-color: #3B82F6;
	background: #eff6ff;
}

.jt-radio-option label strong {
	font-size: 14px;
	font-weight: 600;
	color: #0f172a;
}

.jt-radio-option label span {
	font-size: 12px;
	color: #64748b;
}

/* Divider */
.jt-setup-divider {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 24px 0;
	color: #94a3b8;
	font-size: 13px;
}

.jt-setup-divider::before,
.jt-setup-divider::after {
	content: '';
	flex: 1;
	height: 1px;
	background: #e2e8f0;
}

/* Buttons */
.jt-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 11px 24px;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: all .2s;
	border: none;
	text-decoration: none;
}

.jt-btn--primary {
	background: #3B82F6;
	color: #fff;
	box-shadow: 0 2px 8px rgba(59,130,246,.3);
}

.jt-btn--primary:hover {
	background: #2563eb;
	box-shadow: 0 4px 12px rgba(59,130,246,.4);
	color: #fff;
	text-decoration: none;
}

.jt-btn--secondary {
	background: #f1f5f9;
	color: #475569;
	border: 1.5px solid #e2e8f0;
}

.jt-btn--secondary:hover {
	background: #e2e8f0;
	color: #334155;
}

.jt-btn--outline {
	background: transparent;
	color: #3B82F6;
	border: 2px solid #3B82F6;
}

.jt-btn--outline:hover {
	background: #eff6ff;
}

.jt-btn--ghost {
	background: transparent;
	color: #64748b;
	border: 1.5px solid #e2e8f0;
	width: 100%;
	padding: 13px 20px;
	font-size: 14px;
}

.jt-btn--ghost:hover {
	background: #f8fafc;
	border-color: #cbd5e1;
	color: #334155;
}

.jt-btn--loading {
	opacity: .7;
	pointer-events: none;
}

.jt-btn-row {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 28px;
}

/* Success step */
#jt-step-3.jt-setup-card {
	text-align: center;
}

.jt-success-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 72px;
	height: 72px;
	background: #dcfce7;
	border-radius: 50%;
	margin: 0 auto 20px;
}

.jt-success-icon svg {
	width: 40px;
	height: 40px;
	color: #16a34a;
}

.jt-success-heading {
	font-size: 24px;
	font-weight: 700;
	text-align: center;
	color: #0f172a;
	margin-bottom: 8px;
}

.jt-success-sub {
	text-align: center;
	color: #64748b;
	font-size: 15px;
	margin-bottom: 32px;
}

.jt-success-cta {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
	margin-bottom: 28px;
}

.jt-success-tips {
	background: #f8fafc;
	border: 1.5px solid #e2e8f0;
	border-radius: 10px;
	padding: 16px 20px;
}

.jt-success-tips h4 {
	font-size: 13px;
	font-weight: 600;
	color: #475569;
	margin-bottom: 10px;
	text-transform: uppercase;
	letter-spacing: .5px;
}

.jt-success-tips ul {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.jt-success-tips li {
	font-size: 13px;
	color: #475569;
	display: flex;
	align-items: center;
	gap: 8px;
}

.jt-success-tips li::before {
	content: '\2192';
	color: #3B82F6;
	font-weight: 700;
	flex-shrink: 0;
}

/* Error notice */
.jt-setup-error {
	background: #fef2f2;
	border: 1px solid #fecaca;
	border-radius: 8px;
	padding: 12px 16px;
	font-size: 13px;
	color: #dc2626;
	margin-bottom: 16px;
	display: none;
}

/* Spinner */
@keyframes jt-spin { to { transform: rotate(360deg); } }
.jt-spinner {
	width: 16px;
	height: 16px;
	border: 2px solid rgba(255,255,255,.4);
	border-top-color: #fff;
	border-radius: 50%;
	animation: jt-spin .6s linear infinite;
	display: none;
}

.jt-btn--loading .jt-spinner { display: block; }

/* Step visibility */
.jt-step { display: none; }
.jt-step--active { display: block; }
</style>
</head>
<body class="jt-setup-body">

<div class="jt-setup-wrap">

	<!-- Header -->
	<div class="jt-setup-header">
		<div class="jt-setup-logo">
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
				<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
			</svg>
		</div>
		<h1 class="jt-setup-title"><?php esc_html_e( 'Jetonomy Setup', 'jetonomy' ); ?></h1>
		<p class="jt-setup-subtitle"><?php esc_html_e( "Let's get your community ready in a few simple steps.", 'jetonomy' ); ?></p>
	</div>

	<!-- Progress indicator -->
	<div class="jt-setup-progress" id="jt-progress">
		<div class="jt-setup-step-dot jt-setup-step-dot--active" data-step="1">
			<div class="jt-setup-step-dot__circle">1</div>
			<div class="jt-setup-step-dot__label"><?php esc_html_e( 'Basics', 'jetonomy' ); ?></div>
		</div>
		<div class="jt-setup-step-connector" id="jt-conn-1"></div>
		<div class="jt-setup-step-dot" data-step="2">
			<div class="jt-setup-step-dot__circle">2</div>
			<div class="jt-setup-step-dot__label"><?php esc_html_e( 'First Space', 'jetonomy' ); ?></div>
		</div>
		<div class="jt-setup-step-connector" id="jt-conn-2"></div>
		<div class="jt-setup-step-dot" data-step="3">
			<div class="jt-setup-step-dot__circle">3</div>
			<div class="jt-setup-step-dot__label"><?php esc_html_e( 'Done!', 'jetonomy' ); ?></div>
		</div>
	</div>

	<!-- Step 1: Welcome + Basics -->
	<div class="jt-setup-card jt-step jt-step--active" id="jt-step-1">
		<h2><?php esc_html_e( 'Welcome to Jetonomy', 'jetonomy' ); ?></h2>
		<p class="jt-setup-card__desc"><?php esc_html_e( 'Configure the basics for your community. You can always change these later in Settings.', 'jetonomy' ); ?></p>

		<div class="jt-setup-error" id="jt-error-1"></div>

		<div class="jt-form-group">
			<label for="jt-base-slug"><?php esc_html_e( 'Community URL Slug', 'jetonomy' ); ?></label>
			<input type="text" id="jt-base-slug" value="<?php echo esc_attr( $base_slug ); ?>" placeholder="community">
			<div class="jt-url-preview" id="jt-url-preview">
				<span class="jt-url-preview__icon">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
				</span>
				<span class="jt-url-preview__url">
					<span id="jt-url-base"><?php echo esc_html( $site_url ); ?></span><span class="jt-url-preview__slug" id="jt-url-slug"><?php echo esc_html( $base_slug ); ?></span><span>/</span>
				</span>
			</div>
			<p class="jt-form-hint"><?php esc_html_e( 'All community pages will be nested under this URL.', 'jetonomy' ); ?></p>
		</div>

		<div class="jt-form-group">
			<label><?php esc_html_e( 'Default Community Type', 'jetonomy' ); ?></label>
			<div class="jt-radio-group">
				<div class="jt-radio-option">
					<input type="radio" name="jt-default-type" id="jt-type-forum" value="forum" checked>
					<label for="jt-type-forum">
						<strong><?php esc_html_e( 'Forum', 'jetonomy' ); ?></strong>
						<span><?php esc_html_e( 'Threaded discussions, replies, topics', 'jetonomy' ); ?></span>
					</label>
				</div>
				<div class="jt-radio-option">
					<input type="radio" name="jt-default-type" id="jt-type-qa" value="qa">
					<label for="jt-type-qa">
						<strong><?php esc_html_e( 'Q&amp;A', 'jetonomy' ); ?></strong>
						<span><?php esc_html_e( 'Questions, answers, accepted solution', 'jetonomy' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<div class="jt-btn-row">
			<button type="button" class="jt-btn jt-btn--primary" id="jt-next-1">
				<?php esc_html_e( 'Next: First Space', 'jetonomy' ); ?>
				<span class="jt-spinner"></span>
			</button>
		</div>
	</div>

	<!-- Step 2: Create First Space -->
	<div class="jt-setup-card jt-step" id="jt-step-2">
		<h2><?php esc_html_e( 'Create Your First Space', 'jetonomy' ); ?></h2>
		<p class="jt-setup-card__desc"><?php esc_html_e( 'A Space is a community section where people can start discussions. You can add more spaces later.', 'jetonomy' ); ?></p>

		<div class="jt-setup-error" id="jt-error-2"></div>

		<div class="jt-form-group">
			<label for="jt-cat-name"><?php esc_html_e( 'Category Name', 'jetonomy' ); ?></label>
			<input type="text" id="jt-cat-name" value="General" placeholder="General">
			<p class="jt-form-hint"><?php esc_html_e( 'Categories group related spaces together.', 'jetonomy' ); ?></p>
		</div>

		<div class="jt-form-group">
			<label for="jt-space-name"><?php esc_html_e( 'Space Name', 'jetonomy' ); ?></label>
			<input type="text" id="jt-space-name" value="Community Discussion" placeholder="Community Discussion">
		</div>

		<div class="jt-form-group">
			<label for="jt-space-desc"><?php esc_html_e( 'Space Description', 'jetonomy' ); ?></label>
			<textarea id="jt-space-desc" placeholder="<?php esc_attr_e( 'A place for community discussions...', 'jetonomy' ); ?>"></textarea>
		</div>

		<div class="jt-setup-divider"><?php esc_html_e( 'or', 'jetonomy' ); ?></div>

		<button type="button" class="jt-btn jt-btn--ghost" id="jt-create-sample">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
			<?php esc_html_e( 'Create sample data instead (2 categories, 4 spaces, 10 posts)', 'jetonomy' ); ?>
			<span class="jt-spinner" style="border-color:rgba(100,116,139,.4);border-top-color:#64748b;"></span>
		</button>

		<div class="jt-btn-row">
			<button type="button" class="jt-btn jt-btn--secondary" id="jt-back-2"><?php esc_html_e( 'Back', 'jetonomy' ); ?></button>
			<button type="button" class="jt-btn jt-btn--primary" id="jt-next-2">
				<?php esc_html_e( 'Create Space', 'jetonomy' ); ?>
				<span class="jt-spinner"></span>
			</button>
		</div>
	</div>

	<!-- Step 3: Done! -->
	<div class="jt-setup-card jt-step" id="jt-step-3">
		<div class="jt-success-icon">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
				<path d="M20 6L9 17l-5-5"/>
			</svg>
		</div>
		<h2 class="jt-success-heading"><?php esc_html_e( 'Your community is ready!', 'jetonomy' ); ?></h2>
		<p class="jt-success-sub"><?php esc_html_e( 'Everything is set up. Start exploring your new Jetonomy community.', 'jetonomy' ); ?></p>

		<div class="jt-success-cta">
			<a href="<?php echo esc_url( home_url( '/' . $base_slug . '/' ) ); ?>" class="jt-btn jt-btn--primary" id="jt-visit-community" target="_blank">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
				<?php esc_html_e( 'Visit Community', 'jetonomy' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy' ) ); ?>" class="jt-btn jt-btn--outline">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
				<?php esc_html_e( 'Go to Dashboard', 'jetonomy' ); ?>
			</a>
		</div>

		<div class="jt-success-tips">
			<h4><?php esc_html_e( 'Next Steps', 'jetonomy' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Customize appearance in Settings (colors, fonts, layout)', 'jetonomy' ); ?></li>
				<li><?php esc_html_e( 'Create more spaces for different topics', 'jetonomy' ); ?></li>
				<li><?php esc_html_e( 'Invite members to start discussions', 'jetonomy' ); ?></li>
				<li><?php esc_html_e( 'Import existing content from bbPress or wpForo', 'jetonomy' ); ?></li>
			</ul>
		</div>
	</div>

</div><!-- .jt-setup-wrap -->

<script>
(function () {
	'use strict';

	var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
	var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
	var siteUrl  = <?php echo wp_json_encode( trailingslashit( home_url() ) ); ?>;

	var communityUrl = siteUrl + 'community/';

	// Cache elements.
	var steps     = document.querySelectorAll( '.jt-step' );
	var stepDots  = document.querySelectorAll( '.jt-setup-step-dot' );
	var conn1     = document.getElementById( 'jt-conn-1' );
	var conn2     = document.getElementById( 'jt-conn-2' );

	function showStep( n ) {
		steps.forEach( function ( el, i ) {
			el.classList.toggle( 'jt-step--active', i + 1 === n );
		} );
		stepDots.forEach( function ( dot, i ) {
			var num = i + 1;
			dot.classList.toggle( 'jt-setup-step-dot--active', num === n );
			dot.classList.toggle( 'jt-setup-step-dot--done', num < n );
		} );
		if ( conn1 ) conn1.classList.toggle( 'jt-setup-step-connector--done', n > 1 );
		if ( conn2 ) conn2.classList.toggle( 'jt-setup-step-connector--done', n > 2 );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function showError( id, msg ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = msg; el.style.display = 'block'; }
	}

	function hideError( id ) {
		var el = document.getElementById( id );
		if ( el ) { el.style.display = 'none'; }
	}

	function setLoading( btn, loading ) {
		btn.classList.toggle( 'jt-btn--loading', loading );
		btn.disabled = loading;
	}

	function sanitizeSlug( raw ) {
		return raw.replace( /[^a-z0-9-]/gi, '-' ).replace( /-+/g, '-' ).replace( /^-|-$/g, '' ).toLowerCase() || 'community';
	}

	// URL preview on slug change.
	var slugInput  = document.getElementById( 'jt-base-slug' );
	var urlSlugEl  = document.getElementById( 'jt-url-slug' );
	var visitBtn   = document.getElementById( 'jt-visit-community' );

	if ( slugInput ) {
		slugInput.addEventListener( 'input', function () {
			var slug = sanitizeSlug( this.value );
			communityUrl = siteUrl + slug + '/';
			if ( urlSlugEl ) {
				urlSlugEl.textContent = slug;
			}
		} );
	}

	// Step 1: Next button.
	var next1 = document.getElementById( 'jt-next-1' );
	if ( next1 ) {
		next1.addEventListener( 'click', function () {
			hideError( 'jt-error-1' );
			var slug = slugInput ? sanitizeSlug( slugInput.value ) : 'community';
			if ( ! slug ) {
				showError( 'jt-error-1', 'Please enter a community URL slug.' );
				return;
			}
			showStep( 2 );
		} );
	}

	// Step 2: Back button.
	var back2 = document.getElementById( 'jt-back-2' );
	if ( back2 ) {
		back2.addEventListener( 'click', function () { showStep( 1 ); } );
	}

	function doAjax( action, data, btn, errorId, onSuccess ) {
		setLoading( btn, true );
		hideError( errorId );

		var params = new URLSearchParams();
		params.append( 'action', action );
		params.append( 'nonce', nonce );
		Object.keys( data ).forEach( function ( k ) { params.append( k, data[ k ] ); } );

		fetch( ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( res ) {
			setLoading( btn, false );
			if ( res.success ) {
				onSuccess( res.data );
			} else {
				var msg = ( res.data && typeof res.data === 'object' && res.data.message )
					? String( res.data.message )
					: 'Something went wrong. Please try again.';
				showError( errorId, msg );
			}
		} )
		.catch( function () {
			setLoading( btn, false );
			showError( errorId, 'Network error. Please try again.' );
		} );
	}

	// Step 2: Create space.
	var next2 = document.getElementById( 'jt-next-2' );
	if ( next2 ) {
		next2.addEventListener( 'click', function () {
			var slug      = slugInput ? sanitizeSlug( slugInput.value ) : 'community';
			var typeEl    = document.querySelector( 'input[name="jt-default-type"]:checked' );
			var type      = typeEl ? typeEl.value : 'forum';
			var catNameEl = document.getElementById( 'jt-cat-name' );
			var spaceNameEl = document.getElementById( 'jt-space-name' );
			var spaceDescEl = document.getElementById( 'jt-space-desc' );
			var catName   = catNameEl ? catNameEl.value : 'General';
			var spaceName = spaceNameEl ? spaceNameEl.value : 'Community Discussion';
			var spaceDesc = spaceDescEl ? spaceDescEl.value : '';

			if ( ! catName.trim() || ! spaceName.trim() ) {
				showError( 'jt-error-2', 'Please fill in the category and space name.' );
				return;
			}

			doAjax( 'jetonomy_setup_save', {
				base_slug: slug,
				default_type: type,
				category_name: catName,
				space_name: spaceName,
				space_description: spaceDesc,
			}, next2, 'jt-error-2', function () {
				communityUrl = siteUrl + slug + '/';
				if ( visitBtn ) { visitBtn.href = communityUrl; }
				showStep( 3 );
			} );
		} );
	}

	// Create sample data.
	var sampleBtn = document.getElementById( 'jt-create-sample' );
	if ( sampleBtn ) {
		sampleBtn.addEventListener( 'click', function () {
			var slug = slugInput ? sanitizeSlug( slugInput.value ) : 'community';
			var typeEl = document.querySelector( 'input[name="jt-default-type"]:checked' );
			var type = typeEl ? typeEl.value : 'forum';

			doAjax( 'jetonomy_setup_create_sample', {
				base_slug: slug,
				default_type: type,
			}, sampleBtn, 'jt-error-2', function () {
				communityUrl = siteUrl + slug + '/';
				if ( visitBtn ) { visitBtn.href = communityUrl; }
				showStep( 3 );
			} );
		} );
	}

})();
</script>
<?php wp_footer(); ?>
</body>
</html>
