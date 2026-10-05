<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes a single form submission end to end: nonce -> honeypot -> time
 * trap -> rate limit -> sanitize/validate against the registered field
 * schema -> write entry -> write per-stage logs -> fire scc_form_submitted
 * -> optional webhook -> build response.
 *
 * Called by both SCC_REST_Controller (AJAX/fetch mode) and the admin-post
 * fallback (plain PHP-reload mode) in site-core.php, so all the security
 * logic lives in exactly one place regardless of transport.
 */
class SCC_Form_Handler {

	const MIN_FILL_SECONDS = 3;
	const DEFAULT_RATE_LIMIT_COUNT  = 5;
	const DEFAULT_RATE_LIMIT_WINDOW = 60; // seconds

	/**
	 * Fasi dell'invio in corso, per mandare all'hub una riga sola a fine
	 * elaborazione invece di una per fase. Il registro locale (scc_logs)
	 * non cambia: resta una riga per fase come oggi.
	 *
	 * @var array
	 */
	private static $stage_buffer = array();

	/**
	 * @param string $slug     Registered form slug.
	 * @param array  $raw_post Raw $_POST-shaped array (already JSON-decoded if needed).
	 * @return array { success: bool, message: string, redirect?: string, http_status: int, entry_id?: int }
	 */
	public static function process( $slug, array $raw_post ) {
		self::$stage_buffer = array();
		$risultato = self::process_internal( $slug, $raw_post );
		self::flush_hub_log( sanitize_key( $slug ) );
		return $risultato;
	}

