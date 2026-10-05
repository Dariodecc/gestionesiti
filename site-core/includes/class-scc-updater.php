<?php
/**
 * Aggiornamenti di Site Core dall'hub.
 *
 * Fa comparire "Aggiornamento disponibile" nella pagina Plugin dei siti
 * clienti, senza passare dal repository di WordPress.org.
 *
 * COME FUNZIONA IL NOME DEL FILTRO
 * L'header `Update URI` nel file principale ha due effetti:
 *   1. dice a WordPress di NON cercare aggiornamenti su wordpress.org, cosi'
 *      un plugin omonimo pubblicato la' non sovrascrive il nostro;
 *   2. il suo HOSTNAME determina il nome del filtro: update_plugins_{hostname}.
 *
 * L'hostname e' quindi congelato nel codice e non si cambia dall'admin.
 * L'INDIRIZZO CHE INTERROGHIAMO invece si': sta in un'opzione. Cosi' il
 * servizio puo' spostarsi dove vuole, basta che l'hostname resti riservato
 * e risponda, anche solo con un rimando.
 *
 * @package site-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Updater {

	/**
	 * Hostname dichiarato in `Update URI`. NON si cambia dopo il rilascio:
	 * i plugin gia' installati cercherebbero un filtro che non esiste piu'
	 * e non vedrebbero mai piu' un aggiornamento.
	 */
	const HOST = 'gestionesiti.growbe.cloud';

	/** Indirizzo di base dell'API. Modificabile dall'admin. */
	const OPZIONE_URL = 'scc_hub_url';

	const FILE_PLUGIN  = 'site-core/site-core.php';
	const SLUG         = 'site-core';
	const CACHE        = 'scc_update_check';
	const CACHE_TTL    = 21600;
	const CACHE_TTL_KO = 1800;

	public static function init() {
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'controlla' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'dettagli' ), 20, 3 );
		// Cambiando l'indirizzo dell'hub la cache va buttata, altrimenti per
		// sei ore continua a interrogare il vecchio.
		add_action( 'update_option_' . self::OPZIONE_URL, array( __CLASS__, 'svuota_cache' ) );
	}

	private static function base() {
		$url = get_option( self::OPZIONE_URL );
		if ( ! $url ) {
			$url = 'https://' . self::HOST;
		}
		return untrailingslashit( esc_url_raw( $url ) );
	}

	/**
	 * Risponde a WordPress quando controlla gli aggiornamenti.
	 */
	public static function controlla( $update, $plugin_data, $plugin_file ) {
		// Il filtro scatta per OGNI plugin che dichiara questo hostname.
		if ( self::FILE_PLUGIN !== $plugin_file ) {
			return $update;
		}

		$remoto = self::interroga_hub();
		if ( ! is_array( $remoto ) || empty( $remoto['version'] ) || empty( $remoto['package'] ) ) {
			return $update;
		}

		// version_compare, non un confronto di stringhe: fra stringhe
		// '2.10.0' risulta minore di '2.9.0', ed e' sbagliato.
		if ( ! version_compare( $remoto['version'], $plugin_data['Version'], '>' ) ) {
			return $update;
		}

		return array(
			'id'           => self::HOST . '/' . self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $remoto['version'],
			'url'          => self::base() . '/' . self::SLUG,
			'package'      => $remoto['package'],
			'tested'       => isset( $remoto['tested'] ) ? $remoto['tested'] : '',
			'requires'     => isset( $remoto['requires'] ) ? $remoto['requires'] : '',
			'requires_php' => isset( $remoto['requires_php'] ) ? $remoto['requires_php'] : '',
		);
	}

	/**
	 * Il modale "Visualizza dettagli". Senza questo il link nella pagina
	 * Plugin chiede a wordpress.org, che non conosce il plugin, e da' errore.
	 */
	public static function dettagli( $risultato, $azione, $args ) {
		if ( 'plugin_information' !== $azione ) {
			return $risultato;
		}
		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $risultato;
		}

		$remoto = self::interroga_hub();
		if ( ! is_array( $remoto ) || empty( $remoto['version'] ) ) {
			return $risultato;
		}

		return (object) array(
			'name'          => 'Site Core',
			'slug'          => self::SLUG,
			'version'       => $remoto['version'],
			'author'        => isset( $remoto['author'] ) ? $remoto['author'] : '',
			'requires'      => isset( $remoto['requires'] ) ? $remoto['requires'] : '',
			'tested'        => isset( $remoto['tested'] ) ? $remoto['tested'] : '',
			'requires_php'  => isset( $remoto['requires_php'] ) ? $remoto['requires_php'] : '',
			'last_updated'  => isset( $remoto['last_updated'] ) ? $remoto['last_updated'] : '',
			'download_link' => isset( $remoto['package'] ) ? $remoto['package'] : '',
			'sections'      => array(
				'description' => isset( $remoto['description'] ) ? $remoto['description'] : '',
				'changelog'   => isset( $remoto['changelog'] ) ? $remoto['changelog'] : '',
			),
		);
	}

	/**
	 * Interroga l'hub. In cache sia il successo sia il fallimento.
	 *
	 * Mettere in cache il FALLIMENTO conta quanto l'altro: se l'hub e' giu'
	 * e non lo memorizzi, ogni caricamento della pagina Plugin aspetta il
	 * timeout e la bacheca diventa inutilizzabile.
	 */
	private static function interroga_hub() {
		$cache = get_site_transient( self::CACHE );
		if ( false !== $cache ) {
			return is_array( $cache ) ? $cache : null;
		}

		$risposta = wp_remote_get(
			self::base() . '/wp-json/scchub/v1/site-core/latest',
			array(
				'timeout'   => 8,
				'sslverify' => true,
				'headers'   => array(
					'Accept'        => 'application/json',
					'X-Licence-Key' => (string) get_option( 'scc_license_key' ),
					'X-Site-Url'    => home_url(),
				),
			)
		);

		if ( is_wp_error( $risposta ) || 200 !== (int) wp_remote_retrieve_response_code( $risposta ) ) {
			set_site_transient( self::CACHE, 'ko', self::CACHE_TTL_KO );
			return null;
		}

		$dati = json_decode( wp_remote_retrieve_body( $risposta ), true );
		if ( ! is_array( $dati ) ) {
			set_site_transient( self::CACHE, 'ko', self::CACHE_TTL_KO );
			return null;
		}

		set_site_transient( self::CACHE, $dati, self::CACHE_TTL );
		return $dati;
	}

	public static function svuota_cache() {
		delete_site_transient( self::CACHE );
	}
}

SCC_Updater::init();

