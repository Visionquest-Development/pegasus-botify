<?php
/*
Plugin Name: Pegasus Botify
Plugin URI:  https://visionquestdevelopment.com/
Description: AI-powered chat widget for Pegasus sites — answers customer questions 24/7 from a settings-managed knowledge base, states office hours, and captures leads after hours. Bring-your-own Anthropic key: the plugin calls the model server-side; the key never reaches the browser.
Version:     0.8.0
Author:      Jim O'Brien
Author URI:  https://visionquestdevelopment.com/
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: pegasus-botify
Domain Path: /languages
*/

/**
 * Silence is golden; exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'PEGASUS_BOTIFY_VERSION', '0.8.0' );
define( 'PEGASUS_BOTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'PEGASUS_BOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'PEGASUS_BOTIFY_OPTION_KEY', 'pegasus_botify_options' );

/**
 * Load CMB2.
 *
 * MUST be required unconditionally at the top level — NOT inside a `class_exists()`
 * guard and NOT hooked to `init`. CMB2 runs its own version-arbitration on the `init`
 * hook: every bundled copy registers a version-stamped bootstrap and the newest one
 * present wins, older copies no-op. Wrapping this in `class_exists()` or deferring it
 * to a hook breaks that mechanism. See the build spec §3.2 (docs/pegasus-botify-spec.md).
 *
 * The only guard permitted is a filesystem check, purely to avoid a fatal if the
 * bundled library is somehow missing.
 */
if ( file_exists( PEGASUS_BOTIFY_PATH . 'inc/cmb2/init.php' ) ) {
	require_once PEGASUS_BOTIFY_PATH . 'inc/cmb2/init.php';
}

/**
 * Is a Pegasus theme (parent or child) active?
 *
 * `get_template()` returns the *parent* template slug even for child themes, so this
 * is true for both `pegasus` and `pegasus-child`. Used to decide whether the settings
 * page nests under the theme's "Pegasus Options" menu or stands alone, and to default
 * the "inherit theme colors" appearance option on.
 *
 * @return bool
 */
function pegasus_botify_is_pegasus_active() {
	return 'pegasus' === get_template();
}

/**
 * Load plugin includes.
 */
require_once PEGASUS_BOTIFY_PATH . 'includes/class-options.php';
require_once PEGASUS_BOTIFY_PATH . 'includes/class-hours.php';
require_once PEGASUS_BOTIFY_PATH . 'includes/class-rest.php';
require_once PEGASUS_BOTIFY_PATH . 'includes/class-enqueue.php';
require_once PEGASUS_BOTIFY_PATH . 'includes/class-chat.php';
require_once PEGASUS_BOTIFY_PATH . 'includes/class-io.php';

/**
 * Resolve the client's Anthropic API key (bring-your-own-key model).
 *
 * Prefers a `PEGASUS_BOTIFY_ANTHROPIC_KEY` constant in wp-config.php (never touches the
 * database) and falls back to the saved settings field. The key is used only server-side
 * by class-chat.php — it is NEVER included in any REST response or sent to the browser.
 *
 * @return string Empty string when no key is configured.
 */
function pegasus_botify_get_api_key() {
	if ( defined( 'PEGASUS_BOTIFY_ANTHROPIC_KEY' ) && PEGASUS_BOTIFY_ANTHROPIC_KEY ) {
		return trim( (string) PEGASUS_BOTIFY_ANTHROPIC_KEY );
	}
	if ( function_exists( 'cmb2_get_option' ) ) {
		return trim( (string) cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, 'anthropic_api_key', '' ) );
	}
	$opts = get_option( PEGASUS_BOTIFY_OPTION_KEY, array() );
	return is_array( $opts ) && isset( $opts['anthropic_api_key'] ) ? trim( (string) $opts['anthropic_api_key'] ) : '';
}

/**
 * Whether AI answers are enabled AND a key is available.
 *
 * @return bool
 */
function pegasus_botify_ai_enabled() {
	$toggle = function_exists( 'cmb2_get_option' )
		? cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, 'ai_enabled', 'on' )
		: 'on';
	return ( 'on' === $toggle ) && '' !== pegasus_botify_get_api_key();
}

/**
 * Build a Pegasus_Botify_Hours object from the saved CMB2 settings.
 *
 * This is the thin WP-side adapter: it reads the CMB2 group values (which are stored as
 * flat rows) and folds them into the normalized §7 structure the pure Hours class wants —
 * merging multiple regular-hours rows for the same weekday into interval arrays (split
 * shifts / lunch closures), and multiple exception rows for the same date likewise.
 *
 * @return Pegasus_Botify_Hours
 */
/**
 * Normalize a time value to 24-hour "HH:MM".
 *
 * CMB2's time field stores times in the site's format, which is usually 12-hour ("9:00 AM").
 * The Hours module requires zero-padded 24-hour "HH:MM", so normalize whatever was saved
 * (12-hour or 24-hour) here. Parsed by components to avoid timezone/timestamp pitfalls.
 *
 * @param mixed $raw
 * @return string "HH:MM" or '' if unparseable.
 */
