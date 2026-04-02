<?php
/**
 * Database schema definitions.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB;

defined( 'ABSPATH' ) || exit;

class Schema {

	/**
	 * Create all plugin tables using dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sqls = self::get_table_definitions( $p, $charset_collate );

		// FULLTEXT indexes are not supported on temporary InnoDB tables
		// (used by the WP test suite). Strip them when running tests.
		$is_temp = defined( 'WP_TESTS_TABLE_PREFIX' ) || ( defined( 'WP_TESTS_DOMAIN' ) );

		foreach ( $sqls as $sql ) {
			if ( $is_temp ) {
				$sql = preg_replace( '/,\s*FULLTEXT KEY[^\n]+/i', '', $sql );
			}
			dbDelta( $sql );
		}
	}

	/**
	 * Drop all plugin tables. Used during uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = array_reverse( self::get_table_names() );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
		}
	}

	/**
	 * Return list of all table name suffixes (without prefix).
	 *
	 * @return string[]
	 */
	public static function get_table_names(): array {
		return [
			'jt_categories',
			'jt_spaces',
			'jt_posts',
			'jt_replies',
			'jt_votes',
			'jt_user_profiles',
			'jt_notifications',
			'jt_subscriptions',
			'jt_read_status',
			'jt_space_members',
			'jt_tags',
			'jt_post_tags',
			'jt_space_tags',
			'jt_space_tag_map',
			'jt_user_interests',
			'jt_activity_log',
			'jt_restrictions',
			'jt_access_rules',
			'jt_flags',
			'jt_revisions',
			'jt_join_requests',
			'jt_invite_links',
			'jt_bookmarks',
		];
	}

	/**
	 * Build CREATE TABLE SQL strings for all 21 tables.
	 *
	 * @param string $p               Table prefix (e.g. "wp_").
	 * @param string $charset_collate Charset/collation string from $wpdb.
	 * @return string[]
	 */
	private static function get_table_definitions( string $p, string $charset_collate ): array {
		$sqls = [];

		// 1. jt_categories
		$sqls[] = "CREATE TABLE {$p}jt_categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  description longtext,
  icon varchar(255) DEFAULT NULL,
  color varchar(50) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  space_count int(11) NOT NULL DEFAULT 0,
  visibility ENUM('public','private','hidden') NOT NULL DEFAULT 'public',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY parent_sort (parent_id,sort_order)
) $charset_collate;";

		// 2. jt_spaces
		$sqls[] = "CREATE TABLE {$p}jt_spaces (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type ENUM('forum','qa','ideas','feed') NOT NULL DEFAULT 'forum',
  title varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  description longtext,
  icon varchar(255) DEFAULT NULL,
  cover_image varchar(255) DEFAULT NULL,
  visibility ENUM('public','private','hidden') NOT NULL DEFAULT 'public',
  join_policy ENUM('open','approval','invite') NOT NULL DEFAULT 'open',
  status ENUM('active','archived','locked') NOT NULL DEFAULT 'active',
  sort_order int(11) NOT NULL DEFAULT 0,
  settings longtext,
  post_count int(11) NOT NULL DEFAULT 0,
  member_count int(11) NOT NULL DEFAULT 0,
  last_activity_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY category_sort (category_id,sort_order),
  KEY parent_sort (parent_id,sort_order)
) $charset_collate;";

		// 3. jt_posts
		$sqls[] = "CREATE TABLE {$p}jt_posts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  space_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type ENUM('topic','question','idea','status') NOT NULL DEFAULT 'topic',
  prefix varchar(100) DEFAULT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) DEFAULT NULL,
  content longtext,
  content_plain longtext,
  status ENUM('publish','pending','draft','spam','trash') NOT NULL DEFAULT 'publish',
  published_at datetime DEFAULT NULL,
  is_sticky tinyint(1) NOT NULL DEFAULT 0,
  is_private tinyint(1) NOT NULL DEFAULT 0,
  is_closed tinyint(1) NOT NULL DEFAULT 0,
  is_resolved tinyint(1) NOT NULL DEFAULT 0,
  idea_status ENUM('submitted','under_review','planned','in_progress','completed','declined') DEFAULT NULL,
  vote_score int(11) NOT NULL DEFAULT 0,
  reply_count int(11) NOT NULL DEFAULT 0,
  view_count int(11) NOT NULL DEFAULT 0,
  last_reply_at datetime DEFAULT NULL,
  last_reply_by bigint(20) unsigned DEFAULT NULL,
  accepted_reply_id bigint(20) unsigned DEFAULT NULL,
  edited_at datetime DEFAULT NULL,
  edited_by bigint(20) unsigned DEFAULT NULL,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY space_sticky_reply (space_id,is_sticky,last_reply_at),
  KEY space_private_reply (space_id,is_private,last_reply_at),
  KEY space_votes (space_id,vote_score),
  KEY author_created (author_id,created_at),
  FULLTEXT KEY ft_title_content (title,content_plain)
) ENGINE=InnoDB $charset_collate;";

		// 4. jt_replies
		$sqls[] = "CREATE TABLE {$p}jt_replies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned DEFAULT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  content longtext,
  content_plain longtext,
  status ENUM('publish','pending','spam','trash') NOT NULL DEFAULT 'publish',
  vote_score int(11) NOT NULL DEFAULT 0,
  is_accepted tinyint(1) NOT NULL DEFAULT 0,
  edited_at datetime DEFAULT NULL,
  edited_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY post_created (post_id,created_at),
  KEY post_votes (post_id,vote_score),
  KEY author_created (author_id,created_at),
  FULLTEXT KEY ft_content (content_plain)
) ENGINE=InnoDB $charset_collate;";

		// 5. jt_votes
		$sqls[] = "CREATE TABLE {$p}jt_votes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type ENUM('post','reply') NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  value tinyint(4) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY user_object (user_id,object_type,object_id)
) $charset_collate;";

		// 6. jt_user_profiles
		$sqls[] = "CREATE TABLE {$p}jt_user_profiles (
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  display_name varchar(255) NOT NULL DEFAULT '',
  bio longtext,
  avatar_url varchar(255) DEFAULT NULL,
  trust_level tinyint(3) unsigned NOT NULL DEFAULT 0,
  reputation int(11) NOT NULL DEFAULT 0,
  post_count int(11) NOT NULL DEFAULT 0,
  reply_count int(11) NOT NULL DEFAULT 0,
  vote_received int(11) NOT NULL DEFAULT 0,
  badges longtext,
  settings longtext,
  last_seen_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (user_id),
  KEY trust_reputation (trust_level,reputation)
) $charset_collate;";

		// 7. jt_notifications
		$sqls[] = "CREATE TABLE {$p}jt_notifications (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(50) NOT NULL DEFAULT '',
  object_type ENUM('post','reply','space','badge') NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message varchar(500) NOT NULL DEFAULT '',
  is_read tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_read_created (user_id,is_read,created_at)
) $charset_collate;";

		// 8. jt_subscriptions
		$sqls[] = "CREATE TABLE {$p}jt_subscriptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type ENUM('space','post') NOT NULL DEFAULT 'space',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notify_via ENUM('web','email','both') NOT NULL DEFAULT 'web',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY user_object (user_id,object_type,object_id)
) $charset_collate;";

		// 9. jt_read_status
		$sqls[] = "CREATE TABLE {$p}jt_read_status (
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_read_reply_id bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (user_id,post_id)
) $charset_collate;";

		// 10. jt_space_members
		$sqls[] = "CREATE TABLE {$p}jt_space_members (
  space_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  role ENUM('viewer','member','moderator','admin') NOT NULL DEFAULT 'member',
  joined_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (space_id,user_id),
  KEY user_joined (user_id,joined_at)
) $charset_collate;";

		// 11. jt_tags
		$sqls[] = "CREATE TABLE {$p}jt_tags (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  post_count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset_collate;";

		// 12. jt_post_tags
		$sqls[] = "CREATE TABLE {$p}jt_post_tags (
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tag_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id,tag_id),
  KEY tag_post (tag_id,post_id)
) $charset_collate;";

		// 13. jt_space_tags
		$sqls[] = "CREATE TABLE {$p}jt_space_tags (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  space_count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset_collate;";

		// 14. jt_space_tag_map
		$sqls[] = "CREATE TABLE {$p}jt_space_tag_map (
  space_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tag_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (space_id,tag_id)
) $charset_collate;";

		// 15. jt_user_interests
		$sqls[] = "CREATE TABLE {$p}jt_user_interests (
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tag_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (user_id,tag_id)
) $charset_collate;";

		// 16. jt_activity_log
		$sqls[] = "CREATE TABLE {$p}jt_activity_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(50) NOT NULL DEFAULT '',
  object_type varchar(50) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  metadata longtext,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY created (created_at)
) $charset_collate;";

		// 17. jt_restrictions
		$sqls[] = "CREATE TABLE {$p}jt_restrictions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type ENUM('global_ban','space_ban','silence','post_restrict','ip_ban') NOT NULL DEFAULT 'silence',
  space_id bigint(20) unsigned DEFAULT NULL,
  reason text,
  issued_by bigint(20) unsigned NOT NULL DEFAULT 0,
  expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_type_space (user_id,type,space_id),
  KEY expires (expires_at)
) $charset_collate;";

		// 18. jt_access_rules
		$sqls[] = "CREATE TABLE {$p}jt_access_rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  space_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rule_type ENUM('membership','role','capability','trust_level','logged_in','everyone') NOT NULL DEFAULT 'everyone',
  rule_value varchar(255) DEFAULT NULL,
  grants ENUM('read','participate','full') NOT NULL DEFAULT 'read',
  space_role ENUM('viewer','member','moderator','admin') NOT NULL DEFAULT 'viewer',
  priority int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY space_priority (space_id,priority)
) $charset_collate;";

		// 19. jt_flags
		$sqls[] = "CREATE TABLE {$p}jt_flags (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reporter_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type ENUM('post','reply','user') NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason ENUM('spam','offensive','off_topic','harassment','other') NOT NULL DEFAULT 'other',
  description text,
  status ENUM('pending','valid','dismissed') NOT NULL DEFAULT 'pending',
  resolved_by bigint(20) unsigned DEFAULT NULL,
  resolved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY object (object_type,object_id),
  KEY reporter (reporter_id)
) $charset_collate;";

		// 20. jt_revisions
		$sqls[] = "CREATE TABLE {$p}jt_revisions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type ENUM('post','reply') NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  content longtext,
  title varchar(255) DEFAULT NULL,
  edit_summary varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY object_created (object_type,object_id,created_at)
) $charset_collate;";

		// 21. jt_join_requests
		$sqls[] = "CREATE TABLE {$p}jt_join_requests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  space_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text,
  status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY space_user_status (space_id,user_id,status),
  KEY space_status_created (space_id,status,created_at)
) $charset_collate;";

		// 22. jt_invite_links
		$sqls[] = "CREATE TABLE {$p}jt_invite_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  space_id bigint(20) unsigned NOT NULL,
  token varchar(64) NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  max_uses int DEFAULT 0,
  use_count int DEFAULT 0,
  expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY idx_space (space_id)
) $charset_collate;";

		// 23. jt_bookmarks
		$sqls[] = "CREATE TABLE {$p}jt_bookmarks (
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (user_id, post_id),
  KEY user_created (user_id, created_at)
) $charset_collate;";

		return $sqls;
	}
}