	/**
	 * @param string $slug     Registered form slug.
	 * @param array  $raw_post Raw $_POST-shaped array (already JSON-decoded if needed).
	 * @return array { success: bool, message: string, redirect?: string, http_status: int, entry_id?: int }
	 */
	private static function process_internal( $slug, array $raw_post ) {
		$slug = sanitize_key( $slug );
		$config = SCC_Form_Registry::get( $slug );

		if ( ! $config ) {
			return self::fail( $slug, null, 'unknown_form', __( 'Questo modulo non risulta configurato.', 'site-core' ), 404 );
		}

		// 1. Nonce.
		$nonce = isset( $raw_post['scc_nonce'] ) ? $raw_post['scc_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'scc_form_' . $slug ) ) {
			self::log( null, $slug, 'nonce', 'fail', 'Nonce verification failed.' );
			return self::fail( $slug, null, 'invalid_nonce', __( 'La sessione &egrave; scaduta: ricarica la pagina e riprova.', 'site-core' ), 403 );
		}
		self::log( null, $slug, 'nonce', 'pass', '' );

		// 2. Honeypot , must be empty. A filled honeypot means a bot; fail
		// silently with a generic success-looking message so the bot doesn't
		// learn its submission was rejected.
		$honeypot_value = isset( $raw_post['scc_hp_field'] ) ? trim( (string) $raw_post['scc_hp_field'] ) : '';
		if ( '' !== $honeypot_value ) {
			self::log( null, $slug, 'honeypot', 'fail', 'Honeypot field was filled.' );
			return self::fail( $slug, null, 'rejected', __( 'Grazie.', 'site-core' ), 200, true );
		}
		self::log( null, $slug, 'honeypot', 'pass', '' );

		// 3. Time trap , reject submissions faster than a plausible human fill time.
		$submitted_at = isset( $raw_post['scc_timestamp'] ) ? (int) $raw_post['scc_timestamp'] : 0;
		$elapsed = time() - $submitted_at;
		if ( $submitted_at <= 0 || $elapsed < self::MIN_FILL_SECONDS ) {
			self::log( null, $slug, 'time_trap', 'fail', "Elapsed: {$elapsed}s" );
			return self::fail( $slug, null, 'rejected', __( 'Grazie.', 'site-core' ), 200, true );
		}
		self::log( null, $slug, 'time_trap', 'pass', "Elapsed: {$elapsed}s" );

		// 4. Rate limit, per hashed IP + form slug.
		$ip_hash = self::hash_ip( self::get_client_ip() );
		if ( self::is_rate_limited( $ip_hash, $slug ) ) {
			self::log( null, $slug, 'rate_limit', 'fail', 'Rate limit exceeded.' );
			return self::fail( $slug, null, 'rate_limited', __( 'Troppi invii ravvicinati: riprova tra un minuto.', 'site-core' ), 429 );
		}
		self::bump_rate_limit( $ip_hash, $slug );
		self::log( null, $slug, 'rate_limit', 'pass', '' );

		// La licenza NON blocca piu' i moduli (dalla 2.6.0): a chiave scaduta
		// i form dei clienti devono continuare a funzionare, altrimenti un
		// ritardo di fatturazione interno diventa un disservizio per il
		// visitatore del sito. Quello che si ferma a licenza scaduta e'
		// solo l'editor dei testi e la testata/pie' (vedi SCC_Editable_Text
		// e SCC_Site_Content) piu' il battito verso l'hub (vedi SCC_Hub).

		// 5. Sanitize + validate against the registered field schema.
		list( $clean_data, $errors ) = self::sanitize_and_validate( $raw_post, $config['fields'] );
		if ( ! empty( $errors ) ) {
			self::log( null, $slug, 'validation', 'fail', implode( '; ', $errors ) );
			return self::fail( $slug, null, 'validation_failed', implode( ' ', $errors ), 422 );
		}
		self::log( null, $slug, 'validation', 'pass', '' );

		// 6. Write the entry.
		global $wpdb;
		$table = $wpdb->prefix . 'scc_entries';
		$wpdb->insert(
			$table,
			array(
				'form_slug'  => $slug,
				'status'     => 'received',
				'data'       => wp_json_encode( $clean_data ),
				'ip_hash'    => $ip_hash,
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'], 0, 255 ) ) : '',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$entry_id = (int) $wpdb->insert_id;

		if ( ! $entry_id ) {
			self::log( null, $slug, 'db_write', 'fail', $wpdb->last_error );
			return self::fail( $slug, null, 'storage_error', __( 'Non siamo riusciti a salvare la richiesta: riprova.', 'site-core' ), 500 );
		}
		self::log( $entry_id, $slug, 'db_write', 'pass', "Entry #{$entry_id}" );

		// 7. Sanctioned extension point.
		do_action( 'scc_form_submitted', $slug, $entry_id, $clean_data );

		// 8. Optional webhook, per Form Configuration.
		$form_config = SCC_Admin::get_form_config( $slug );
		if ( ! empty( $form_config['webhook_enabled'] ) && ! empty( $form_config['webhook_url'] ) ) {
			self::send_webhook( $form_config['webhook_url'], $slug, $entry_id, $clean_data );
		}

		// 9. Response, per Form Configuration's success behavior.
		$response = array(
			'success'     => true,
			'message'     => ! empty( $form_config['success_message'] ) ? $form_config['success_message'] : __( 'Grazie, la tua richiesta &egrave; stata inviata. Ti rispondiamo al pi&ugrave; presto.', 'site-core' ),
			'http_status' => 200,
			'entry_id'    => $entry_id,
		);
		if ( ! empty( $form_config['success_behavior'] ) && 'redirect' === $form_config['success_behavior'] && ! empty( $form_config['success_redirect'] ) ) {
			$response['redirect'] = esc_url_raw( $form_config['success_redirect'] );
		}
		return $response;
	}

	private static function fail( $slug, $entry_id, $code, $message, $http_status, $soft = false ) {
		return array(
			'success'     => $soft, // soft-fail (honeypot/time trap) reports success=true to the bot but does no real work.
			'code'        => $code,
			'message'     => $message,
			'http_status' => $http_status,
		);
	}

	private static function sanitize_and_validate( $raw_post, array $fields ) {
		$clean  = array();
		$errors = array();

		foreach ( $fields as $key => $rule ) {
			$type     = isset( $rule['type'] ) ? $rule['type'] : 'text';
			$required = ! empty( $rule['required'] );
			$value    = isset( $raw_post[ $key ] ) ? $raw_post[ $key ] : '';

			switch ( $type ) {
				case 'email':
					$value = sanitize_email( $value );
					if ( $required && ! is_email( $value ) ) {
						$errors[] = sprintf( __( 'Il campo %s deve contenere un indirizzo email valido.', 'site-core' ), $key );
					}
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					if ( $required && '' === trim( $value ) ) {
						$errors[] = sprintf( __( 'Il campo %s &egrave; obbligatorio.', 'site-core' ), $key );
					}
					break;
				case 'number':
					$value = is_numeric( $value ) ? $value + 0 : '';
					if ( $required && '' === $value ) {
						$errors[] = sprintf( __( 'Il campo %s deve contenere un numero.', 'site-core' ), $key );
					}
					break;
				case 'checkbox':
					$value = ! empty( $value ) ? 1 : 0;
					if ( $required && ! $value ) {
						$errors[] = sprintf( __( 'Il campo %s deve essere selezionato.', 'site-core' ), $key );
					}
					break;
				default: // text
					$value = sanitize_text_field( $value );
					if ( $required && '' === trim( $value ) ) {
						$errors[] = sprintf( __( 'Il campo %s &egrave; obbligatorio.', 'site-core' ), $key );
					}
			}

			$clean[ $key ] = $value;
		}

		return array( $clean, $errors );
	}

	private static function is_rate_limited( $ip_hash, $slug ) {
		$count = (int) get_transient( "scc_rl_{$ip_hash}_{$slug}" );
		$limit = apply_filters( 'scc_form_rate_limit', self::DEFAULT_RATE_LIMIT_COUNT, $slug );
		return $count >= $limit;
	}

	private static function bump_rate_limit( $ip_hash, $slug ) {
		$window = apply_filters( 'scc_form_rate_limit_window', self::DEFAULT_RATE_LIMIT_WINDOW, $slug );
		$key    = "scc_rl_{$ip_hash}_{$slug}";
		$count  = (int) get_transient( $key );
		set_transient( $key, $count + 1, $window );
	}

	private static function get_client_ip() {
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', $_SERVER[ $key ] )[0];
				return trim( $ip );
			}
		}
		return '0.0.0.0';
	}

