<?php
/**
 * Pegasus Botify — REST config endpoint (build spec §5.3).
 *
 *   GET /wp-json/pegasus-botify/v1/config
 *
 * Public and lightly cacheable. Returns everything the widget needs to render *before*
 * any conversation starts. It MUST NOT expose the site key (server-to-server only) or the
 * internal notification/escalation emails — only public-safe, render-time data.
 *
 * @package pegasus-botify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pegasus_Botify_REST {

	/** REST namespace + version. */
	const REST_NAMESPACE = 'pegasus-botify/v1';

	/** Config payload schema version (bumped independently of the plugin version). */
	const CONFIG_VERSION = 1;

	/**
	 * Hook route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE, // GET
				'callback'            => array( __CLASS__, 'get_config' ),
				'permission_callback' => '__return_true', // Public endpoint.
			)
		);
	}

	/**
	 * Read a single saved plugin option value.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	private static function opt( $key, $default = '' ) {
		if ( function_exists( 'cmb2_get_option' ) ) {
			return cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, $key, $default );
		}
		$opts = get_option( PEGASUS_BOTIFY_OPTION_KEY, array() );
		return is_array( $opts ) && isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	}

	/**
	 * Build and return the config payload.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_config( WP_REST_Request $request ) {
		$data = array(
			'version'          => self::CONFIG_VERSION,
			'greeting'         => (string) self::opt( 'greeting', 'Hi! How can I help?' ),
			'offsetBottom'       => (int) self::opt( 'offset_bottom', 20 ),
			'offsetRight'        => (int) self::opt( 'offset_side', 20 ),
			'windowOffsetBottom' => (int) self::opt( 'window_offset_bottom', 20 ),
			'windowOffsetRight'  => (int) self::opt( 'window_offset_side', 20 ),
			'bubbleIcon'       => (string) self::opt( 'bubble_icon', 'fa-comment' ),
			'quickReplies'     => self::quick_replies(),
			'appearance'       => self::appearance(),
			'afterHoursAction' => (string) self::opt( 'after_hours_action', 'capture_lead' ),
			'business'         => self::business(),
			'hours'            => self::hours_state(),
			// Bring-your-own-key AI: the widget POSTs here (same-origin) and the plugin calls
			// the model server-side. aiEnabled is false unless a key is set and AI is on.
			'aiEnabled'        => function_exists( 'pegasus_botify_ai_enabled' ) ? pegasus_botify_ai_enabled() : false,
			'chatUrl'          => esc_url_raw( rest_url( Pegasus_Botify_Chat::REST_NAMESPACE . '/chat' ) ),
			'nonce'            => wp_create_nonce( Pegasus_Botify_Chat::NONCE_ACTION ),
			// Optional hosted-backend fields (managed/subscription tier); unused in BYOK mode.
			'sessionEndpoint'  => self::session_endpoint(),
			'publicToken'      => self::mint_public_token(),
		);

		/**
		 * Filter the full config payload before it is returned. Lets other code add or
		 * override fields without editing this class.
		 *
		 * @param array $data
		 */
		$data = apply_filters( 'pegasus_botify_config', $data );

		$response = new WP_REST_Response( $data, 200 );

		// Lightly cacheable: the static fields are stable, but publicToken is short-lived
		// and hours.isOpen is time-sensitive, so keep the window small.
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Payload sections
	 * ------------------------------------------------------------------- */

	/**
	 * Quick-reply buttons → [{ label, type, value }].
	 *
	 * @return array
	 */
	private static function quick_replies() {
		$rows = self::opt( 'quick_replies', array() );
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['label'] ) ) {
					continue;
				}
				$out[] = array(
					'label' => (string) $row['label'],
					'type'  => isset( $row['response_type'] ) ? (string) $row['response_type'] : 'text',
					'value' => isset( $row['response_value'] ) ? (string) $row['response_value'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Appearance block. Colors are null when unset so the widget can fall back to theme
	 * custom properties or its own defaults.
	 *
	 * @return array
	 */
	private static function appearance() {
		$inherit_default = pegasus_botify_is_pegasus_active() ? 'on' : '';
		$inherit         = ( 'on' === self::opt( 'inherit_theme', $inherit_default ) );

		$color = function ( $key ) {
			$val = trim( (string) self::opt( $key, '' ) );
			return '' !== $val ? $val : null;
		};

		$avatar = trim( (string) self::opt( 'avatar', '' ) );

		return array(
			'inheritTheme' => $inherit,
			'bubbleColor'  => $color( 'bubble_color' ),
			'headerColor'  => $color( 'header_color' ),
			'accentColor'  => $color( 'accent_color' ),
			'position'     => (string) self::opt( 'position', 'bottom-right' ),
			'avatar'       => '' !== $avatar ? $avatar : null,
		);
	}

	/**
	 * Public-safe business identity. Internal emails (lead/escalation) are intentionally
	 * NOT included — they are server-side only.
	 *
	 * @return array
	 */
	private static function business() {
		return array(
			'name'  => (string) self::opt( 'business_name', get_bloginfo( 'name' ) ),
			'phone' => (string) self::opt( 'phone', '' ),
		);
	}

	/**
	 * Computed hours state (reuses the M2 Hours module). All date math is done here in
	 * PHP — the widget/model never computes open/closed (spec §3.5).
	 *
	 * @return array
	 */
	private static function hours_state() {
		if ( ! function_exists( 'pegasus_botify_get_hours' ) ) {
			return array(
				'isOpen'     => null,
				'closesAt'   => null,
				'nextOpenAt' => null,
				'summary'    => '',
				'timezone'   => 'UTC',
			);
		}

		$state = pegasus_botify_get_hours()->get_state();

		return array(
			'isOpen'     => (bool) $state['is_open'],
			'closesAt'   => $state['closes_at'],
			'nextOpenAt' => $state['next_open_at'],
			'summary'    => $state['summary'],
			'timezone'   => $state['timezone'],
		);
	}

	/**
	 * The backend chat endpoint the widget POSTs to. Undecided at this milestone, so it
	 * derives from a constant/filter and is null until wired (spec §8, §10).
	 *
	 * @return string|null
	 */
	private static function session_endpoint() {
		$default = null;
		if ( defined( 'PEGASUS_BOTIFY_API_BASE' ) && PEGASUS_BOTIFY_API_BASE ) {
			$default = rtrim( (string) PEGASUS_BOTIFY_API_BASE, '/' ) . '/v1/chat';
		}

		/**
		 * Filter the session endpoint URL.
		 *
		 * @param string|null $default
		 */
		return apply_filters( 'pegasus_botify_session_endpoint', $default );
	}

	/**
	 * Mint a short-lived, domain-scoped public token from the site key.
	 *
	 * Per spec §5.3 the plugin mints this so the widget can authenticate to the backend
	 * WITHOUT ever seeing the site key. It is an HS256-style token — base64url(payload) +
	 * "." + base64url(HMAC-SHA256(payload, site_key)) — that the backend verifies because
	 * it knows the same site key for the tenant.
	 *
	 * Returns null when no site key is configured (widget then runs in an unconfigured
	 * state). When the backend's /v1/session mint flow exists (spec §8, M6), swap the
	 * result via the `pegasus_botify_public_token` filter without changing the payload.
	 *
	 * @return string|null
	 */
	private static function mint_public_token() {
		$site_key = trim( (string) self::opt( 'site_key', '' ) );
		if ( '' === $site_key ) {
			return null;
		}

		$ttl = (int) apply_filters( 'pegasus_botify_public_token_ttl', HOUR_IN_SECONDS );
		$exp = time() + max( 60, $ttl );

		$payload = array(
			'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'exp'    => $exp,
		);

		$body  = self::base64url( wp_json_encode( $payload ) );
		$sig   = self::base64url( hash_hmac( 'sha256', $body, $site_key, true ) );
		$token = $body . '.' . $sig;

		/**
		 * Filter the minted public token (e.g. to substitute a backend-issued token).
		 *
		 * @param string $token
		 * @param array  $payload
		 */
		return apply_filters( 'pegasus_botify_public_token', $token, $payload );
	}

	/**
	 * URL-safe, unpadded base64.
	 *
	 * @param string $data
	 * @return string
	 */
	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
