<?php
/**
 * Pegasus Botify — front-end enqueue (build spec §5.4).
 *
 * Enqueues only the tiny launcher (stage 1); the widget chunk (stage 2) is pulled in by
 * the launcher's dynamic import(), never enqueued directly. Bootstrap config is printed as
 * a <script type="application/json"> tag the launcher reads.
 *
 * Loading policy:
 *   - `[pegasus_botify]` shortcode → enqueue on demand (this is what the theme's site-wide
 *     "Enable Botify" footer toggle drives).
 *   - `pegasus_botify_should_enqueue` filter (default false) → auto-enqueue everywhere for
 *     sites that prefer the plugin to own placement.
 *
 * @package pegasus-botify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pegasus_Botify_Enqueue {

	const HANDLE = 'pegasus-botify-launcher';

	/** @var bool Guards against enqueuing (and printing config) more than once. */
	private static $enqueued = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_auto_enqueue' ), 20 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'as_module' ), 10, 2 );
	}

	/**
	 * Register (but do not enqueue) the launcher. Cache-busted by build mtime.
	 */
	public static function register() {
		$path = PEGASUS_BOTIFY_PATH . 'dist/launcher.js';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : PEGASUS_BOTIFY_VERSION;
		wp_register_script( self::HANDLE, PEGASUS_BOTIFY_URL . 'dist/launcher.js', array(), $ver, true );
	}

	/**
	 * Optional site-wide auto-enqueue.
	 */
	public static function maybe_auto_enqueue() {
		/**
		 * Whether to load the launcher on this request without a shortcode.
		 *
		 * @param bool $should Default false.
		 */
		if ( apply_filters( 'pegasus_botify_should_enqueue', false ) ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue the launcher and arrange for the config tag to print. Safe to call multiple
	 * times and from within a shortcode rendered in the footer.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		// Nothing to load if the widget has not been built yet.
		if ( ! file_exists( PEGASUS_BOTIFY_PATH . 'dist/launcher.js' ) ) {
			return;
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register();
		}
		wp_enqueue_script( self::HANDLE );
		self::$enqueued = true;

		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'print_config' ), 9 );
	}

	/**
	 * Print the inline bootstrap config the launcher reads before its first paint.
	 */
	public static function print_config() {
		$config = self::bootstrap_config();
		// JSON_HEX_TAG neutralizes any "</script>" in user-entered values (business name,
		// greeting, …) so the data tag cannot be broken out of.
		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
		echo '<script type="application/json" id="pegasus-botify-config">' . $json . '</script>' . "\n";
	}

	/**
	 * The minimal data needed to render the bubble immediately (spec §5.4). The full render
	 * config is fetched by the widget from restUrl.
	 *
	 * @return array
	 */
	private static function bootstrap_config() {
		$get = function ( $key, $default = '' ) {
			if ( function_exists( 'cmb2_get_option' ) ) {
				return cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, $key, $default );
			}
			$opts = get_option( PEGASUS_BOTIFY_OPTION_KEY, array() );
			return is_array( $opts ) && isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
		};

		$bubble = trim( (string) $get( 'bubble_color', '' ) );

		return array(
			'restUrl'      => esc_url_raw( rest_url( Pegasus_Botify_REST::REST_NAMESPACE . '/config' ) ),
			'position'     => (string) $get( 'position', 'bottom-right' ),
			'bubbleColor'  => '' !== $bubble ? $bubble : null,
			'greeting'     => (string) $get( 'greeting', 'Hi! How can I help?' ),
			'offsetBottom'       => (int) $get( 'offset_bottom', 20 ),
			'offsetRight'        => (int) $get( 'offset_side', 20 ),
			'windowOffsetBottom' => (int) $get( 'window_offset_bottom', 20 ),
			'windowOffsetRight'  => (int) $get( 'window_offset_side', 20 ),
			'bubbleIcon'         => (string) $get( 'bubble_icon', 'fa-comment' ),
		);
	}

	/**
	 * Serve the launcher as an ES module so its relative dynamic import() resolves against
	 * import.meta.url (subdirectory-install safe, spec §3.4).
	 *
	 * @param string $tag
	 * @param string $handle
	 * @return string
	 */
	public static function as_module( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$tag = preg_replace( "/ type=(['\"]).*?\\1/", '', $tag );
		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
