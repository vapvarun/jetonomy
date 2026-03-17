<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

class Api {

	private array $controllers = [];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$dir = JETONOMY_DIR . 'includes/api/';

		$classes = [
			'categories'    => 'Categories_Controller',
			'spaces'        => 'Spaces_Controller',
			'posts'         => 'Posts_Controller',
			'replies'       => 'Replies_Controller',
			'votes'         => 'Votes_Controller',
			'users'         => 'Users_Controller',
			'notifications' => 'Notifications_Controller',
			'subscriptions' => 'Subscriptions_Controller',
			'search'        => 'Search_Controller',
			'moderation'    => 'Moderation_Controller',
			'tags'          => 'Tags_Controller',
			'updates'       => 'Updates_Controller',
		];

		require_once $dir . 'class-base-controller.php';

		foreach ( $classes as $key => $class ) {
			$file = $dir . 'class-' . str_replace( '_', '-', strtolower( $key ) ) . '-controller.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				$fqn = __NAMESPACE__ . '\\' . $class;
				$controller = new $fqn();
				$controller->register_routes();
				$this->controllers[ $key ] = $controller;
			}
		}
	}
}
