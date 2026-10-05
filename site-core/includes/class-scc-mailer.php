<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posta dei form (dalla 2.18.0): SMTP del sito, mittente per ogni form,
 * gestione delle risposte e testo del messaggio.
 *
 * - SMTP: una sola configurazione per sito (opzione scc_smtp). Se attiva,
 *   configura PHPMailer per tutte le email inviate con wp_mail(). La password
 *   e' salvata cifrata (libsodium, chiave derivata dai sali di WordPress) e
 *   non viene mai restituita da nessuna schermata, REST o strumento MCP.
 * - Per ogni form (opzioni in SCC_Admin::OPTION_KEY, chiavi mail_*): mittente,
 *   destinatari, modalita' di risposta, oggetto e corpo con segnaposto.
 * - Un invio che fallisce non blocca il form: la entry e' gia' salvata, l'esito
 *   finisce nel registro delle fasi (stage "mail" / "mail_confirm").
 */
class SCC_Mailer {

	const OPTION_SMTP = 'scc_smtp';

	/** @var string Ultimo errore PHPMailer, per il registro e per la mail di prova. */
	private static $last_error = '';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 20 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		add_action( 'admin_post_scc_save_smtp', array( __CLASS__, 'handle_save_smtp' ) );
		add_action( 'admin_post_scc_test_smtp', array( __CLASS__, 'handle_test_smtp' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Impostazioni SMTP                                                 */
	/* ---------------------------------------------------------------- */

	public static function smtp_defaults() {
		return array(
			'enabled'    => false,
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls', // none | ssl | tls
			'auth'       => true,
			'username'   => '',
			'password'   => '', // cifrata
			'from_email' => '',
			'from_name'  => '',
		);
	}

	public static function get_smtp() {
		$saved = get_option( self::OPTION_SMTP, array() );
		return array_merge( self::smtp_defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** Versione sicura per schermate e MCP: mai la password. */
	public static function get_smtp_public() {
		$smtp                 = self::get_smtp();
		$smtp['password_set'] = '' !== $smtp['password'];
		unset( $smtp['password'] );
		return $smtp;
	}

	public static function smtp_ready() {
		$smtp = self::get_smtp();
		return ! empty( $smtp['enabled'] ) && '' !== $smtp['host'] && (int) $smtp['port'] > 0;
	}

	public static function sanitize_smtp( array $in, array $prev ) {
		$enc = isset( $in['encryption'] ) ? (string) $in['encryption'] : 'tls';
		$out = array(
			'enabled'    => ! empty( $in['enabled'] ),
			'host'       => sanitize_text_field( isset( $in['host'] ) ? $in['host'] : '' ),
			'port'       => max( 1, min( 65535, (int) ( isset( $in['port'] ) ? $in['port'] : 587 ) ) ),
			'encryption' => in_array( $enc, array( 'none', 'ssl', 'tls' ), true ) ? $enc : 'tls',
			'auth'       => ! empty( $in['auth'] ),
			'username'   => sanitize_text_field( isset( $in['username'] ) ? $in['username'] : '' ),
			'password'   => $prev['password'],
			'from_email' => self::clean_email( isset( $in['from_email'] ) ? $in['from_email'] : '' ),
			'from_name'  => self::clean_line( isset( $in['from_name'] ) ? $in['from_name'] : '' ),
		);
		// Password: campo vuoto = si tiene quella gia' salvata.
		if ( isset( $in['password'] ) && '' !== (string) $in['password'] ) {
			$out['password'] = self::encrypt( (string) $in['password'] );
		}
		if ( ! empty( $in['clear_password'] ) ) {
			$out['password'] = '';
		}
		return $out;
	}

	public static function handle_save_smtp() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'site-core' ) );
		}
		check_admin_referer( 'scc_save_smtp' );
		$prev = self::get_smtp();
		$new  = self::sanitize_smtp( wp_unslash( $_POST ), $prev ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( self::OPTION_SMTP, $new, false );
		wp_safe_redirect( admin_url( 'admin.php?page=scc-form-config&smtp_saved=1' ) );
		exit;
	}

	public static function handle_test_smtp() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'site-core' ) );
		}
		check_admin_referer( 'scc_test_smtp' );
		$user = wp_get_current_user();
		$to   = isset( $_POST['test_to'] ) ? self::clean_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( '' === $to ) {
			$to = $user->user_email;
		}
		self::$last_error = '';
		$ok               = self::send(
			array( $to ),
			__( 'Prova SMTP Site Core', 'site-core' ),
			sprintf( __( "Questa è una email di prova inviata da %s.\nSe la stai leggendo, la configurazione SMTP funziona.", 'site-core' ), home_url() ),
			array(),
			false
		);
		$arg = $ok ? 'smtp_test=ok' : 'smtp_test=fail&smtp_err=' . rawurlencode( substr( self::$last_error, 0, 300 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=scc-form-config&' . $arg ) );
		exit;
	}

	public static function configure_phpmailer( $phpmailer ) {
		if ( ! self::smtp_ready() ) {
			return;
		}
		$smtp = self::get_smtp();
		// phpcs:disable WordPress.NamingConventions.ValidVariableName
		$phpmailer->isSMTP();
		$phpmailer->Host       = $smtp['host'];
		$phpmailer->Port       = (int) $smtp['port'];
		$phpmailer->SMTPSecure = 'none' === $smtp['encryption'] ? '' : $smtp['encryption'];
		$phpmailer->SMTPAutoTLS = 'none' !== $smtp['encryption'];
		$phpmailer->SMTPAuth   = ! empty( $smtp['auth'] );
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $smtp['username'];
			$phpmailer->Password = self::decrypt( $smtp['password'] );
		}
		// phpcs:enable
	}

	public static function capture_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	/* ---------------------------------------------------------------- */
	/* Configurazione mail per form                                      */
	/* ---------------------------------------------------------------- */

	/** Valori predefiniti delle chiavi mail_* di un form. */
	public static function form_mail_defaults() {
		return array(
			'mail_enabled'       => false,
			'mail_to'            => '',
			'mail_from_email'    => '',
			'mail_from_name'     => '',
			'mail_reply_mode'    => 'sender', // sender | visitor | noreply
			'mail_reply_field'   => '',
			'mail_subject'       => '',
			'mail_body'          => '',
			'mail_html'          => false,
			'confirm_enabled'    => false,
			'confirm_to_field'   => '',
			'confirm_subject'    => '',
			'confirm_body'       => '',
		);
	}

	public static function mail_keys() {
		return array_keys( self::form_mail_defaults() );
	}

	/** Pulisce e normalizza le chiavi mail_* / confirm_* di un input (POST o MCP). */
	public static function sanitize_form_mail( array $in ) {
		$out  = array();
		foreach ( $in as $k => $v ) {
			switch ( $k ) {
				case 'mail_enabled':
				case 'mail_html':
				case 'confirm_enabled':
					$out[ $k ] = ! empty( $v );
					break;
				case 'mail_to':
					$out[ $k ] = implode( ', ', self::parse_recipients( $v ) );
					break;
				case 'mail_from_email':
					$out[ $k ] = self::clean_email( $v );
					break;
				case 'mail_from_name':
				case 'mail_subject':
				case 'confirm_subject':
					$out[ $k ] = self::clean_line( $v );
					break;
				case 'mail_reply_mode':
					$out[ $k ] = in_array( $v, array( 'sender', 'visitor', 'noreply' ), true ) ? $v : 'sender';
					break;
				case 'mail_reply_field':
				case 'confirm_to_field':
					$out[ $k ] = sanitize_key( $v );
					break;
				case 'mail_body':
				case 'confirm_body':
					$out[ $k ] = wp_kses_post( (string) $v );
					break;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Invio dei messaggi di un form                                     */
	/* ---------------------------------------------------------------- */

	/**
	 * Chiamato dal form handler a invio concluso. Ritorna l'elenco degli esiti
	 * [ stage, 'pass'|'fail', messaggio ] da scrivere nel registro.
	 */
	public static function send_for_submission( $slug, $entry_id, array $data, array $config, array $form ) {
		$cfg     = array_merge( self::form_mail_defaults(), $config );
		$results = array();

		$visitor_email = self::visitor_email( $data, $form, $cfg['mail_reply_field'] );
		$vars          = self::build_vars( $slug, $entry_id, $data, $form, (bool) $cfg['mail_html'] );

		if ( ! empty( $cfg['mail_enabled'] ) ) {
			$to = self::parse_recipients( $cfg['mail_to'] );
			if ( empty( $to ) ) {
				$to = array( get_option( 'admin_email' ) );
			}
			$subject = self::render( '' !== $cfg['mail_subject'] ? $cfg['mail_subject'] : '[{site_name}] Nuova richiesta da {form_label}', $vars, false );
			$body    = self::render( '' !== trim( $cfg['mail_body'] ) ? $cfg['mail_body'] : "Nuova richiesta dal modulo {form_label}:\n\n{all_fields}", $vars, (bool) $cfg['mail_html'] );

			$extra = array();
			if ( 'visitor' === $cfg['mail_reply_mode'] && '' !== $visitor_email ) {
				$extra['reply_to'] = $visitor_email;
			} elseif ( 'noreply' === $cfg['mail_reply_mode'] ) {
				$extra['no_reply'] = true;
			}
			$extra['from_email'] = $cfg['mail_from_email'];
			$extra['from_name']  = $cfg['mail_from_name'];

			self::$last_error = '';
			$ok               = self::send( $to, $subject, $body, $extra, (bool) $cfg['mail_html'] );
			$results[]        = array( 'mail', $ok ? 'pass' : 'fail', $ok ? implode( ', ', $to ) : ( self::$last_error ? self::$last_error : 'wp_mail ha restituito false.' ) );
		}

		if ( ! empty( $cfg['confirm_enabled'] ) ) {
			$field = '' !== $cfg['confirm_to_field'] ? $cfg['confirm_to_field'] : '';
			$to    = self::visitor_email( $data, $form, $field );
			if ( '' === $to ) {
				$results[] = array( 'mail_confirm', 'fail', 'Nessun indirizzo email del visitatore nei dati inviati.' );
			} else {
				$subject = self::render( '' !== $cfg['confirm_subject'] ? $cfg['confirm_subject'] : 'Abbiamo ricevuto la tua richiesta', $vars, false );
				$body    = self::render( '' !== trim( $cfg['confirm_body'] ) ? $cfg['confirm_body'] : "Grazie, abbiamo ricevuto la tua richiesta e ti risponderemo al più presto.\n\nRiepilogo:\n{all_fields}", $vars, (bool) $cfg['mail_html'] );
				$extra   = array(
					'from_email' => $cfg['mail_from_email'],
					'from_name'  => $cfg['mail_from_name'],
				);
				// La conferma segue la regola del form: se la casella non accetta risposte, niente Reply-To.
				if ( 'noreply' === $cfg['mail_reply_mode'] ) {
					$extra['no_reply'] = true;
				}
				self::$last_error = '';
				$ok               = self::send( array( $to ), $subject, $body, $extra, (bool) $cfg['mail_html'] );
				$results[]        = array( 'mail_confirm', $ok ? 'pass' : 'fail', $ok ? 'Inviata al visitatore.' : ( self::$last_error ? self::$last_error : 'wp_mail ha restituito false.' ) );
			}
		}

		return $results;
	}

	/**
	 * Invia un messaggio con wp_mail(). Tutti gli indirizzi e le intestazioni
	 * sono ripuliti da ritorni a capo (header injection).
	 *
	 * @param string[] $to
	 * @param array    $extra { from_email, from_name, reply_to, no_reply }
	 */
	public static function send( array $to, $subject, $body, array $extra, $html ) {
		$headers = array( $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8' );

		$smtp       = self::get_smtp();
		$from_email = ! empty( $extra['from_email'] ) ? self::clean_email( $extra['from_email'] ) : '';
		$from_name  = ! empty( $extra['from_name'] ) ? self::clean_line( $extra['from_name'] ) : '';
		if ( '' === $from_email ) {
			$from_email = self::clean_email( $smtp['from_email'] );
			$from_name  = '' !== $from_name ? $from_name : self::clean_line( $smtp['from_name'] );
		}
		if ( '' !== $from_email ) {
			$headers[] = 'From: ' . ( '' !== $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
		}

		if ( ! empty( $extra['reply_to'] ) && '' !== self::clean_email( $extra['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . self::clean_email( $extra['reply_to'] );
		}
		if ( ! empty( $extra['no_reply'] ) ) {
			$headers[] = 'Auto-Submitted: auto-generated';
			$headers[] = 'X-Auto-Response-Suppress: All';
		}

		$to = array_values( array_filter( array_map( array( __CLASS__, 'clean_email' ), $to ) ) );
		if ( empty( $to ) ) {
			self::$last_error = 'Nessun destinatario valido.';
			return false;
		}

		$subject = self::clean_line( $subject );
		if ( $html ) {
			$body = wpautop( $body );
		}
		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/* ---------------------------------------------------------------- */
	/* Template                                                          */
	/* ---------------------------------------------------------------- */

	/** Segnaposto disponibili per un form: {campo}, {all_fields} e quelli di sistema. */
	public static function placeholders( array $form ) {
		$list = array( '{all_fields}', '{form_label}', '{site_name}', '{site_url}', '{entry_id}', '{date}' );
		foreach ( array_keys( isset( $form['fields'] ) ? $form['fields'] : array() ) as $key ) {
			$list[] = '{' . $key . '}';
		}
		return $list;
	}

	private static function build_vars( $slug, $entry_id, array $data, array $form, $html ) {
		$esc  = $html ? 'esc_html' : 'wp_strip_all_tags';
		$vars = array(
			'form_label' => $esc( isset( $form['label'] ) ? $form['label'] : $slug ),
			'site_name'  => $esc( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'site_url'   => $esc( home_url() ),
			'entry_id'   => (string) (int) $entry_id,
			'date'       => $esc( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
		);
		$rows = array();
		foreach ( $data as $key => $value ) {
			$text         = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			$vars[ $key ] = $html ? esc_html( $text ) : $text;
			$label        = isset( $form['fields'][ $key ]['label'] ) ? $form['fields'][ $key ]['label'] : $key;
			$rows[]       = $html ? '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $text ) : $label . ': ' . $text;
		}
		$vars['all_fields'] = implode( $html ? "<br />\n" : "\n", $rows );
		return $vars;
	}

	/** Sostituisce {chiave} con i valori; i segnaposto sconosciuti spariscono. */
	private static function render( $template, array $vars, $html ) {
		if ( $html ) {
			$template = wp_kses_post( $template );
		}
		return preg_replace_callback(
			'/\{([a-z0-9_\-]+)\}/i',
			function ( $m ) use ( $vars ) {
				return isset( $vars[ $m[1] ] ) ? $vars[ $m[1] ] : '';
			},
			$template
		);
	}

	private static function visitor_email( array $data, array $form, $field ) {
		if ( '' !== $field && isset( $data[ $field ] ) && is_email( $data[ $field ] ) ) {
			return self::clean_email( $data[ $field ] );
		}
		if ( '' !== $field ) {
			return '';
		}
		foreach ( isset( $form['fields'] ) ? $form['fields'] : array() as $key => $rule ) {
			if ( isset( $rule['type'] ) && 'email' === $rule['type'] && ! empty( $data[ $key ] ) && is_email( $data[ $key ] ) ) {
				return self::clean_email( $data[ $key ] );
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------- */
	/* Utilita'                                                          */
	/* ---------------------------------------------------------------- */

	private static function clean_line( $v ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', sanitize_text_field( (string) $v ) ) );
	}

	private static function clean_email( $v ) {
		$e = sanitize_email( trim( (string) $v ) );
		return is_email( $e ) ? $e : '';
	}

	private static function parse_recipients( $v ) {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', (string) $v ) as $part ) {
			$e = self::clean_email( $part );
			if ( '' !== $e ) {
				$out[] = $e;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . 'scc_smtp', true );
	}

	private static function encrypt( $plain ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	private static function decrypt( $stored ) {
		if ( '' === $stored || 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? '' : $plain;
	}
}
