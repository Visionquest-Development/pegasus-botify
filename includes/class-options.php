<?php
/**
 * Pegasus Botify — CMB2 options page.
 *
 * Registers a single admin menu item and the settings metabox described in the build
 * spec §5.2 (docs/pegasus-botify-spec.md). All fields persist to one option array,
 * PEGASUS_BOTIFY_OPTION_KEY.
 *
 * Modeled on the parent theme's Pegasus_Admin class (themes/pegasus/inc/theme-options.php).
 *
 * NOTE (Milestone 1): the Hours field groups below are captured and stored, but the
 * serialization to the §7 Hours JSON schema and its validation are Milestone 2 and are
 * not implemented here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pegasus_Botify_Options {

	/**
	 * Option key / page slug.
	 * @var string
	 */
	private $key = PEGASUS_BOTIFY_OPTION_KEY;

	/**
	 * Metabox id.
	 * @var string
	 */
	private $metabox_id = 'pegasus_botify_option_metabox';

	/**
	 * Options page hook suffix.
	 * @var string
	 */
	protected $options_page = '';

	/**
	 * Singleton instance.
	 * @var Pegasus_Botify_Options|null
	 */
	protected static $instance = null;

	/**
	 * Get (and lazily create) the running instance.
	 *
	 * @return Pegasus_Botify_Options
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	protected function __construct() {
		// Intentionally empty. Do NOT call __() here — this runs on plugins_loaded,
		// before `init`, and translating that early triggers a _doing_it_wrong notice
		// in WP 6.7+. Titles are translated in add_options_page() (fires after init).
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		// Priority 11 so the parent "Pegasus Options" menu (registered by the theme on
		// admin_menu at the default priority 10) exists before we attach our submenu to it.
		// Plugins load before themes, so without this our callback would otherwise run first
		// and the submenu would be orphaned ("Sorry, you are not allowed to access this page").
		add_action( 'admin_menu', array( $this, 'add_options_page' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'cmb2_admin_init', array( $this, 'add_options_page_metabox' ) );
	}

	/**
	 * Register a single admin menu item.
	 *
	 * When a Pegasus theme is active the page nests as a submenu under the theme's
	 * "Pegasus Options" menu (slug `pegasus_options`). Otherwise it registers its own
	 * top-level menu so the plugin is usable on any theme.
	 */
	public function add_options_page() {
		// Translated here (on admin_menu, after `init`) rather than in the constructor.
		$title      = __( 'Pegasus Botify', 'pegasus-botify' );
		$menu_title = __( 'Botify', 'pegasus-botify' );

		if ( pegasus_botify_is_pegasus_active() ) {
			$this->options_page = add_submenu_page(
				'pegasus_options',       // Parent slug (theme's top-level menu).
				$title,                  // Page title.
				$menu_title,             // Menu title.
				'manage_options',        // Capability.
				$this->key,              // Menu slug.
				array( $this, 'admin_page_display' )
			);
		} else {
			$this->options_page = add_menu_page(
				$title,                  // Page title.
				$menu_title,             // Menu title.
				'manage_options',        // Capability.
				$this->key,              // Menu slug.
				array( $this, 'admin_page_display' ),
				'dashicons-format-chat', // Icon.
				58                       // Position.
			);
		}

		// Include CMB2 CSS in the head on our page to avoid a flash of unstyled content.
		add_action( "admin_print_styles-{$this->options_page}", array( 'CMB2_hookup', 'enqueue_cmb_css' ) );
	}

	/**
	 * Enqueue the collapsible-sections script/style, only on our options page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== $this->options_page ) {
			return;
		}
		$css = PEGASUS_BOTIFY_PATH . 'css/admin.css';
		$js  = PEGASUS_BOTIFY_PATH . 'js/admin-options.js';
		wp_enqueue_style(
			'pegasus-botify-admin',
			PEGASUS_BOTIFY_URL . 'css/admin.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : PEGASUS_BOTIFY_VERSION
		);
		wp_enqueue_script(
			'pegasus-botify-admin-options',
			PEGASUS_BOTIFY_URL . 'js/admin-options.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : PEGASUS_BOTIFY_VERSION,
			true
		);
	}

	/**
	 * Render the settings page. CMB2 draws the form.
	 */
	public function admin_page_display() {
		?>
		<div class="wrap cmb2-options-page <?php echo esc_attr( $this->key ); ?>">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
			<?php cmb2_metabox_form( $this->metabox_id, $this->key ); ?>
		</div>
		<?php
	}

	/**
	 * Register the options metabox and all fields (spec §5.2).
	 */
	public function add_options_page_metabox() {
		$cmb = new_cmb2_box( array(
			'id'         => $this->metabox_id,
			'hookup'     => false,
			'cmb_styles' => false,
			'show_on'    => array(
				'key'   => 'options-page',
				'value' => array( $this->key ),
			),
		) );

		/*============================
			CONNECTION
		=============================*/
		$cmb->add_field( array(
			'name' => __( 'Connection', 'pegasus-botify' ),
			'desc' => __( 'The API key powers the AI answers. It is used server-side only and never sent to the browser.', 'pegasus-botify' ),
			'type' => 'title',
			'id'   => 'connection_title',
		) );
		$cmb->add_field( array(
			'name'       => __( 'Site key (optional)', 'pegasus-botify' ),
			'desc'       => __( 'Only used by the optional hosted Botify backend (managed/subscription mode). Leave blank when using your own Anthropic key below.', 'pegasus-botify' ),
			'id'         => 'site_key',
			'type'       => 'text',
			'attributes' => array(
				'autocomplete' => 'off',
			),
		) );
		$key_from_constant = defined( 'PEGASUS_BOTIFY_ANTHROPIC_KEY' ) && PEGASUS_BOTIFY_ANTHROPIC_KEY;
		$cmb->add_field( array(
			'name'       => __( 'Anthropic API key', 'pegasus-botify' ),
			'desc'       => $key_from_constant
				? __( 'A key is set via the <code>PEGASUS_BOTIFY_ANTHROPIC_KEY</code> constant in wp-config.php, so this field is ignored. (More secure — the key never touches the database.)', 'pegasus-botify' )
				: __( 'Your Anthropic API key (get one at console.anthropic.com). Powers the AI answers; you are billed by Anthropic per message. Stored in the site database — for stronger security, define <code>PEGASUS_BOTIFY_ANTHROPIC_KEY</code> in wp-config.php instead. This key is used server-side only and never sent to visitors.', 'pegasus-botify' ),
			'id'         => 'anthropic_api_key',
			'type'       => $key_from_constant ? 'text' : 'text',
			'attributes' => array_merge(
				array( 'autocomplete' => 'off', 'placeholder' => 'sk-ant-...' ),
				$key_from_constant ? array( 'readonly' => 'readonly', 'disabled' => 'disabled' ) : array()
			),
		) );

		/*============================
			BUSINESS IDENTITY
		=============================*/
		$cmb->add_field( array(
			'name' => __( 'Business identity', 'pegasus-botify' ),
			'desc' => __( 'Used to build the assistant’s system prompt and to route lead notifications.', 'pegasus-botify' ),
			'type' => 'title',
			'id'   => 'identity_title',
		) );
		$cmb->add_field( array(
			'name' => __( 'Business name', 'pegasus-botify' ),
			'id'   => 'business_name',
			'type' => 'text',
		) );
		$cmb->add_field( array(
			'name' => __( 'What we do', 'pegasus-botify' ),
			'desc' => __( 'A short description of the business. Feeds the system prompt.', 'pegasus-botify' ),
			'id'   => 'business_description',
			'type' => 'textarea',
		) );
		$cmb->add_field( array(
			'name'       => __( 'Contact email for lead notifications', 'pegasus-botify' ),
			'id'         => 'contact_email',
			'type'       => 'text_email',
		) );
		$cmb->add_field( array(
			'name' => __( 'Phone number', 'pegasus-botify' ),
			'id'   => 'phone',
			'type' => 'text',
		) );

		/*============================
			HOURS
			(Fields only at M1; JSON serialization + validation is Milestone 2.)
		=============================*/
		$cmb->add_field( array(
			'name' => __( 'Hours', 'pegasus-botify' ),
			'desc' => __( 'Regular weekly hours and date-specific exceptions. Times are local to the timezone selected below.', 'pegasus-botify' ),
			'type' => 'title',
			'id'   => 'hours_title',
		) );
		$cmb->add_field( array(
			'name'             => __( 'Timezone', 'pegasus-botify' ),
			'desc'             => __( 'IANA timezone name. Stored as a name (e.g. America/New_York), never a fixed offset.', 'pegasus-botify' ),
			'id'               => 'timezone',
			'type'             => 'select',
			'show_option_none' => false,
			'default'          => 'America/New_York',
			'options'          => $this->timezone_options(),
		) );

		// Regular hours — repeatable group. Multiple rows per day allowed (split shifts / lunch closures).
		$regular_group = $cmb->add_field( array(
			'id'      => 'regular_hours',
			'type'    => 'group',
			'options' => array(
				'group_title'   => __( 'Hours row {#}', 'pegasus-botify' ),
				'add_button'    => __( 'Add hours row', 'pegasus-botify' ),
				'remove_button' => __( 'Remove row', 'pegasus-botify' ),
				'sortable'      => true,
				'closed'        => true,
			),
		) );
		$cmb->add_group_field( $regular_group, array(
			'name'    => __( 'Day', 'pegasus-botify' ),
			'id'      => 'day',
			'type'    => 'select',
			'options' => $this->day_options(),
		) );
		$cmb->add_group_field( $regular_group, array(
			'name'        => __( 'Opens', 'pegasus-botify' ),
			'id'          => 'open',
			'type'        => 'text_time',
			'attributes'  => array( 'data-timeformat' => 'H:i' ),
		) );
		$cmb->add_group_field( $regular_group, array(
			'name'        => __( 'Closes', 'pegasus-botify' ),
			'id'          => 'close',
			'type'        => 'text_time',
			'attributes'  => array( 'data-timeformat' => 'H:i' ),
		) );

		// Exceptions — repeatable group. Override regular hours by date.
		$exceptions_group = $cmb->add_field( array(
			'id'      => 'exceptions',
			'type'    => 'group',
			'options' => array(
				'group_title'   => __( 'Exception {#}', 'pegasus-botify' ),
				'add_button'    => __( 'Add exception', 'pegasus-botify' ),
				'remove_button' => __( 'Remove exception', 'pegasus-botify' ),
				'sortable'      => true,
				'closed'        => true,
			),
		) );
		$cmb->add_group_field( $exceptions_group, array(
			'name' => __( 'Date', 'pegasus-botify' ),
			'id'   => 'date',
			'type' => 'text_date',
			'date_format' => 'Y-m-d',
		) );
		$cmb->add_group_field( $exceptions_group, array(
			'name' => __( 'Closed all day', 'pegasus-botify' ),
			'id'   => 'closed',
			'type' => 'checkbox',
		) );
		$cmb->add_group_field( $exceptions_group, array(
			'name'       => __( 'Opens', 'pegasus-botify' ),
			'desc'       => __( 'Leave blank if closed all day.', 'pegasus-botify' ),
			'id'         => 'open',
			'type'       => 'text_time',
			'attributes' => array( 'data-timeformat' => 'H:i' ),
		) );
		$cmb->add_group_field( $exceptions_group, array(
			'name'       => __( 'Closes', 'pegasus-botify' ),
			'id'         => 'close',
			'type'       => 'text_time',
			'attributes' => array( 'data-timeformat' => 'H:i' ),
		) );
		$cmb->add_group_field( $exceptions_group, array(
			'name' => __( 'Label', 'pegasus-botify' ),
			'desc' => __( 'e.g. “Thanksgiving”, “Christmas Eve”.', 'pegasus-botify' ),
			'id'   => 'label',
			'type' => 'text',
		) );

		/*============================
			BEHAVIOR
		=============================*/
		$cmb->add_field( array(
			'name' => __( 'Behavior', 'pegasus-botify' ),
			'type' => 'title',
			'id'   => 'behavior_title',
		) );
		$cmb->add_field( array(
			'name'    => __( 'Enable AI answers', 'pegasus-botify' ),
			'desc'    => __( 'When on (and an Anthropic key is set above), free-text questions that don’t match a quick reply are answered by the AI. Turn off to run as a quick-reply-only FAQ widget.', 'pegasus-botify' ),
			'id'      => 'ai_enabled',
			'type'    => 'checkbox',
			'default' => 'on',
		) );
		$cmb->add_field( array(
			'name'             => __( 'AI model', 'pegasus-botify' ),
			'desc'             => __( 'Which Claude model answers questions. Haiku is cheapest and fastest; Sonnet and Opus are more capable and cost more per message.', 'pegasus-botify' ),
			'id'               => 'ai_model',
			'type'             => 'select',
			'show_option_none' => false,
			'default'          => 'claude-haiku-4-5',
			'options'          => array(
				'claude-haiku-4-5' => __( 'Claude Haiku 4.5 — cheapest, fast (recommended)', 'pegasus-botify' ),
				'claude-sonnet-5'  => __( 'Claude Sonnet 5 — balanced', 'pegasus-botify' ),
				'claude-opus-4-8'  => __( 'Claude Opus 4.8 — most capable, priciest', 'pegasus-botify' ),
			),
		) );
		$cmb->add_field( array(
			'name'    => __( 'Greeting message', 'pegasus-botify' ),
			'id'      => 'greeting',
			'type'    => 'textarea_small',
			'default' => __( 'Hi! How can I help?', 'pegasus-botify' ),
		) );

		// Quick-reply buttons — repeatable group.
		$quick_group = $cmb->add_field( array(
			'id'      => 'quick_replies',
			'type'    => 'group',
			'options' => array(
				'group_title'   => __( 'Quick reply {#}', 'pegasus-botify' ),
				'add_button'    => __( 'Add quick reply', 'pegasus-botify' ),
				'remove_button' => __( 'Remove quick reply', 'pegasus-botify' ),
				'sortable'      => true,
				'closed'        => true,
			),
		) );
		$cmb->add_group_field( $quick_group, array(
			'name' => __( 'Button label', 'pegasus-botify' ),
			'id'   => 'label',
			'type' => 'text',
		) );
		$cmb->add_group_field( $quick_group, array(
			'name'    => __( 'Response type', 'pegasus-botify' ),
			'id'      => 'response_type',
			'type'    => 'select',
			'options' => array(
				'hours'   => __( 'Show hours', 'pegasus-botify' ),
				'contact' => __( 'Show contact info', 'pegasus-botify' ),
				'link'    => __( 'Open a link', 'pegasus-botify' ),
				'text'    => __( 'Send a canned message', 'pegasus-botify' ),
			),
		) );
		$cmb->add_group_field( $quick_group, array(
			'name' => __( 'Response value', 'pegasus-botify' ),
			'desc' => __( 'URL for “Open a link”, or the message text for “Send a canned message”.', 'pegasus-botify' ),
			'id'   => 'response_value',
			'type' => 'text',
		) );

		$cmb->add_field( array(
			'name'             => __( 'After-hours action', 'pegasus-botify' ),
			'desc'             => __( 'What the widget does when the business is closed.', 'pegasus-botify' ),
			'id'               => 'after_hours_action',
			'type'             => 'select',
			'show_option_none' => false,
			'default'          => 'capture_lead',
			'options'          => array(
				'capture_lead'         => __( 'Capture a lead', 'pegasus-botify' ),
				'show_hours'           => __( 'Show hours only', 'pegasus-botify' ),
				'show_hours_contact'   => __( 'Show hours + contact form', 'pegasus-botify' ),
			),
		) );
		$cmb->add_field( array(
			'name' => __( 'Escalation email', 'pegasus-botify' ),
			'desc' => __( 'Where captured leads and escalations are sent.', 'pegasus-botify' ),
			'id'   => 'escalation_email',
			'type' => 'text_email',
		) );

		/*============================
			KNOWLEDGE BASE (FAQ)
		=============================*/
		$cmb->add_field( array(
			'name' => __( 'Knowledge base', 'pegasus-botify' ),
			'desc' => __( 'Question/answer pairs the AI uses to answer visitors. Add anything the assistant should know — how-tos, policies, product details. Keep answers short and factual. (For a large site, a crawler/retrieval index is planned; for now this is the AI’s knowledge.)', 'pegasus-botify' ),
			'type' => 'title',
			'id'   => 'knowledge_title',
		) );
		$cmb->add_field( array(
			'name' => __( 'When the AI can’t answer, send users here', 'pegasus-botify' ),
			'desc' => __( 'Optional URL (e.g. your docs site). The assistant links here when a question isn’t covered above.', 'pegasus-botify' ),
			'id'   => 'help_url',
			'type' => 'text_url',
		) );
		$kb_group = $cmb->add_field( array(
			'id'      => 'knowledge_base',
			'type'    => 'group',
			'options' => array(
				'group_title'   => __( 'Q&A {#}', 'pegasus-botify' ),
				'add_button'    => __( 'Add question', 'pegasus-botify' ),
				'remove_button' => __( 'Remove question', 'pegasus-botify' ),
				'sortable'      => true,
				'closed'        => true,
			),
		) );
		$cmb->add_group_field( $kb_group, array(
			'name' => __( 'Question / topic', 'pegasus-botify' ),
			'id'   => 'question',
			'type' => 'text',
		) );
		$cmb->add_group_field( $kb_group, array(
			'name' => __( 'Answer', 'pegasus-botify' ),
			'id'   => 'answer',
			'type' => 'textarea_small',
		) );

		/*============================
			APPEARANCE
		=============================*/
		$cmb->add_field( array(
			'name' => __( 'Appearance', 'pegasus-botify' ),
			'type' => 'title',
			'id'   => 'appearance_title',
		) );
		$cmb->add_field( array(
			'name'    => __( 'Inherit Pegasus theme colors', 'pegasus-botify' ),
			'desc'    => __( 'When on, the widget reads the theme’s --pegasus-* CSS custom properties. Turn off to use the colors below.', 'pegasus-botify' ),
			'id'      => 'inherit_theme',
			'type'    => 'checkbox',
			'default' => pegasus_botify_is_pegasus_active() ? 'on' : '',
		) );
		$cmb->add_field( array(
			'name' => __( 'Bubble color', 'pegasus-botify' ),
			'id'   => 'bubble_color',
			'type' => 'colorpicker',
		) );
		$cmb->add_field( array(
			'name' => __( 'Header color', 'pegasus-botify' ),
			'id'   => 'header_color',
			'type' => 'colorpicker',
		) );
		$cmb->add_field( array(
			'name' => __( 'Accent color', 'pegasus-botify' ),
			'id'   => 'accent_color',
			'type' => 'colorpicker',
		) );
		$cmb->add_field( array(
			'name'             => __( 'Bubble position', 'pegasus-botify' ),
			'id'               => 'position',
			'type'             => 'select',
			'show_option_none' => false,
			'default'          => 'bottom-right',
			'options'          => array(
				'bottom-right' => __( 'Bottom right', 'pegasus-botify' ),
				'bottom-left'  => __( 'Bottom left', 'pegasus-botify' ),
			),
		) );
		$cmb->add_field( array(
			'name'       => __( 'Avatar image', 'pegasus-botify' ),
			'id'         => 'avatar',
			'type'       => 'file',
			'query_args' => array(
				'type' => array( 'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'image/svg+xml' ),
			),
		) );
	}

	/**
	 * Weekday options for the regular-hours group. Keys match the §7 schema.
	 *
	 * @return array
	 */
	private function day_options() {
		return array(
			'mon' => __( 'Monday', 'pegasus-botify' ),
			'tue' => __( 'Tuesday', 'pegasus-botify' ),
			'wed' => __( 'Wednesday', 'pegasus-botify' ),
			'thu' => __( 'Thursday', 'pegasus-botify' ),
			'fri' => __( 'Friday', 'pegasus-botify' ),
			'sat' => __( 'Saturday', 'pegasus-botify' ),
			'sun' => __( 'Sunday', 'pegasus-botify' ),
		);
	}

	/**
	 * IANA timezone options, grouped, from PHP's own database.
	 *
	 * @return array slug => label
	 */
	private function timezone_options() {
		$zones   = timezone_identifiers_list();
		$options = array();
		foreach ( $zones as $zone ) {
			$options[ $zone ] = str_replace( '_', ' ', $zone );
		}
		return $options;
	}
}