function pegasus_botify_normalize_time( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*([AaPp][Mm])?\s*$/', $raw, $m ) ) {
		return '';
	}
	$hour   = (int) $m[1];
	$minute = (int) $m[2];
	if ( ! empty( $m[3] ) ) { // 12-hour with AM/PM.
		$pm = 'PM' === strtoupper( $m[3] );
		if ( 12 === $hour ) {
			$hour = $pm ? 12 : 0;
		} elseif ( $pm ) {
			$hour += 12;
		}
	}
	if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 ) {
		return '';
	}
	return sprintf( '%02d:%02d', $hour, $minute );
}

function pegasus_botify_get_hours() {
	$get = function ( $key, $default = '' ) {
		if ( function_exists( 'cmb2_get_option' ) ) {
			return cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, $key, $default );
		}
		$opts = get_option( PEGASUS_BOTIFY_OPTION_KEY, array() );
		return is_array( $opts ) && isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	};

	$timezone = $get( 'timezone', 'UTC' );

	// Regular hours: rows of { day, open, close } → regular[day][] = [open, close].
	$regular = array();
	$rows    = $get( 'regular_hours', array() );
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			if ( empty( $row['day'] ) || ! isset( $row['open'], $row['close'] ) ) {
				continue;
			}
			$open  = pegasus_botify_normalize_time( $row['open'] );
			$close = pegasus_botify_normalize_time( $row['close'] );
			if ( '' === $open || '' === $close ) {
				continue;
			}
			$regular[ $row['day'] ][] = array( $open, $close );
		}
	}

	// Exceptions: rows of { date, closed, open, close, label } → merged by date.
	$exceptions_by_date = array();
	$ex_rows            = $get( 'exceptions', array() );
	if ( is_array( $ex_rows ) ) {
		foreach ( $ex_rows as $row ) {
			if ( empty( $row['date'] ) ) {
				continue;
			}
			$date = $row['date'];
			if ( ! isset( $exceptions_by_date[ $date ] ) ) {
				$exceptions_by_date[ $date ] = array(
					'date'   => $date,
					'closed' => false,
					'hours'  => array(),
					'label'  => isset( $row['label'] ) ? $row['label'] : '',
				);
			}
			if ( ! empty( $row['closed'] ) ) {
				$exceptions_by_date[ $date ]['closed'] = true;
			} else {
				$open  = pegasus_botify_normalize_time( isset( $row['open'] ) ? $row['open'] : '' );
				$close = pegasus_botify_normalize_time( isset( $row['close'] ) ? $row['close'] : '' );
				if ( '' !== $open && '' !== $close ) {
					$exceptions_by_date[ $date ]['hours'][] = array( $open, $close );
				}
			}
			if ( empty( $exceptions_by_date[ $date ]['label'] ) && ! empty( $row['label'] ) ) {
				$exceptions_by_date[ $date ]['label'] = $row['label'];
			}
		}
	}

	return new Pegasus_Botify_Hours( array(
		'timezone'   => $timezone,
		'regular'    => $regular,
		'exceptions' => array_values( $exceptions_by_date ),
	) );
}

/**
 * Boot the settings/options page.
 */
add_action( 'plugins_loaded', 'pegasus_botify_bootstrap' );
function pegasus_botify_bootstrap() {
	Pegasus_Botify_Options::get_instance();
	Pegasus_Botify_REST::init();
	Pegasus_Botify_Enqueue::init();
	Pegasus_Botify_Chat::init();
	Pegasus_Botify_IO::init();
}

/**
 * Load translations.
 *
 * Hooked to `init` (not earlier) — WP 6.7+ warns if a textdomain is loaded before
 * `init`. This is the correct place for i18n setup; user-facing strings are only
 * translated from `admin_menu`/`cmb2_admin_init` onward, all of which run after this.
 */
add_action( 'init', 'pegasus_botify_load_textdomain' );
function pegasus_botify_load_textdomain() {
	load_plugin_textdomain( 'pegasus-botify', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * Activation hook.
 *
 * Milestone 1 needs no custom DB schema — just record the installed version so future
 * releases can run migrations if required.
 */
register_activation_hook( __FILE__, 'pegasus_botify_activate' );
function pegasus_botify_activate() {
	add_option( 'pegasus_botify_version', PEGASUS_BOTIFY_VERSION );
	update_option( 'pegasus_botify_version', PEGASUS_BOTIFY_VERSION );
}

/**
 * Deactivation hook.
 *
 * Intentionally a no-op placeholder — we do not delete client settings on deactivate.
 * (Data removal, if ever wanted, belongs in an uninstall.php, not here.)
 */
register_deactivation_hook( __FILE__, 'pegasus_botify_deactivate' );
function pegasus_botify_deactivate() {
	// No cleanup at Milestone 1.
}

/**
 * Front-end shortcode: [pegasus_botify]
 *
 * Requests the chat widget on this page by enqueuing the launcher (spec §5.4). The
 * launcher then renders a floating bubble and lazy-loads the widget chunk on click, so
 * the shortcode itself outputs no visible markup — placement is a floating bubble by
 * design (§3.4), not an inline element. This is what the theme's site-wide "Enable
 * Botify" footer toggle calls.
 *
 * @param array $atts Shortcode attributes (reserved for future use).
 * @return string
 */
add_shortcode( 'pegasus_botify', 'pegasus_botify_shortcode' );
function pegasus_botify_shortcode( $atts = array() ) {
	shortcode_atts( array(), $atts, 'pegasus_botify' );

	Pegasus_Botify_Enqueue::enqueue();

	return '<!-- pegasus-botify: launcher enqueued; floating bubble rendered by JS. -->';
}
