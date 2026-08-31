<?php
/**
 * Pegasus Botify — AI chat endpoint (bring-your-own-key model).
 *
 *   POST /wp-json/pegasus-botify/v1/chat
 *
 * Same-origin: the widget calls this endpoint on the client's own WordPress site, and THIS
 * PHP calls Anthropic using the client's own API key. The key stays on the client's server
 * and is never sent to the browser (§3.1). This replaces the external hosted backend for
 * the self-serve model; the hosted backend remains an option for a managed/subscription tier.
 *
 * The Anthropic Messages API is called via WordPress's own HTTP layer (wp_remote_post)
 * rather than the Anthropic PHP SDK: vendoring the SDK's Guzzle dependency into a plugin
 * distributed to many sites is a well-known source of autoloader/version conflicts.
 *
 * @package pegasus-botify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pegasus_Botify_Chat {

	const REST_NAMESPACE = 'pegasus-botify/v1';
	const NONCE_ACTION   = 'pegasus_botify_chat';
	const ANTHROPIC_URL  = 'https://api.anthropic.com/v1/messages';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Light gate: a nonce (CSRF hygiene) plus a per-IP rate limit (abuse / credit-burn
	 * protection — the client pays per message). The endpoint isn't secret; these bound
	 * casual abuse.
	 */
	public static function permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-Pegasus-Botify-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error( 'pegasus_botify_bad_nonce', 'Invalid or missing nonce.', array( 'status' => 403 ) );
		}
		if ( self::rate_limited() ) {
			return new WP_Error( 'pegasus_botify_rate_limited', 'Too many requests. Please slow down.', array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Simple fixed-window per-IP limit.
	 *
	 * @return bool true when the caller is over the limit.
	 */
	private static function rate_limited() {
		$max    = (int) apply_filters( 'pegasus_botify_rate_limit', 20 ); // requests
		$window = (int) apply_filters( 'pegasus_botify_rate_window', MINUTE_IN_SECONDS ); // seconds
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key    = 'pegasus_botify_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * Handle a chat turn.
	 */
	public static function handle( WP_REST_Request $request ) {
		if ( ! function_exists( 'pegasus_botify_ai_enabled' ) || ! pegasus_botify_ai_enabled() ) {
			return new WP_REST_Response( array( 'error' => 'ai_disabled' ), 200 );
		}

		$params   = $request->get_json_params();
		$messages = isset( $params['messages'] ) && is_array( $params['messages'] ) ? $params['messages'] : array();
		$messages = self::normalize_messages( $messages );

		if ( empty( $messages ) ) {
			return new WP_REST_Response( array( 'error' => 'no_messages' ), 400 );
		}

		$system = self::build_system_prompt();
		$model  = self::model();
		$key    = pegasus_botify_get_api_key();

		$response = wp_remote_post(
			self::ANTHROPIC_URL,
			array(
				'timeout' => (int) apply_filters( 'pegasus_botify_request_timeout', 30 ),
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => (int) apply_filters( 'pegasus_botify_max_tokens', 1024 ),
						'system'     => $system,
						'messages'   => $messages,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fallback( 'network_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return self::fallback( 'api_error', $detail );
		}

		if ( isset( $body['stop_reason'] ) && 'refusal' === $body['stop_reason'] ) {
			return new WP_REST_Response(
				array( 'reply' => self::CONTACT_FALLBACK ),
				200
			);
		}

		$reply = self::extract_text( $body );
		if ( '' === $reply ) {
			return self::fallback( 'empty_reply', 'no text block in response' );
		}

		return new WP_REST_Response(
			array(
				'reply' => $reply,
				'usage' => isset( $body['usage'] ) ? array(
					'input'  => (int) ( $body['usage']['input_tokens'] ?? 0 ),
					'output' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
				) : null,
			),
			200
		);
	}

	/* ---------------------------------------------------------------- helpers */

	const CONTACT_FALLBACK = "I'm sorry, I can't help with that. Please try rephrasing, or contact us directly.";

	/**
	 * Log server-side and return a friendly, non-leaky reply so the widget can render it.
	 */
	private static function fallback( $code, $detail ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "[pegasus-botify] chat {$code}: {$detail}" );
		}
		return new WP_REST_Response(
			array(
				'reply' => "Sorry — I'm having trouble responding right now. Please try again in a moment.",
				'error' => $code,
			),
			200
		);
	}

	/**
	 * Assemble the system prompt: business identity + computed hours state (§8 step 6, §3.5).
	 *
	 * @return string
	 */
	private static function build_system_prompt() {
		$opt = function ( $key, $default = '' ) {
			return function_exists( 'cmb2_get_option' )
				? cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, $key, $default )
				: $default;
		};

		$name  = (string) $opt( 'business_name', get_bloginfo( 'name' ) );
		$desc  = (string) $opt( 'business_description', '' );
		$phone = (string) $opt( 'phone', '' );

		$state = function_exists( 'pegasus_botify_get_hours' )
			? pegasus_botify_get_hours()->get_state()
			: array( 'is_open' => null, 'closes_at' => null, 'next_open_at' => null, 'summary' => '', 'timezone' => 'UTC', 'now_local' => '' );

		if ( null === $state['is_open'] ) {
			$status = 'Hours are not configured.';
		} elseif ( $state['is_open'] ) {
			$status = 'OPEN right now' . ( $state['closes_at'] ? ', closing at ' . $state['closes_at'] : '' ) . '.';
		} else {
			$status = 'CLOSED right now' . ( $state['next_open_at'] ? '; next opening ' . $state['next_open_at'] : '' ) . '.';
		}

		$lines = array(
			sprintf( 'You are the friendly customer-support assistant for %s%s.', $name, $desc ? ', ' . $desc : '' ),
			'',
			sprintf( 'Current local time: %s (%s).', $state['now_local'], $state['timezone'] ),
			'Business status: ' . $status,
		);
		if ( ! empty( $state['summary'] ) ) {
			$lines[] = 'Weekly hours:';
			$lines[] = $state['summary'];
		}
		if ( $phone ) {
			$lines[] = 'Contact phone: ' . $phone . '.';
		}

		// Knowledge base (FAQ) — the admin-authored answers the assistant should rely on.
		$kb = self::knowledge_base( $opt );
		if ( ! empty( $kb ) ) {
			$lines[] = '';
			$lines[] = 'Reference knowledge (use these to answer; they are authoritative):';
			foreach ( $kb as $qa ) {
				$lines[] = 'Q: ' . $qa['question'];
				$lines[] = 'A: ' . $qa['answer'];
			}
		}

		$help_url = trim( (string) $opt( 'help_url', '' ) );

		$guidelines = array(
			'',
			'Guidelines:',
			'- Answer concisely and warmly, in 1–3 short sentences (a few more only for step-by-step how-tos).',
			'- Prefer the reference knowledge above. Use general knowledge about this kind of business too, but do NOT invent specifics you were not given (exact prices, policies, staff names, availability).',
		);
		if ( '' !== $help_url ) {
			$guidelines[] = '- If a question is not covered by the reference knowledge and you are unsure, direct the user to ' . $help_url . '.';
		} else {
			$guidelines[] = "- If you don't know something, say so plainly and offer the phone number if there is one.";
		}
		$guidelines[] = '- Never claim to have completed an action you cannot perform (booking, ordering, payment).';

		$lines = array_merge( $lines, $guidelines );

		return implode( "\n", $lines );
	}

	/**
	 * Read the knowledge-base Q&A group into [{question, answer}], dropping empty rows and
	 * capping how much we inject (keeps the prompt — and cost — bounded).
	 *
	 * @param callable $opt option reader
	 * @return array<int, array{question:string, answer:string}>
	 */
	private static function knowledge_base( $opt ) {
		$rows = $opt( 'knowledge_base', array() );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$max = (int) apply_filters( 'pegasus_botify_max_knowledge', 40 );
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$q = isset( $row['question'] ) ? trim( (string) $row['question'] ) : '';
			$a = isset( $row['answer'] ) ? trim( (string) $row['answer'] ) : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out[] = array( 'question' => $q, 'answer' => $a );
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Filter the transcript to valid roles/strings, cap length/history, collapse consecutive
	 * same-role turns, and ensure it begins and ends with a user message (Anthropic rules).
	 *
	 * @param array $messages
	 * @return array
	 */
	private static function normalize_messages( $messages ) {
		$max_len   = (int) apply_filters( 'pegasus_botify_message_max_chars', 4000 );
		$max_turns = (int) apply_filters( 'pegasus_botify_max_history', 12 );

		$clean = array();
		foreach ( $messages as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['role'], $m['content'] ) ) {
				continue;
			}
			if ( 'user' !== $m['role'] && 'assistant' !== $m['role'] ) {
				continue;
			}
			$content = trim( (string) $m['content'] );
			if ( '' === $content ) {
				continue;
			}
			if ( strlen( $content ) > $max_len ) {
				$content = substr( $content, 0, $max_len );
			}
			$clean[] = array( 'role' => $m['role'], 'content' => $content );
		}

		// Keep the most recent turns.
		if ( count( $clean ) > $max_turns ) {
			$clean = array_slice( $clean, -$max_turns );
		}

		// Drop leading assistant turns (must start with user).
		while ( ! empty( $clean ) && 'user' !== $clean[0]['role'] ) {
			array_shift( $clean );
		}

		// Collapse consecutive same-role turns (Anthropic requires alternation).
		$merged = array();
		foreach ( $clean as $m ) {
			$last = end( $merged );
			if ( $last && $last['role'] === $m['role'] ) {
				$merged[ key( $merged ) ]['content'] .= "\n\n" . $m['content'];
			} else {
				$merged[] = $m;
			}
		}

		// Must end with a user message.
		if ( ! empty( $merged ) && 'user' !== $merged[ count( $merged ) - 1 ]['role'] ) {
			array_pop( $merged );
		}

		return array_values( $merged );
	}

	/**
	 * Selected model, from settings.
	 *
	 * @return string
	 */
	private static function model() {
		$model = function_exists( 'cmb2_get_option' )
			? cmb2_get_option( PEGASUS_BOTIFY_OPTION_KEY, 'ai_model', 'claude-haiku-4-5' )
			: 'claude-haiku-4-5';
		return $model ? (string) $model : 'claude-haiku-4-5';
	}

	/**
	 * Concatenate text blocks from a Messages API response.
	 *
	 * @param array|null $body
	 * @return string
	 */
	private static function extract_text( $body ) {
		if ( ! is_array( $body ) || empty( $body['content'] ) || ! is_array( $body['content'] ) ) {
			return '';
		}
		$out = '';
		foreach ( $body['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$out .= $block['text'];
			}
		}
		return trim( $out );
	}
}
