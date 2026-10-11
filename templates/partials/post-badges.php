<?php
/**
 * Topic status badges: Pinned, Private, Closed, plus whatever extensions add.
 *
 * Shared by the topic row (post-card) and the feed card, so every listing
 * shows the same states the topic page does.
 *
 * @package Jetonomy
 *
 * @var object      $post  Post row.
 * @var object|null $space Space row, if loaded.
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( ! empty( $post->is_sticky ) ) : ?>
	<span class="jt-badge-pinned"><?php jetonomy_echo_icon( 'pin', 12 ); ?> <?php esc_html_e( 'Pinned', 'jetonomy' ); ?></span>
<?php endif; ?>
<?php if ( ! empty( $post->is_private ) ) : ?>
	<span class="jt-badge-private"><?php jetonomy_echo_icon( 'lock', 12 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?></span>
<?php endif; ?>
<?php if ( ! empty( $post->is_closed ) ) : ?>
	<span class="jt-badge-closed"><?php esc_html_e( 'Closed', 'jetonomy' ); ?></span>
<?php endif; ?>
<?php
/**
 * Fires after the built-in topic badges so extensions can add their own
 * markers (pinned site-wide, from announcements, and so on).
 *
 * @since 1.0.0
 *
 * @param object      $post  Post row.
 * @param object|null $space Space row, if loaded.
 */
do_action( 'jetonomy_post_card_after_badges', $post, $space ?? null );
