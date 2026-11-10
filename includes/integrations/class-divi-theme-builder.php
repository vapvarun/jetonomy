<?php
/**
 * Divi Theme Builder templates on community pages.
 *
 * Divi applies a Theme Builder template (global header, footer, body) only
 * when ET_Theme_Builder_Request::from_current() recognises the request as a
 * page, post, archive, 404 or search. Router::correct_query_state() clears
 * all of those flags on purpose, so every community route came back null and
 * Divi skipped the templates. Its only filter, et_theme_builder_template_layouts,
 * runs after that check, so it cannot help.
 *
 * Fix: while Divi itself decides (its body_class filter, its template_include
 * override, and the first line of its header and footer templates), the main
 * query reads as singular. Divi then falls back to the Default Website
 * Template. The window closes before wp_head and wp_footer, so SEO plugins,
 * the theme and every other reader still see the cleared state.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

class Divi_Theme_Builder {

	public function __construct() {
		add_filter( 'body_class', array( $this, 'open_filter' ), 8 );
		add_filter( 'body_class', array( $this, 'close_filter' ), 10 );
		add_filter( 'template_include', array( $this, 'open_filter' ), 97 );
		add_filter( 'template_include', array( $this, 'close_filter' ), 99 );
		add_action( 'get_header', array( $this, 'arm' ), 9 );
		add_action( 'get_footer', array( $this, 'arm' ), 9 );
		add_action( 'wp_after_load_template', array( $this, 'open_after_theme_partial' ) );
		add_action( 'et_theme_builder_template_head', array( $this, 'close' ), 0 );
		add_action( 'wp_footer', array( $this, 'close' ), PHP_INT_MIN );
		add_action( 'wp', array( $this, 'setup_module_styles' ), 9 );
		add_filter( 'et_core_page_resource_current_post_id', array( $this, 'no_post_id' ) );
		// While a layout renders, Divi picks where its styles go. Read as singular,
		// it uses the page's stylesheet, which prints in the footer; otherwise a
		// per-layout one whose turn to print came before the header rendered.
		add_action( 'et_theme_builder_after_layout_opening_wrappers', array( $this, 'open_layout' ) );
		add_action( 'et_theme_builder_before_layout_closing_wrappers', array( $this, 'close_layout' ) );
	}

	/**
	 * Queried objects to restore after each layout render (a stack, like $saved).
	 *
	 * @var array<int, object|null>
	 */
	private array $saved_object = array();

	/**
	 * Open the window for a layout render. Code inside the layout that trusts
	 * is_singular() reads the queried object: a Menu module with no items falls
	 * back to wp_list_pages(), which reads get_queried_object()->post_type and
	 * warned on null. An empty, non-hierarchical post answers it harmlessly.
	 */
	public function open_layout(): void {
		$depth = count( $this->saved );
		$this->open();
		if ( count( $this->saved ) === $depth ) {
			return; // Not a community route: nothing was opened.
		}
		global $wp_query;
		$this->saved_object[] = $wp_query->queried_object;
		if ( null === $wp_query->queried_object ) {
			$wp_query->queried_object = new \WP_Post(
				(object) array(
					'ID'        => 0,
					'post_type' => '',
				)
			);
		}
	}

	/**
	 * Close the layout window and put the queried object back.
	 */
	public function close_layout(): void {
		if ( $this->saved_object ) {
			$GLOBALS['wp_query']->queried_object = array_pop( $this->saved_object );
			$this->close();
		}
	}

	/**
	 * Community routes have no post. Without this, Divi takes the first layout
	 * it renders as the page's post and caches that for the whole request.
	 *
	 * @param mixed $post_id Post ID Divi found.
	 * @return mixed
	 */
	public function no_post_id( $post_id ) {
		return '' !== (string) get_query_var( 'jetonomy_route' ) ? 0 : $post_id;
	}

	/**
	 * Divi names and sets up the module stylesheet on `wp`, from the template
	 * it sees then. Outside a window it sees none, reuses a cached generic
	 * stylesheet, and the header layout's own styles are never printed. Run
	 * that one setup inside a window; Divi's own call later is a no-op.
	 */
	public function setup_module_styles(): void {
		$setup = array( 'ET\\Builder\\FrontEnd\\Assets\\StaticCSS', 'setup' );
		if ( ! is_callable( $setup ) || '' === (string) get_query_var( 'jetonomy_route' ) ) {
			return;
		}
		$this->open();
		$setup();
		$this->close();
	}

	/**
	 * Which partial Divi is about to swap ('header' or 'footer'), or ''.
	 *
	 * @var string
	 */
	private string $armed = '';

	/**
	 * Note that Divi will swap this partial for its Theme Builder one.
	 */
	public function arm(): void {
		$partial     = 'get_header' === current_filter() ? 'header' : 'footer';
		$this->armed = has_action( current_filter(), "et_theme_builder_frontend_override_{$partial}" ) ? $partial : '';
	}

	/**
	 * Divi first loads the theme's own header.php (output discarded), then its
	 * Theme Builder template, whose first line looks the layouts up. Open only
	 * between the two: the theme file calls body_class(), which must not see
	 * a singular request with no post behind it.
	 *
	 * @param string $file Template file just loaded.
	 */
	public function open_after_theme_partial( $file ): void {
		if ( '' !== $this->armed && preg_match( '#(^|/)' . $this->armed . '(-[^/]*)?\.php$#', (string) $file ) ) {
			$this->armed = '';
			$this->open();
		}
	}

	/**
	 * Earlier is_singular values, so windows can nest: the theme header Divi
	 * buffers inside the get_header window calls body_class(), which opens and
	 * closes its own window.
	 *
	 * @var bool[]
	 */
	private array $saved = array();

	/**
	 * Let Divi read a community route as singular.
	 */
	public function open(): void {
		if ( function_exists( 'et_theme_builder_get_template_layouts' ) && '' !== (string) get_query_var( 'jetonomy_route' ) ) {
			$this->saved[]                    = (bool) $GLOBALS['wp_query']->is_singular;
			$GLOBALS['wp_query']->is_singular = true;
		}
	}

	/**
	 * Put back the state the matching open() found.
	 */
	public function close(): void {
		if ( $this->saved ) {
			$GLOBALS['wp_query']->is_singular = array_pop( $this->saved );
		}
	}

	/**
	 * open() for a filter; the value passes through.
	 *
	 * @param mixed $value Filter value.
	 * @return mixed
	 */
	public function open_filter( $value ) {
		$this->open();
		return $value;
	}

	/**
	 * close() for a filter; the value passes through.
	 *
	 * @param mixed $value Filter value.
	 * @return mixed
	 */
	public function close_filter( $value ) {
		$this->close();
		return $value;
	}
}
