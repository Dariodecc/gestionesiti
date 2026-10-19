<?php
/**
 * Quello che il sito spedisce all'hub.
 *
 * Regola d'impianto, e non e' negoziabile: se l'hub e' giu', qui non
 * deve succedere niente. Il sito del cliente continua a funzionare
 * esattamente come prima, non rallenta, e non si riempiono i log di
 * errori. Ogni messaggio che non parte finisce in una coda breve e si
 * riprova al giro dopo; se non parte lo stesso, si butta.
 *
 * Nessun dato personale lascia il sito: del modulo si manda lo slug,
 * la fase e l'esito, mai il contenuto dell'invio.
 *
 * Un aggiornamento in coda dall'hub arriva SOLO dentro la risposta a
 * una richiesta che il sito ha gia' fatto lui (il battito). L'hub non
 * sa chiamare il sito dall'esterno e non deve: e' la stessa regola,
 * applicata anche agli aggiornamenti.
 *
 * @package site-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Hub {

	const CRON_BATTITO = 'scc_hub_battito';

	/** Messaggi non partiti, in attesa del prossimo giro. */
	const OPZIONE_CODA = 'scc_hub_coda';

	/** Stato dei fallimenti: quanti di fila e fino a quando si sta fermi. */
	const OPZIONE_KO = 'scc_hub_ko';

	/** Ultimo esito, solo per la schermata. */
	const OPZIONE_ULTIMO = 'scc_hub_ultimo';

	/**
	 * Un secondo hub, facoltativo, che riceve una copia degli stessi
	 * messaggi mandati al principale — pensato per far girare un hub
	 * nuovo in parallelo durante una migrazione, senza spostare quello
	 * che il sito usa davvero. Vedi manda_secondario().
	 */
	const OPZIONE_URL_SECONDARIO = 'scc_hub_url_secondario';

	/** Oltre questa lunghezza la coda si taglia: non deve crescere mai. */
	const MAX_CODA = 20;

	/** Timeout corti: il visitatore non deve aspettare l'hub. */
	const TIMEOUT        = 5;
	const TIMEOUT_ERRORE = 3;

