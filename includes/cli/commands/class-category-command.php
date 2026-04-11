<?php
/**
 * wp jetonomy category — category CRUD via Taxonomy_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Taxonomy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy categories from the terminal.
 *
 * All subcommands delegate to {@see Taxonomy_Journey} for the actual work
 * so PHPUnit tests can assert on the same code path the CLI runs.
 */
final class Category_Command extends Base_Command {

	/**
	 * Create a new category.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Category display name.
	 *
	 * --slug=<slug>
	 * : Category slug (must be unique).
	 *
	 * [--description=<text>]
	 * : Optional description.
	 *
	 * [--parent=<id>]
	 * : Optional parent category ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category create --name="General" --slug=general
	 *     wp jetonomy category create --name="Sub" --slug=sub --parent=1
	 */
	public function create( $args, $assoc ): void {
		$input = [
			'name' => (string) ( $assoc['name'] ?? '' ),
			'slug' => (string) ( $assoc['slug'] ?? '' ),
		];
		if ( isset( $assoc['description'] ) ) {
			$input['description'] = (string) $assoc['description'];
		}
		if ( isset( $assoc['parent'] ) ) {
			$input['parent_id'] = (int) $assoc['parent'];
		}

		$result = ( new Taxonomy_Journey() )->create_category( $input );
		$this->render( $result, $assoc );
	}

	/**
	 * Update mutable fields on a category.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Category ID.
	 *
	 * [--name=<name>]
	 * : New display name.
	 *
	 * [--slug=<slug>]
	 * : New slug.
	 *
	 * [--description=<text>]
	 * : New description.
	 *
	 * [--parent=<id>]
	 * : New parent category ID.
	 *
	 * [--sort=<order>]
	 * : New sort order (integer).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category update 3 --name="Renamed"
	 *     wp jetonomy category update 3 --parent=1 --sort=10
	 */
	public function update( $args, $assoc ): void {
		$id      = (int) ( $args[0] ?? 0 );
		$changes = [];
		if ( isset( $assoc['name'] ) ) {
			$changes['name'] = (string) $assoc['name'];
		}
		if ( isset( $assoc['slug'] ) ) {
			$changes['slug'] = (string) $assoc['slug'];
		}
		if ( isset( $assoc['description'] ) ) {
			$changes['description'] = (string) $assoc['description'];
		}
		if ( isset( $assoc['parent'] ) ) {
			$changes['parent_id'] = (int) $assoc['parent'];
		}
		if ( isset( $assoc['sort'] ) ) {
			$changes['sort_order'] = (int) $assoc['sort'];
		}

		$result = ( new Taxonomy_Journey() )->update_category( $id, $changes );
		$this->render( $result, $assoc );
	}

	/**
	 * Delete a category by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Category ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category delete 3
	 */
	public function delete( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Taxonomy_Journey() )->delete_category( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Fetch a category by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Category ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category get 3
	 */
	public function get( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Taxonomy_Journey() )->get_category( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Fetch a category by slug.
	 *
	 * ## OPTIONS
	 *
	 * --slug=<slug>
	 * : Category slug to look up.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category get-by-slug --slug=general
	 *
	 * @subcommand get-by-slug
	 */
	public function get_by_slug( $args, $assoc ): void {
		$slug   = (string) ( $assoc['slug'] ?? '' );
		$result = ( new Taxonomy_Journey() )->get_category_by_slug( $slug );
		$this->render( $result, $assoc );
	}

	/**
	 * List all top-level categories.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column names to show.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category list
	 *     wp jetonomy category list --format=json
	 */
	public function list( $args, $assoc ): void {
		$result = ( new Taxonomy_Journey() )->list_top_level_categories();
		$this->render_list( $result, $assoc );
	}

	/**
	 * List child categories for a parent.
	 *
	 * ## OPTIONS
	 *
	 * --parent=<id>
	 * : Parent category ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column names to show.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy category children --parent=1
	 */
	public function children( $args, $assoc ): void {
		$parent_id = (int) ( $assoc['parent'] ?? 0 );
		$result    = ( new Taxonomy_Journey() )->list_category_children( $parent_id );
		$this->render_list( $result, $assoc );
	}
}
