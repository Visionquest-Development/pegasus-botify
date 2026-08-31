<?php
/**
 * Pegasus Botify — settings import/export.
 *
 * Lets you configure the plugin on one site (e.g. local VVV) and move the settings —
 * knowledge base, hours, exceptions, business info, behavior, appearance — to another
 * (e.g. production) as a JSON file, instead of re-entering everything.
 *
 * Two safety rules:
 *   - Secrets are never exported (the Anthropic API key, the hosted-backend site key), so a
 *     downloaded file can't leak them.
 *   - Import MERGES: keys present in the file overwrite those keys; keys absent (like the API
 *     key you set on production) are left untouched. So importing never wipes your live key.
 *
 * @package pegasus-botify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pegasus_Botify_IO {

	const PAGE_SLUG      = 'pegasus_botify_io';
	const EXPORT_ACTION  = 'pegasus_botify_export';
	const IMPORT_ACTION  = 'pegasus_botify_import';

	/** Never exported or imported (secrets, and site-specific media that wouldn't transfer). */
	const EXCLUDED_KEYS = array( 'anthropic_api_key', 'site_key', 'avatar', 'avatar_id' );

	public static function init() {
		// Priority 12 so the parent Pegasus Options menu (10) and our options page (11) exist first.
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 12 );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'handle_import' ) );
	}

	public static function add_page() {
		$parent = pegasus_botify_is_pegasus_active() ? 'pegasus_options' : 'pegasus_botify_options';
		add_submenu_page(
			$parent,
			__( 'Botify Import / Export', 'pegasus-botify' ),
			__( 'Botify Import / Export', 'pegasus-botify' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ---------------------------------------------------------------- data */

	/**
	 * Build the export payload: metadata + settings with secrets/media removed.
	 *
	 * @return array
	 */
	public static function export_data() {
		$opts = get_option( PEGASUS_BOTIFY_OPTION_KEY, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		foreach ( self::EXCLUDED_KEYS as $key ) {
			unset( $opts[ $key ] );
		}

		return array(
			'_meta'    => array(
				'plugin'   => 'pegasus-botify',
				'version'  => defined( 'PEGASUS_BOTIFY_VERSION' ) ? PEGASUS_BOTIFY_VERSION : '',
				'exported' => current_time( 'mysql' ),
				'site'     => home_url(),
			),
			'settings' => $opts,
		);
	}

	/**
	 * Merge imported settings into the saved option. Secrets/media are ignored even if present.
	 *
	 * @param array $settings
	 * @return int Number of keys imported.
	 */
	public static function import_settings( array $settings ) {
		$current = get_option( PEGASUS_BOTIFY_OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$count = 0;
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, self::EXCLUDED_KEYS, true ) ) {
				continue;
			}
			$current[ $key ] = $value;
			$count++;
		}

		update_option( PEGASUS_BOTIFY_OPTION_KEY, $current );
		return $count;
	}

	/* ---------------------------------------------------------------- handlers */

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pegasus-botify' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$json     = wp_json_encode( self::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$filename = 'pegasus-botify-settings-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download, not HTML.
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pegasus-botify' ) );
		}
		check_admin_referer( self::IMPORT_ACTION );

		$redirect = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		if ( empty( $_FILES['pegasus_botify_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pegasus_botify_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pb_io', 'nofile', $redirect ) );
			exit;
		}

		$size = (int) $_FILES['pegasus_botify_file']['size'];
		if ( $size <= 0 || $size > 2 * MB_IN_BYTES ) {
			wp_safe_redirect( add_query_arg( 'pb_io', 'badsize', $redirect ) );
			exit;
		}

		$raw  = file_get_contents( $_FILES['pegasus_botify_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			wp_safe_redirect( add_query_arg( 'pb_io', 'invalid', $redirect ) );
			exit;
		}

		$count = self::import_settings( $data['settings'] );
		wp_safe_redirect( add_query_arg( array( 'pb_io' => 'ok', 'pb_n' => $count ), $redirect ) );
		exit;
	}

	/* ---------------------------------------------------------------- UI */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$export_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Botify Import / Export', 'pegasus-botify' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'Move your Botify configuration between sites — knowledge base, hours, exceptions, business info, behavior, and appearance. Your Anthropic API key and site key are never included, so you set those separately on each site.', 'pegasus-botify' ); ?></p>

			<h2><?php esc_html_e( 'Export', 'pegasus-botify' ); ?></h2>
			<p><?php esc_html_e( 'Download all Botify settings (minus secrets) as a JSON file.', 'pegasus-botify' ); ?></p>
			<form method="post" action="<?php echo esc_url( $export_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
				<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
				<?php submit_button( __( 'Export settings', 'pegasus-botify' ), 'primary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Import', 'pegasus-botify' ); ?></h2>
			<p><?php esc_html_e( 'Upload a file exported from another site. Matching settings are overwritten; anything not in the file (like your API key) is left as-is.', 'pegasus-botify' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $export_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
				<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
				<input type="file" name="pegasus_botify_file" accept="application/json,.json" required />
				<?php submit_button( __( 'Import settings', 'pegasus-botify' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function notice() {
		if ( ! isset( $_GET['pb_io'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['pb_io'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$map  = array(
			'ok'      => array( 'success', sprintf( __( 'Imported %d settings.', 'pegasus-botify' ), isset( $_GET['pb_n'] ) ? (int) $_GET['pb_n'] : 0 ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'nofile'  => array( 'error', __( 'No file was uploaded.', 'pegasus-botify' ) ),
			'badsize' => array( 'error', __( 'That file is empty or too large.', 'pegasus-botify' ) ),
			'invalid' => array( 'error', __( 'That file is not a valid Botify export.', 'pegasus-botify' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] )
		);
	}
}