	/**
	 * IPs are stored hashed, never in plaintext , nothing downstream should
	 * assume a raw IP is retrievable from an entry.
	 */
	private static function hash_ip( $ip ) {
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	private static function send_webhook( $url, $slug, $entry_id, $data ) {
		$result = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'form_slug' => $slug,
						'entry_id'  => $entry_id,
						'data'      => $data,
						'site'      => home_url(),
					)
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			self::log( $entry_id, $slug, 'webhook', 'fail', $result->get_error_message() );
		} else {
			$code = wp_remote_retrieve_response_code( $result );
			self::log( $entry_id, $slug, 'webhook', ( $code >= 200 && $code < 300 ) ? 'pass' : 'fail', "HTTP {$code}" );
		}
	}

	private static function log( $entry_id, $slug, $stage, $result, $message ) {
		// Si bufferizza per l'hub: una riga sola a fine invio (vedi
		// flush_hub_log()), non piu' una per fase. Il registro locale sotto
		// invece resta com'era: una riga per fase, sempre.
		self::$stage_buffer[] = array(
			'entry_id' => $entry_id,
			'stage'    => $stage,
			'result'   => $result,
			'message'  => $message,
		);

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'scc_logs',
			array(
				'entry_id'   => $entry_id,
				'form_slug'  => $slug,
				'stage'      => $stage,
				'result'     => $result,
				'message'    => $message,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Manda all'hub il riepilogo di un invio, non piu' una riga per fase.
	 *
	 * Un invio pulito (tutte le fasi 'pass') non genera niente: e' il
	 * punto di questo cambio. Se qualcosa e' andato storto si manda una
	 * riga sola con la prima fase che ha fallito -- con l'elaborazione che
	 * si ferma al primo fallimento e' anche l'unica, tranne il caso del
	 * webhook che puo' fallire dopo che tutto il resto e' passato.
	 */
	private static function flush_hub_log( $slug ) {
		if ( empty( self::$stage_buffer ) ) {
			return;
		}

		$buffer              = self::$stage_buffer;
		self::$stage_buffer = array();

		$fallita = null;
		foreach ( $buffer as $fase ) {
			if ( 'fail' === $fase['result'] ) {
				$fallita = $fase;
				break;
			}
		}

		if ( ! $fallita ) {
			return;
		}

		$entry_id = null;
		foreach ( $buffer as $fase ) {
			if ( null !== $fase['entry_id'] ) {
				$entry_id = $fase['entry_id'];
			}
		}

		// Punto di aggancio per chi deve sapere com'e' andato un invio.
		// Lo usa il modulo hub per il registro remoto.
		do_action( 'scc_form_log', $entry_id, $slug, $fallita['stage'], $fallita['result'], $fallita['message'] );
	}
}

/**
 * Markup helper: outputs the three security fields (nonce, honeypot,
 * timestamp) a theme/pattern/block needs inside any <form data-scc-form="slug">.
 * Keeps the exact attribute names/classes in one place instead of duplicated
 * across every form's markup.
 */
function scc_render_form_security_fields( $slug ) {
	$slug = sanitize_key( $slug );
	wp_nonce_field( 'scc_form_' . $slug, 'scc_nonce' );
	echo '<span class="scc-honeypot" aria-hidden="true">';
	echo '<label>Leave this field empty<input type="text" name="scc_hp_field" tabindex="-1" autocomplete="off" value="" /></label>';
	echo '</span>';
	echo '<input type="hidden" name="scc_timestamp" value="' . esc_attr( time() ) . '" />';
}

