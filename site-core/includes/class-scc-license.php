<?php
/**
 * Gate a chiave prodotto per Site Core.
 *
 * Unica fonte di verita' per tutto il plugin: ogni modulo che deve essere
 * licenziato chiama SCC_License::is_active(). Il metodo legge solo lo stato
 * in cache e non fa mai una chiamata di rete sul percorso della richiesta,
 * quindi e' sicuro invocarlo da un permission_callback REST o dall'handler
 * di un form.
 *
 * Due modalita', nell'ordine in cui vengono valutate:
 *
 * 1. Licenza interna. Nessun hub configurato: il plugin gira sbloccato e lo
 *    dichiara apertamente nella schermata admin. E' la modalita' di sviluppo
 *    e quella dei siti gestiti direttamente.
 * 2. Licenza remota. Con un hub configurato la chiave viene validata contro
 *    di esso, con cache e periodo di tolleranza se l'hub non risponde.
 *
 * Dalla 2.4.0 non esiste piu' la chiave perpetua generabile dal sito: era
 * un modo per scavalcare il gate senza passare dall'hub, e chiunque avesse
 * accesso da amministratore al sito consegnato poteva usarlo.
 *
 * ATTENZIONE per la distribuzione: finche' SCC_LICENSE_HUB_URL non e'
 * definita il plugin e' sbloccato per chiunque lo installi. Prima di
 * distribuirlo a un cliente la costante va definita, altrimenti il gate
 * non gatta nulla.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_License {

	const OPTION_KEY           = 'scc_license_key';
	const OPTION_STATUS        = 'scc_license_status';
	const OPTION_LAST_OK_AT    = 'scc_license_last_ok_at';
	const CRON_HOOK            = 'scc_license_daily_check';
	const STATUS_CACHE_TTL     = 12 * HOUR_IN_SECONDS;
	const OFFLINE_GRACE_PERIOD = 3 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'registra_pagina' ), 30 );
		add_action( 'admin_post_scc_salva_licenza', array( $this, 'salva' ) );
		add_action( self::CRON_HOOK, array( $this, 'controlla' ) );
		add_action( 'init', array( $this, 'pianifica_cron' ) );
	}

	public function pianifica_cron() {
		// Senza hub non c'e' niente da controllare: nessun cron da pianificare.
		if ( ! self::hub_url() ) {
			$next = wp_next_scheduled( self::CRON_HOOK );
			if ( $next ) {
				wp_unschedule_event( $next, self::CRON_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function hub_url(): string {
		$url = defined( 'SCC_LICENSE_HUB_URL' ) ? SCC_LICENSE_HUB_URL : '';
		return (string) apply_filters( 'scc_license_hub_url', $url );
	}

	public static function get_key(): string {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Il controllo che ogni modulo licenziato deve fare.
	 */
	public static function is_active(): bool {
		// 1. Nessun hub configurato: installazione interna, plugin sbloccato.
		if ( ! self::hub_url() ) {
			return apply_filters( 'scc_license_is_active', true, self::get_status() );
		}

		// 2. Licenza remota.
		$key = self::get_key();
		if ( empty( $key ) ) {
			return apply_filters( 'scc_license_is_active', false, null );
		}

		$status = get_option( self::OPTION_STATUS, array() );

		// Hub ha confermato entro la finestra di cache: ci si fida.
		if ( ! empty( $status['active'] ) && ! empty( $status['checked_at'] )
			&& ( time() - $status['checked_at'] ) < self::STATUS_CACHE_TTL ) {
			return apply_filters( 'scc_license_is_active', true, $status );
		}

		// Cache scaduta: invece di bloccare la richiesta su una chiamata di
		// rete si applica la tolleranza, e sara' il cron a rinfrescare.
		$last_ok = (int) get_option( self::OPTION_LAST_OK_AT, 0 );
		$in_tolleranza = $last_ok && ( time() - $last_ok ) < self::OFFLINE_GRACE_PERIOD;
		$attiva = ! empty( $status['active'] ) && $in_tolleranza;

		return apply_filters( 'scc_license_is_active', $attiva, $status );
	}

	/** Stato leggibile per la schermata admin. Non si usa per il gate. */
	public static function get_status(): array {
		if ( ! self::hub_url() ) {
			return array(
				'active'     => true,
				'expires_at' => null,
				'plan'       => 'Interna',
				'reason'     => 'hub_non_configurato',
				'checked_at' => time(),
			);
		}
		return get_option( self::OPTION_STATUS, array(
			'active'     => false,
			'expires_at' => null,
			'plan'       => null,
			'reason'     => 'mai_controllata',
			'checked_at' => null,
		) );
	}

	/**
	 * Interroga l'hub e aggiorna lo stato in cache.
	 */
	public function controlla(): array {
		if ( ! self::hub_url() ) {
			return self::get_status();
		}

		$key = self::get_key();
		if ( empty( $key ) ) {
			$status = array( 'active'=>false, 'expires_at'=>null, 'plan'=>null, 'reason'=>'nessuna_chiave', 'checked_at'=>time() );
			update_option( self::OPTION_STATUS, $status );
			return $status;
		}

		$risposta = wp_remote_post(
			trailingslashit( self::hub_url() ) . 'wp-json/scc-hub/v1/validate',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'key' => $key, 'site' => home_url() ) ),
			)
		);

		if ( is_wp_error( $risposta ) ) {
			// Hub irraggiungibile non e' "chiave scaduta": si lascia lo stato
			// precedente e decide la tolleranza in is_active().
			$status = self::get_status();
			$status['reason'] = 'hub_irraggiungibile';
			$status['checked_at'] = time();
			update_option( self::OPTION_STATUS, $status );
			return $status;
		}

		$corpo = json_decode( wp_remote_retrieve_body( $risposta ), true );
		$status = array(
			'active'     => ! empty( $corpo['active'] ),
			'expires_at' => $corpo['expires_at'] ?? null,
			'plan'       => $corpo['plan'] ?? null,
			'reason'     => $corpo['reason'] ?? 'ok',
			'checked_at' => time(),
		);
		update_option( self::OPTION_STATUS, $status );
		if ( $status['active'] ) {
			update_option( self::OPTION_LAST_OK_AT, time() );
		}
		return $status;
	}

	/* ---------------------------------------------------------------- */

	public function registra_pagina() {
		add_submenu_page(
			'scc-entries',
			__( 'Licenza', 'site-core' ),
			__( 'Licenza', 'site-core' ),
			'manage_options',
			'scc-licenza',
			array( $this, 'render' )
		);
	}

	public function salva() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'site-core' ) );
		}
		check_admin_referer( 'scc_salva_licenza' );

		if ( isset( $_POST['scc_license_key'] ) ) {
			update_option( self::OPTION_KEY, sanitize_text_field( wp_unslash( $_POST['scc_license_key'] ) ) );
			$this->controlla();
		}

		if ( isset( $_POST['ricontrolla'] ) ) {
			$this->controlla();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=scc-licenza&salvato=1' ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = self::get_key();
		$stato  = self::get_status();
		$attiva = self::is_active();
		$hub    = self::hub_url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Licenza', 'site-core' ); ?></h1>

			<?php if ( ! empty( $_GET['salvato'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Salvato.', 'site-core' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:720px;margin-bottom:20px">
				<tbody>
					<tr>
						<th style="width:180px"><?php esc_html_e( 'Stato', 'site-core' ); ?></th>
						<td>
							<strong style="color:<?php echo $attiva ? '#1a7f37' : '#c62828'; ?>">
								<?php echo $attiva ? esc_html__( 'Attiva', 'site-core' ) : esc_html__( 'Non attiva', 'site-core' ); ?>
							</strong>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tipo', 'site-core' ); ?></th>
						<td><?php echo esc_html( $stato['plan'] ? $stato['plan'] : '&mdash;' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Scadenza', 'site-core' ); ?></th>
						<td><?php echo esc_html( $stato['expires_at'] ? $stato['expires_at'] : __( 'Nessuna', 'site-core' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Hub', 'site-core' ); ?></th>
						<td><?php echo $hub ? esc_html( $hub ) : esc_html__( 'Non configurato', 'site-core' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Chiave', 'site-core' ); ?></th>
						<td><code><?php echo $key ? esc_html( $key ) : esc_html__( 'nessuna', 'site-core' ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! $hub ) : ?>
				<div class="notice notice-info inline" style="max-width:720px">
					<p>
						<?php esc_html_e( 'Nessun hub delle licenze configurato: il plugin gira in modalita interna e tutte le funzioni sono sbloccate. Prima di distribuire il plugin a un cliente va definita la costante SCC_LICENSE_HUB_URL, altrimenti il controllo non blocca nulla.', 'site-core' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scc_salva_licenza' ); ?>
				<input type="hidden" name="action" value="scc_salva_licenza" />

				<table class="form-table">
					<tr>
						<th scope="row"><label for="scc_license_key"><?php esc_html_e( 'Chiave prodotto', 'site-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="scc_license_key" name="scc_license_key" value="<?php echo esc_attr( $key ); ?>" />
							<p class="description"><?php esc_html_e( 'La chiave la genera l\'hub quando crei il cliente, e si vede una volta sola.', 'site-core' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Salva', 'site-core' ); ?></button>
					<?php if ( $hub ) : ?>
						<button type="submit" name="ricontrolla" value="1" class="button"><?php esc_html_e( 'Ricontrolla ora', 'site-core' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}
}