public function __construct() {
		add_action( 'init', array( $this, 'pianifica' ) );
		// Il login automatico dall'hub: avvolto in try/catch dentro il
		// metodo stesso, cosi' un problema qui non puo' mai fermare il
		// resto del sito (questo hook gira su ogni singola richiesta).
		add_action( 'init', array( $this, 'controlla_login_hub' ), 1 );
		add_action( self::CRON_BATTITO, array( $this, 'giro' ) );
		// Il registro dei moduli: fase per fase, esito per esito.
		add_action( 'scc_form_log', array( $this, 'manda_modulo' ), 10, 5 );
		// Dopo un aggiornamento di plugin o tema.
		add_action( 'upgrader_process_complete', array( $this, 'manda_aggiornamento' ), 10, 2 );
		// L'errore fatale: e' il messaggio che vale piu' di tutti.
		add_action( 'plugins_loaded', array( $this, 'sorveglia_errori' ) );
		add_action( 'admin_menu', array( $this, 'registra_pagina' ), 31 );
		add_action( 'admin_post_scc_salva_hub', array( $this, 'salva' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Impianto                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * Lo stesso indirizzo che usa l'updater: l'hostname e' congelato nel
	 * codice, l'indirizzo effettivo sta in un'opzione e puo' spostarsi.
	 */
	public static function base(): string {
		$url = (string) get_option( 'scc_hub_url', '' );

		if ( '' === $url && class_exists( 'SCC_Updater' ) ) {
			$url = 'https://' . SCC_Updater::HOST;
		}

		return '' === $url ? '' : untrailingslashit( esc_url_raw( $url ) );
	}

	public static function chiave(): string {
		// La stessa chiave della licenza: una sola per cliente, non due.
		return (string) get_option( 'scc_license_key', '' );
	}

	/**
	 * Si spedisce solo se c'e' una chiave e un indirizzo. Senza, il
	 * modulo e' inerte: nessuna chiamata, nessun errore, nessun log.
	 */
	public static function attivo(): bool {
		return '' !== self::chiave() && '' !== self::base();
	}

	public static function url_secondario(): string {
		$url = (string) get_option( self::OPZIONE_URL_SECONDARIO, '' );
		return '' === $url ? '' : untrailingslashit( esc_url_raw( $url ) );
	}

	/**
	 * Manda una copia dello stesso messaggio a un secondo hub, se
	 * configurato. Pensata per un solo scopo: far ricevere dati reali a
	 * un hub nuovo mentre quello vero resta quello principale, senza
	 * spostare nulla e senza nessun rischio per il sito del cliente.
	 *
	 * Per questo e' volutamente piu' permissiva di manda():
	 * - nessuna coda, nessun conteggio di fallimenti, nessuna pausa:
	 *   se il secondario non risponde oggi, si ritenta al prossimo giro
	 *   naturale, non prima; non deve mai poter interferire con la
	 *   spedizione al hub principale;
	 * - non legge mai la risposta: un eventuale comando in coda là
	 *   dentro va ignorato. Un solo hub alla volta puo' mettere in coda
	 *   un aggiornamento per questo sito, ed e' quello principale;
	 * - qualunque errore sparisce qui: non tocca error_log, non tocca
	 *   la schermata Hub, non tocca la coda del principale.
	 */
	private static function manda_secondario( string $rotta, array $dati ): void {
		$url = self::url_secondario();
		if ( '' === $url || '' === self::chiave() ) {
			return;
		}
		try {
			wp_remote_post(
				$url . '/wp-json/scchub/v1/' . $rotta,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 2,
					'blocking'    => false, // fire-and-forget: non si aspetta e non si legge la risposta
					'headers'     => array(
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
						'X-Licence-Key' => self::chiave(),
						'X-Site-Url'    => home_url(),
					),
					'body'        => wp_json_encode( $dati ),
				)
			);
		} catch ( \Throwable $e ) {
			// Il secondario e' sempre e solo un extra: se fallisce, non
			// deve raccontarlo a nessuno ne' influenzare nient'altro.
		}
	}

	public function pianifica() {
		if ( ! self::attivo() ) {
			$q = wp_next_scheduled( self::CRON_BATTITO );
			if ( $q ) {
				wp_unschedule_event( $q, self::CRON_BATTITO );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_BATTITO ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_BATTITO );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Spedizione                                                        */
	/* ---------------------------------------------------------------- */

	/**
	 * Manda un messaggio all'hub.
	 *
	 * Non solleva mai un'eccezione e non scrive mai in error_log: un hub
	 * che non risponde e' una condizione prevista, non un guasto del sito.
	 */
	public static function manda( string $rotta, array $dati, bool $accoda = true, int $timeout = self::TIMEOUT ): bool {
		self::manda_secondario( $rotta, $dati );

		if ( ! self::attivo() ) {
			return false;
		}

		// Se l'hub ha appena smesso di rispondere non lo si martella:
		// si accoda e si riprova quando la pausa e' finita.
		if ( self::in_pausa() ) {
			if ( $accoda ) {
				self::accoda( $rotta, $dati );
			}
			return false;
		}

		$risposta = wp_remote_post(
			self::base() . '/wp-json/scchub/v1/' . $rotta,
			array(
				'timeout'     => $timeout,
				'redirection' => 2,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'X-Licence-Key' => self::chiave(),
					'X-Site-Url'    => home_url(),
				),
				'body'        => wp_json_encode( $dati ),
			)
		);

		$codice = is_wp_error( $risposta ) ? 0 : (int) wp_remote_retrieve_response_code( $risposta );

		if ( 200 === $codice ) {
			self::segna_ok();
			return true;
		}

		// 401, 403 e 429 non sono guasti dell'hub: sono risposte. Non
		// si riprova, altrimenti si accoda all'infinito una cosa che
		// verra' rifiutata anche domani.
		if ( in_array( $codice, array( 401, 403, 429 ), true ) ) {
			self::segna_esito( 'rifiutato ' . $codice );
			return false;
		}

		self::segna_ko();
		if ( $accoda ) {
			self::accoda( $rotta, $dati );
		}

		return false;
	}

	private static function in_pausa(): bool {
		$ko = get_option( self::OPZIONE_KO, array() );
		return ! empty( $ko['fino'] ) && time() < (int) $ko['fino'];
	}

	private static function segna_ko(): void {
		$ko = get_option( self::OPZIONE_KO, array() );
		$n  = (int) ( $ko['n'] ?? 0 ) + 1;

		// Pausa crescente, con un tetto: 5, 15, 45 minuti, poi due ore.
		$attese = array( 5, 15, 45, 120 );
		$attesa = $attese[ min( $n, count( $attese ) ) - 1 ];

		update_option(
			self::OPZIONE_KO,
			array(
				'n'    => $n,
				'fino' => time() + $attesa * MINUTE_IN_SECONDS,
			),
			false
		);

		self::segna_esito( 'non raggiungibile (tentativo ' . $n . ')' );
	}

	private static function segna_ok(): void {
		if ( get_option( self::OPZIONE_KO ) ) {
			delete_option( self::OPZIONE_KO );
		}
		self::segna_esito( 'ok' );
	}

	private static function segna_esito( string $esito ): void {
		update_option(
			self::OPZIONE_ULTIMO,
			array(
				'esito'  => $esito,
				'quando' => time(),
			),
			false
		);
	}

	/* ---------------------------------------------------------------- */
	/* Coda                                                              */
	/* ---------------------------------------------------------------- */

	private static function accoda( string $rotta, array $dati ): void {
		$coda = get_option( self::OPZIONE_CODA, array() );
		if ( ! is_array( $coda ) ) {
			$coda = array();
		}

		$coda[] = array(
			'rotta'  => $rotta,
			'dati'   => $dati,
			'quando' => time(),
		);

		// Si tengono gli ultimi: se l'hub e' giu' da ore, i messaggi
		// vecchi non interessano piu' a nessuno.
		if ( count( $coda ) > self::MAX_CODA ) {
			$coda = array_slice( $coda, -self::MAX_CODA );
		}

		update_option( self::OPZIONE_CODA, $coda, false );
	}

	private static function svuota_coda(): void {
		$coda = get_option( self::OPZIONE_CODA, array() );
		if ( ! is_array( $coda ) || ! $coda ) {
			return;
		}

		delete_option( self::OPZIONE_CODA );

		foreach ( $coda as $voce ) {
			if ( empty( $voce['rotta'] ) ) {
				continue;
			}
			// Piu' vecchio di un giorno: non lo si manda nemmeno.
			if ( ! empty( $voce['quando'] ) && ( time() - (int) $voce['quando'] ) > DAY_IN_SECONDS ) {
				continue;
			}
			if ( ! self::manda( $voce['rotta'], (array) $voce['dati'], true ) ) {
				// Il primo fallimento ferma il giro: gli altri sono gia'
				// tornati in coda dentro manda().
				break;
			}
		}
	}

	/* ---------------------------------------------------------------- */
	/* Battito                                                           */
	/* ---------------------------------------------------------------- */

	public function giro(): void {
		self::svuota_coda();
		$risposta = self::manda_battito();
		self::applica_comando_da_risposta( $risposta );
	}

	/**
	 * Come manda(), ma solo per il battito e leggendo il corpo della
	 * risposta: e' l'unico posto dove puo' essere arrivato un comando
	 * in coda dall'hub, dentro la risposta a una richiesta che il sito
	 * ha fatto lui. L'hub non chiama mai il sito dall'esterno.
	 */
	private static function manda_battito(): ?array {
		// Indipendente da tutto il resto della funzione: parte anche se il
		// principale e' fermo, in pausa, o la licenza e' scaduta.
		self::manda_secondario( 'battito', self::dati_battito() );

		if ( ! self::attivo() ) {
			return null;
		}

		// A licenza scaduta il battito non parte piu' -- e' l'unico segnale
		// che si ferma per questo. Errori fatali, registro moduli e conferma
		// di un aggiornamento continuano a raggiungere l'hub anche a licenza
		// non attiva: sono le informazioni che servono a evitare un disservizio,
		// non un privilegio legato alla licenza.
		if ( class_exists( 'SCC_License' ) && ! SCC_License::is_active() ) {
			return null;
		}

		if ( self::in_pausa() ) {
			self::accoda( 'battito', self::dati_battito() );
			return null;
		}

		$risposta = wp_remote_post(
			self::base() . '/wp-json/scchub/v1/battito',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'X-Licence-Key' => self::chiave(),
					'X-Site-Url'    => home_url(),
				),
				'body'        => wp_json_encode( self::dati_battito() ),
			)
		);

		$codice = is_wp_error( $risposta ) ? 0 : (int) wp_remote_retrieve_response_code( $risposta );

		if ( 200 === $codice ) {
			self::segna_ok();
			$corpo = json_decode( wp_remote_retrieve_body( $risposta ), true );
			return is_array( $corpo ) ? $corpo : array();
		}

		if ( in_array( $codice, array( 401, 403, 429 ), true ) ) {
			self::segna_esito( 'rifiutato ' . $codice );
			return null;
		}

		self::segna_ko();
		self::accoda( 'battito', self::dati_battito() );
		return null;
	}

	public static function dati_battito(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$attivi  = array();
		$tutti   = get_plugins();
		$accesi  = (array) get_option( 'active_plugins', array() );

		foreach ( $accesi as $file ) {
			if ( isset( $tutti[ $file ] ) ) {
				$slug             = dirname( $file );
				$slug             = ( '.' === $slug ) ? basename( $file, '.php' ) : $slug;
				$attivi[ $slug ]  = $tutti[ $file ]['Version'] ?? '';
			}
		}

		$tema = wp_get_theme();

		$dati = array(
			// Indirizzo pubblico del sito: l'hub lo legge al primo battito
			// cosi' chi crea il sito non deve scriverlo a mano.
			'url'       => home_url( '/' ),
			'wp'        => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'site_core' => defined( 'SCC_VERSION' ) ? SCC_VERSION : '',
			'tema'      => $tema->get( 'Name' ),
			'tema_versione' => $tema->get( 'Version' ),
			'plugin'    => $attivi,
		);

		$disco = @disk_free_space( ABSPATH ); // phpcs:ignore
		if ( $disco ) {
			$dati['disco'] = size_format( $disco ) . ' liberi';
		}

		// Il backup lo sa l'hosting, non WordPress. Se un plugin di
		// backup ha lasciato una traccia riconoscibile la si passa,
		// altrimenti si tace: meglio niente che una data inventata.
		$backup = apply_filters( 'scc_hub_ultimo_backup', '' );
		if ( $backup ) {
			$dati['ultimo_backup'] = $backup;
		}

		// Gli aggiornamenti disponibili: si leggono i transient di
		// WordPress cosi' come sono, senza forzare un controllo a ogni
		// battito. Un controllo fresco lo forza solo il pulsante "Manda
		// un battito adesso" nella schermata Hub.
		$dati['aggiornamenti'] = self::dati_aggiornamenti();

		// Solo id, login e nome: mai la password o il suo hash. Serve
		// all'hub per far scegliere a chi gestisce il sito con quale
		// amministratore entrare nella bacheca.
		$dati['utenti_admin'] = self::utenti_amministratori();

		// Tipi di contenuto che l'hub puo' proporre nel form "Nuovo
		// contenuto": articoli/pagine piu' i CPT gestiti da SiteCore via
		// SCC_MCP_Content_Model, con il tipo dichiarato di ciascun campo.
		$dati['tipi_contenuto'] = self::tipi_contenuto_per_battito();

		return apply_filters( 'scc_hub_dati_battito', $dati );
	}

	/**
	 * Non si inventano tipi per CPT esterni (WooCommerce, ACF, plugin di
	 * terzi): di quelli non conosciamo la forma dei campi, solo il nome
	 * esiste altrove. Si dichiarano solo articoli/pagine (nativi, sempre
	 * uguali) e i CPT che SiteCore stesso possiede e sa descrivere.
	 */
	private static function tipi_contenuto_per_battito(): array {
		if ( ! post_type_exists( 'post' ) ) {
			return array();
		}

		$mappa_tipo_campo = array(
			'string'  => 'string',
			'integer' => 'number',
			'number'  => 'number',
			'boolean' => 'checkbox',
		);

		// Formati di visualizzazione ammessi solo sui campi di tipo string
		// (vedi SCC_MCP_Content_Model::definitions): quando presente, il
		// formato descrive meglio il campo del tipo grezzo e l'hub lo usa
		// per scegliere il controllo giusto nel form contenuti.
		$formati_string_validi = array( 'text', 'textarea', 'wysiwyg', 'email', 'url', 'tel', 'date', 'datetime', 'time', 'color', 'select' );

		$tassonomie_per_tipo = static function ( string $post_type ): array {
			$out = array();
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
				if ( empty( $tax->public ) ) {
					continue;
				}
				$out[] = array( 'slug' => $tax->name, 'etichetta' => $tax->label );
			}
			return $out;
		};

		$tipi = array();

		foreach ( array( 'post', 'page' ) as $builtin ) {
			$oggetto = get_post_type_object( $builtin );
			if ( ! $oggetto ) {
				continue;
			}
			$supporta = array();
			foreach ( array( 'editor', 'excerpt' ) as $feature ) {
				if ( post_type_supports( $builtin, $feature ) ) {
					$supporta[] = $feature;
				}
			}
			$tipi[] = array(
				'slug'       => $builtin,
				'etichetta'  => $oggetto->labels->singular_name ?: $oggetto->label,
				'supports'   => $supporta,
				'campi'      => array(),
				'tassonomie' => $tassonomie_per_tipo( $builtin ),
			);
		}

		if ( class_exists( 'SCC_MCP_Content_Model' ) ) {
			$modello = SCC_MCP_Content_Model::read();
			foreach ( (array) ( $modello['types'] ?? array() ) as $slug => $definizione ) {
				if ( ! post_type_exists( $slug ) ) {
					continue; // non registrato in questa richiesta, o in conflitto: vedi wp_get_content_model
				}
				$campi = array();
				foreach ( (array) ( $modello['fields'][ $slug ] ?? array() ) as $chiave => $campo ) {
					$tipo_dichiarato = (string) ( $campo['type'] ?? 'string' );
					$formato         = (string) ( $campo['format'] ?? '' );
					$tipo            = ( 'string' === $tipo_dichiarato && in_array( $formato, $formati_string_validi, true ) )
						? $formato
						: ( $mappa_tipo_campo[ $tipo_dichiarato ] ?? 'string' );

					$voce = array(
						'chiave'    => $chiave,
						'etichetta' => $campo['label'] ?? $chiave,
						'tipo'      => $tipo,
					);
					if ( 'select' === $tipo && ! empty( $campo['choices'] ) && is_array( $campo['choices'] ) ) {
						$voce['scelte'] = $campo['choices'];
					}
					$campi[] = $voce;
				}
				$tipi[] = array(
					'slug'       => $slug,
					'etichetta'  => $definizione['singular_label'] ?? ( $definizione['label'] ?? $slug ),
					'supports'   => (array) ( $definizione['supports'] ?? array() ),
					'campi'      => $campi,
					'tassonomie' => $tassonomie_per_tipo( $slug ),
				);
			}
		}

		return $tipi;
	}

	/**
	 * Gli amministratori del sito, giusto quel poco che serve a farli
	 * scegliere dall'hub. Mai la password, mai l'hash: quelli restano
	 * dove devono restare, dentro il sito.
	 */
	private static function utenti_amministratori(): array {
		try {
			$utenti = get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 20,
					'fields'  => array( 'ID', 'user_login', 'display_name' ),
				)
			);

			$fuori = array();
			foreach ( $utenti as $u ) {
				$fuori[] = array(
					'id'    => (int) $u->ID,
					'login' => (string) $u->user_login,
					'nome'  => (string) $u->display_name,
				);
			}

			return $fuori;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Cosa risulta aggiornabile in questo momento: core, plugin, tema.
	 * Letto dagli stessi transient che usa la pagina Aggiornamenti della
	 * bacheca, quindi non costa niente in piu' di quello che WordPress
	 * calcola gia' da solo.
	 */
	private static function dati_aggiornamenti(): array {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$agg = array(
			'core'   => null,
			'plugin' => array(),
			'tema'   => array(),
		);

		$core_updates = get_core_updates();
		if ( is_array( $core_updates ) && isset( $core_updates[0] ) && 'upgrade' === $core_updates[0]->response ) {
			$agg['core'] = array(
				'da' => get_bloginfo( 'version' ),
				'a'  => $core_updates[0]->current,
			);
		}

		foreach ( get_plugin_updates() as $file => $dati ) {
			$agg['plugin'][] = array(
				'file' => $file,
				'nome' => (string) ( $dati->Name ?? $file ),
				'da'   => (string) ( $dati->Version ?? '' ),
				'a'    => (string) ( $dati->update->new_version ?? '' ),
			);
		}

		// cache_get() e' privato su WP_Theme: chiamarlo da qui manda in
		// errore fatale ogni sito con un aggiornamento tema disponibile
		// (Uncaught Error: Call to private method WP_Theme::cache_get()).
		// Lo stesso dato ('update') lo scrive get_theme_updates() nel
		// transient standard 'update_themes', via API pubblica.
		$transitorio_temi = get_site_transient( 'update_themes' );
		foreach ( get_theme_updates() as $stylesheet => $tema ) {
			$nuova_versione = $transitorio_temi->response[ $stylesheet ]['new_version'] ?? '';
			$agg['tema'][] = array(
				'stylesheet' => (string) $stylesheet,
				'nome'       => (string) $tema->get( 'Name' ),
				'da'         => (string) $tema->get( 'Version' ),
				'a'          => (string) $nuova_versione,
			);
		}

		return $agg;
	}

	/* ---------------------------------------------------------------- */
	/* Applicare un aggiornamento in coda                                */
	/* ---------------------------------------------------------------- */

	/**
	 * Se la risposta al battito porta un comando, lo si applica. Un
	 * solo comando alla volta: e' quello che l'hub mette in coda quando
	 * si clicca "aggiorna" su una riga sola.
	 */
	private static function applica_comando_da_risposta( ?array $risposta ): void {
		if ( empty( $risposta['comando'] ) || ! is_array( $risposta['comando'] ) ) {
			return;
		}
		self::applica_comando( $risposta['comando'] );
	}

	/**
	 * Aggiorna un plugin, un tema o il core, con la stessa classe e la
	 * stessa skin silenziosa che usa l'aggiornamento automatico di
	 * WordPress in background: nessun prompt, nessuna richiesta di
	 * credenziali FTP se il filesystem scrive gia' diretto (il caso
	 * normale su questo hosting).
	 *
	 * Il successo lo racconta gia' da solo l'hook 'upgrader_process_complete'
	 * qui sotto (manda_aggiornamento): e' lo stesso hook che scatta per
	 * un aggiornamento fatto a mano dalla bacheca, perche' bulk_upgrade()
	 * e Core_Upgrader::upgrade() lo fanno scattare esattamente allo
	 * stesso modo. Da qui si manda un messaggio in piu' solo se e' andata
	 * male, perche' altrimenti quel fallimento non lo saprebbe nessuno.
	 */
	private static function applica_comando( array $comando ): void {
		// Collegamento automatico di OpenCode (HUB, attivatore sviluppo acceso): non e' un aggiornamento, si gestisce a parte.
		if ( 'opencode_link' === (string) ( $comando['tipo'] ?? '' ) ) {
			if ( class_exists( 'SCC_MCP_Setup' ) ) {
				SCC_MCP_Setup::apply_hub_link( $comando );
			}
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$tipo  = (string) ( $comando['tipo'] ?? '' );
		$skin  = new Automatic_Upgrader_Skin();
		$cosa  = '';
		$ok    = false;
		$causa = '';

		if ( 'plugin' === $tipo && ! empty( $comando['file'] ) ) {
			$file      = (string) $comando['file'];
			$cosa      = ! empty( $comando['nome'] ) ? (string) $comando['nome'] : $file;
			$upgrader  = new Plugin_Upgrader( $skin );
			$risultati = $upgrader->bulk_upgrade( array( $file ) );
			// bulk_upgrade() non restituisce mai il booleano true per un esito
			// riuscito: restituisce un array di dettagli (WP_Upgrader::run()).
			// true letterale esce solo nel caso 'gia\' aggiornato'. Un esito e'
			// negativo solo se e' WP_Error o false (fs_connect fallita).
			$voce      = $risultati[ $file ] ?? null;
			$ok        = is_array( $risultati ) && array_key_exists( $file, $risultati ) && false !== $voce && ! is_wp_error( $voce );
			if ( ! $ok && is_wp_error( $voce ) ) {
				$causa = $voce->get_error_message();
			}
		} elseif ( 'tema' === $tipo && ! empty( $comando['stylesheet'] ) ) {
			$stylesheet = (string) $comando['stylesheet'];
			$cosa       = ! empty( $comando['nome'] ) ? (string) $comando['nome'] : $stylesheet;
			$upgrader   = new Theme_Upgrader( $skin );
			$risultati  = $upgrader->bulk_upgrade( array( $stylesheet ) );
			// Stessa correzione del ramo plugin qui sopra: true letterale non
			// arriva mai su un aggiornamento riuscito.
			$voce       = $risultati[ $stylesheet ] ?? null;
			$ok         = is_array( $risultati ) && array_key_exists( $stylesheet, $risultati ) && false !== $voce && ! is_wp_error( $voce );
			if ( ! $ok && is_wp_error( $voce ) ) {
				$causa = $voce->get_error_message();
			}
		} elseif ( 'core' === $tipo ) {
			$cosa          = 'WordPress';
			$aggiornamenti = get_core_updates();
			if ( is_array( $aggiornamenti ) && isset( $aggiornamenti[0] ) && 'upgrade' === $aggiornamenti[0]->response ) {
				$upgrader = new Core_Upgrader( $skin );
				$esito    = $upgrader->upgrade( $aggiornamenti[0] );
				$ok       = true === $esito;
				if ( ! $ok && is_wp_error( $esito ) ) {
					$causa = $esito->get_error_message();
				}
			} else {
				$causa = 'nessun aggiornamento del core risulta piu\' disponibile';
			}
		} else {
			return;
		}

		if ( ! $ok ) {
			if ( '' === $causa && method_exists( $skin, 'get_errors' ) ) {
				$errori = $skin->get_errors();
				if ( is_wp_error( $errori ) && $errori->has_errors() ) {
					$causa = $errori->get_error_message();
				}
			}
			self::manda(
				'aggiornamento',
				array(
					'cosa'      => $cosa,
					'da'        => '',
					'a'         => '',
					'esito'     => 'errore',
					'messaggio' => $causa ? mb_substr( $causa, 0, 200 ) : 'aggiornamento non riuscito',
				),
				false
			);
		}
		// Il successo lo racconta gia' 'upgrader_process_complete': mandarlo
		// anche da qui lo farebbe comparire due volte nella cronologia.
	}

	/* ---------------------------------------------------------------- */
	/* Moduli                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * Una riga di registro per ogni fase di ogni invio.
	 *
	 * Il messaggio che arriva qui e' gia' tecnico (esito di un controllo),
	 * ma per sicurezza non si spedisce mai il contenuto dei campi.
	 */
	public function manda_modulo( $entry_id, $slug, $fase, $esito, $messaggio ): void {
		if ( ! self::attivo() ) {
			return;
		}

		$traduzione = array(
			'pass' => 'ok',
			'fail' => 'errore',
		);

		// Un controllo di sicurezza che scarta un invio non e' un guasto
		// del modulo: e' il modulo che fa il suo lavoro.
		$scartati = array( 'honeypot', 'time_trap', 'rate_limit', 'nonce' );
		$normale  = $traduzione[ $esito ] ?? $esito;

		if ( 'errore' === $normale && in_array( $fase, $scartati, true ) ) {
			$normale = 'scartato';
		}

		self::manda(
			'modulo',
			array(
				'slug'      => (string) $slug,
				'fase'      => (string) $fase,
				'esito'     => $normale,
				'messaggio' => mb_substr( (string) $messaggio, 0, 200 ),
			)
		);
	}

	/* ---------------------------------------------------------------- */
	/* Aggiornamenti                                                     */
	/* ---------------------------------------------------------------- */

	public function manda_aggiornamento( $upgrader, $info ): void {
		if ( ! self::attivo() || empty( $info['action'] ) || 'update' !== $info['action'] ) {
			return;
		}

		// Per un aggiornamento singolo (il nostro comando in coda, o un
		// click sulla bacheca su una riga sola) $upgrader->result e' il
		// risultato di quel preciso upgrader appena finito: se e' un errore
		// non si manda un 'ok' che lo contraddirebbe. Per un bulk con piu'
		// elementi $upgrader->result e' solo l'ultimo processato, quindi qui
		// non si puo' isolare l'esito di ognuno: si resta permissivi, come
		// prima.
		if ( isset( $upgrader->result ) && ( is_wp_error( $upgrader->result ) || false === $upgrader->result ) ) {
			return;
		}

		$cosa = '';
		if ( ! empty( $info['plugins'] ) ) {
			$cosa = implode( ', ', array_map( 'dirname', (array) $info['plugins'] ) );
		} elseif ( ! empty( $info['themes'] ) ) {
			$cosa = implode( ', ', (array) $info['themes'] );
		} elseif ( 'core' === ( $info['type'] ?? '' ) ) {
			$cosa = 'WordPress';
		}

		if ( '' === $cosa ) {
			return;
		}

		self::manda(
			'aggiornamento',
			array(
				'cosa' => $cosa,
				'da'   => '',
				'a'    => '',
				'esito' => 'ok',
			)
		);
	}

	/* ---------------------------------------------------------------- */
	/* Errori fatali                                                     */
	/* ---------------------------------------------------------------- */

	public function sorveglia_errori(): void {
		if ( ! self::attivo() ) {
			return;
		}
		register_shutdown_function( array( __CLASS__, 'a_fine_richiesta' ) );
	}

	public static function a_fine_richiesta(): void {
		$e = error_get_last();

		if ( ! $e ) {
			return;
		}

		$fatali = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( (int) $e['type'], $fatali, true ) ) {
			return;
		}

		// Lo stesso errore su ogni richiesta diventerebbe una raffica.
		// Si manda una volta ogni dieci minuti e basta.
		$impronta = 'scc_hub_err_' . md5( $e['file'] . '|' . $e['line'] . '|' . $e['message'] );
		if ( get_transient( $impronta ) ) {
			return;
		}
		set_transient( $impronta, 1, 10 * MINUTE_IN_SECONDS );

		// Percorso relativo: il percorso assoluto del server non serve
		// a nessuno e dice piu' del dovuto.
		$file = str_replace( ABSPATH, '', (string) $e['file'] );

		self::manda(
			'errore',
			array(
				'tipo'      => 'E_ERROR',
				'file'      => $file,
				'riga'      => (int) $e['line'],
				'messaggio' => mb_substr( (string) $e['message'], 0, 300 ),
			),
			false, // non si accoda: se il sito e' rotto, riprovare non aiuta
			self::TIMEOUT_ERRORE
		);
	}

	/* ---------------------------------------------------------------- */
	/* Schermata                                                         */
	/* ---------------------------------------------------------------- */

	public function registra_pagina(): void {
		add_submenu_page(
			'scc-entries',
			__( 'Hub', 'site-core' ),
			__( 'Hub', 'site-core' ),
			'manage_options',
			'scc-hub',
			array( $this, 'render' )
		);
	}

	public function salva(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'site-core' ) );
		}
		check_admin_referer( 'scc_salva_hub' );

		if ( isset( $_POST['scc_license_key'] ) ) {
			update_option( 'scc_license_key', sanitize_text_field( wp_unslash( $_POST['scc_license_key'] ) ) );
		}

		if ( isset( $_POST['scc_hub_url'] ) ) {
			$url = trim( (string) wp_unslash( $_POST['scc_hub_url'] ) );
			if ( '' === $url ) {
				delete_option( 'scc_hub_url' );
			} else {
				update_option( 'scc_hub_url', esc_url_raw( $url ) );
			}
		}

		if ( isset( $_POST[ self::OPZIONE_URL_SECONDARIO ] ) ) {
			$url = trim( (string) wp_unslash( $_POST[ self::OPZIONE_URL_SECONDARIO ] ) );
			if ( '' === $url ) {
				delete_option( self::OPZIONE_URL_SECONDARIO );
			} else {
				update_option( self::OPZIONE_URL_SECONDARIO, esc_url_raw( $url ) );
			}
		}

		// Una chiave nuova merita un tentativo subito, non fra un'ora.
		delete_option( self::OPZIONE_KO );
		$this->pianifica();

		$args = array();

		if ( isset( $_POST['manda_ora'] ) ) {
			// Un controllo fresco, non quello che ha lasciato l'ultimo
			// giro di WP-Cron: e' un click esplicito, vale la spesa.
			if ( ! function_exists( 'wp_version_check' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			wp_version_check( array(), true );
			wp_update_plugins();
			wp_update_themes();

			$risposta = self::manda_battito();
			self::applica_comando_da_risposta( $risposta );

			$args['mandato'] = 1;
			if ( ! empty( $risposta['comando'] ) ) {
				$args['comando'] = 1;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=scc-hub&salvato=1' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ultimo = get_option( self::OPZIONE_ULTIMO, array() );
		$coda   = (array) get_option( self::OPZIONE_CODA, array() );
		$ko     = get_option( self::OPZIONE_KO, array() );
		$next   = wp_next_scheduled( self::CRON_BATTITO );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hub', 'site-core' ); ?></h1>

			<?php if ( ! empty( $_GET['salvato'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Salvato.', 'site-core' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['mandato'] ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p>
						<?php
						if ( ! empty( $_GET['comando'] ) ) {
							esc_html_e( 'Battito mandato: c\'era un aggiornamento in coda dall\'hub, applicato adesso.', 'site-core' );
						} else {
							esc_html_e( 'Battito mandato. Nessun aggiornamento in coda dall\'hub in questo momento.', 'site-core' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<p style="max-width:720px">
				<?php esc_html_e( 'Il sito manda all\'hub un battito ogni ora, gli errori fatali appena succedono, e una riga di registro per ogni invio dei moduli. Non esce nessun contenuto degli invii: solo quale modulo, quando, quale fase e che esito. Il battito porta anche quali aggiornamenti di WordPress, plugin e tema risultano disponibili: se dall\'hub ne metti uno in coda, questo sito lo applica lui al battito successivo, o subito se clicchi \"Manda un battito adesso\" qui sotto.', 'site-core' ); ?>
			</p>

			<table class="widefat striped" style="max-width:720px;margin-bottom:20px">
				<tbody>
					<tr>
						<th style="width:200px"><?php esc_html_e( 'Spedizione', 'site-core' ); ?></th>
						<td>
							<strong style="color:<?php echo self::attivo() ? '#1a7f37' : '#8a6d00'; ?>">
								<?php echo self::attivo() ? esc_html__( 'Attiva', 'site-core' ) : esc_html__( 'Ferma: manca la chiave o l\'indirizzo', 'site-core' ); ?>
							</strong>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Ultimo esito', 'site-core' ); ?></th>
						<td>
							<?php
							if ( empty( $ultimo['quando'] ) ) {
								esc_html_e( 'mai spedito', 'site-core' );
							} else {
								echo esc_html( $ultimo['esito'] . ' — ' . human_time_diff( (int) $ultimo['quando'] ) . ' fa' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Prossimo battito', 'site-core' ); ?></th>
						<td><?php echo $next ? esc_html( human_time_diff( $next ) ) : esc_html__( 'non pianificato', 'site-core' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'In coda', 'site-core' ); ?></th>
						<td>
							<?php
							echo esc_html( count( $coda ) );
							if ( ! empty( $ko['fino'] ) && time() < (int) $ko['fino'] ) {
								echo ' — ' . esc_html( sprintf( /* translators: %s: tempo */ __( 'in pausa, riprova fra %s', 'site-core' ), human_time_diff( (int) $ko['fino'] ) ) );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scc_salva_hub' ); ?>
				<input type="hidden" name="action" value="scc_salva_hub" />

				<table class="form-table">
					<tr>
						<th scope="row"><label for="scc_license_key"><?php esc_html_e( 'Chiave', 'site-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="scc_license_key" name="scc_license_key" value="<?php echo esc_attr( self::chiave() ); ?>" />
							<p class="description"><?php esc_html_e( 'La stessa chiave della licenza: te la da\' l\'hub quando crei il cliente, e si vede una volta sola.', 'site-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="scc_hub_url"><?php esc_html_e( 'Indirizzo dell\'hub', 'site-core' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="scc_hub_url" name="scc_hub_url" value="<?php echo esc_attr( (string) get_option( 'scc_hub_url', '' ) ); ?>" placeholder="<?php echo esc_attr( 'https://' . SCC_Updater::HOST ); ?>" />
							<p class="description"><?php esc_html_e( 'Da lasciare vuoto quasi sempre: si usa quello predefinito. Serve solo se il servizio si sposta.', 'site-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPZIONE_URL_SECONDARIO ); ?>"><?php esc_html_e( 'Indirizzo secondario (facoltativo)', 'site-core' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="<?php echo esc_attr( self::OPZIONE_URL_SECONDARIO ); ?>" name="<?php echo esc_attr( self::OPZIONE_URL_SECONDARIO ); ?>" value="<?php echo esc_attr( self::url_secondario() ); ?>" placeholder="https://gestionesiti-dev.growbe.cloud" />
							<p class="description"><?php esc_html_e( 'Una copia di ogni messaggio (battito, moduli, errori, aggiornamenti) va anche qui, in piu\' rispetto all\'hub principale — mai al posto suo. Nessun comando in coda viene mai letto da qui, e un mancato invio a questo indirizzo non influisce in nessun modo sull\'hub principale. Pensato per far ricevere dati reali a un hub nuovo durante una migrazione.', 'site-core' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Salva', 'site-core' ); ?></button>
					<button type="submit" name="manda_ora" value="1" class="button"><?php esc_html_e( 'Manda un battito adesso', 'site-core' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Login automatico dall'hub                                        */
	/* ---------------------------------------------------------------- */

	/**
	 * Se nell'URL c'e' il token che genera l'hub, si prova il login.
	 * Tutto e' avvolto in try/catch: questo hook gira su 'init' a ogni
	 * richiesta, quindi un errore qui non deve mai poter mandare in
	 * errore critico il resto del sito (e' successo una volta, per un
	 * motivo diverso, e non deve poter succedere di nuovo per questo).
	 */
	public function controlla_login_hub(): void {
		try {
			if ( is_user_logged_in() || empty( $_GET['scc_hub_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return;
			}

			$token  = sanitize_text_field( wp_unslash( $_GET['scc_hub_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$utente = self::verifica_token_login( $token );

			if ( ! $utente ) {
				return;
			}

			wp_set_current_user( $utente->ID );
			wp_set_auth_cookie( $utente->ID, false );
			do_action( 'wp_login', $utente->user_login, $utente );

			wp_safe_redirect( admin_url() );
			exit;
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Verifica il token firmato che manda l'hub: stessa forma generata
	 * da scchub_azione_accedi_bacheca() sull'hub --
	 * base64url(scadenza|nonce|login) seguito da '.' e dalla firma
	 * hmac-sha256, calcolata con l'impronta della chiave di licenza
	 * (mai la chiave in chiaro, e mai una password: qui non ne serve
	 * nessuna, ne' quella dell'utente ne' quella del sito).
	 *
	 * Nessun ripiego: se l'hub non ha indicato un utente, o quello
	 * indicato non e' (piu') un amministratore valido, il login non
	 * parte proprio. Non si sceglie un amministratore diverso al
	 * posto suo -- se non si puo' entrare con l'account richiesto,
	 * meglio non entrare affatto.
	 *
	 * Ogni nonce vale una volta sola: appena verificato si segna con
	 * un transient, cosi' lo stesso link non rifa' login una seconda
	 * volta se viene riaperto o ricondiviso.
	 */
	private static function verifica_token_login( string $token ): ?WP_User {
		try {
			$chiave = self::chiave();
			if ( '' === $chiave || false === strpos( $token, '.' ) ) {
				return null;
			}

			list( $corpo, $firma ) = explode( '.', $token, 2 );

			$payload = base64_decode( strtr( $corpo, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $payload || '' === $payload ) {
				return null;
			}

			// Stessa impronta che calcola l'hub quando crea la chiave:
			// hash('sha256', 'scchub|' . $chiave). Qui non arriva mai
			// la chiave in chiaro dell'hub, solo la propria, e si rifa'
			// lo stesso calcolo per ottenere la stessa impronta.
			$chiave_hash = hash( 'sha256', 'scchub|' . $chiave );
			$attesa      = hash_hmac( 'sha256', $payload, $chiave_hash );

			if ( ! hash_equals( $attesa, $firma ) ) {
				return null;
			}

			$pezzi    = array_pad( explode( '|', $payload, 3 ), 3, '' );
			$scadenza = (int) $pezzi[0];
			$nonce    = (string) $pezzi[1];
			$login    = (string) $pezzi[2];

			if ( $scadenza < time() || '' === $nonce ) {
				return null;
			}

			$marchio = 'scc_hub_login_' . md5( $nonce );
			if ( get_transient( $marchio ) ) {
				return null;
			}
			set_transient( $marchio, 1, 5 * MINUTE_IN_SECONDS );

			// Nessun ripiego su "il primo amministratore che c'e'":
			// se l'hub non ha indicato un login, o l'utente indicato
			// non esiste piu' o non e' un amministratore, il login
			// non si fa. Punto.
			if ( '' === $login ) {
				return null;
			}

			// v2.15.0: l'accesso MCP e' un interruttore per sito, non piu'
			// un elenco di account. Di conseguenza il login automatico non
			// esclude piu' l'account con sitecore_mcp_access: sarebbe stato
			// impossibile far entrare chiunque, dato che con l'MCP abilitato
			// ogni amministratore del sito risulta autorizzato.
			$utente = get_user_by( 'login', $login );
			if ( ! $utente || ! user_can( $utente, 'manage_options' ) ) {
				return null;
			}

			return $utente instanceof WP_User ? $utente : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
