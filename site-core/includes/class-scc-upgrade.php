<?php
/**
 * Aggiornamenti di Site Core.
 *
 * Sostituisce SCC_Install::maybe_upgrade(), che sapeva fare solo una cosa:
 * rieseguire dbDelta quando la versione cambiava. Va bene per aggiungere una
 * colonna o una tabella, non basta per spostare dei dati.
 *
 * Qui i passaggi sono espliciti e ordinati, e la versione nel database viene
 * scritta SOLO se tutti i passaggi sono andati a termine.
 *
 * @package site-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Upgrade {

	const OPZIONE_VERSIONE = 'scc_db_version';
	const LUCCHETTO        = 'scc_upgrade_lock';
	const LUCCHETTO_TTL    = 300;

	/**
	 * Elenco dei passaggi. Chiave = versione che li introduce.
	 * Si aggiunge in fondo, mai in mezzo. Una voce gia' rilasciata non si
	 * modifica piu': se era sbagliata si aggiunge un passaggio che corregge.
	 */
	private static function passaggi() {
		return array(
			'2.2.0' => 'passaggio_2_2_0',
			'2.8.0' => 'passaggio_2_8_0',
		);
	}

	public static function esegui() {
		$installata = get_option( self::OPZIONE_VERSIONE, '0' );

		if ( $installata === SCC_VERSION ) {
			return;
		}

		// Database piu' avanti del codice: e' un downgrade del plugin.
		// Non si tocca niente: rieseguire le migrazioni al contrario e' il
		// modo piu' rapido di perdere dati.
		if ( version_compare( $installata, SCC_VERSION, '>' ) ) {
			self::log( 'Versione nel database (' . $installata . ') piu recente del codice (' . SCC_VERSION . '): nessuna migrazione.' );
			return;
		}

		// Lucchetto: due richieste in contemporanea non devono migrare insieme.
		if ( get_transient( self::LUCCHETTO ) ) {
			return;
		}
		set_transient( self::LUCCHETTO, 1, self::LUCCHETTO_TTL );

		try {
			if ( class_exists( 'SCC_Install' ) ) {
				SCC_Install::create_tables();
			}

			foreach ( self::passaggi() as $versione => $metodo ) {
				if ( version_compare( $installata, $versione, '>=' ) ) {
					continue;
				}
				if ( ! method_exists( __CLASS__, $metodo ) ) {
					throw new Exception( 'Passaggio ' . $versione . ': metodo ' . $metodo . ' assente.' );
				}
				self::log( 'Avvio passaggio ' . $versione . '.' );
				call_user_func( array( __CLASS__, $metodo ) );
				self::log( 'Passaggio ' . $versione . ' completato.' );
			}

			// La versione si scrive SOLO qui. Scriverla prima e poi fallire
			// lascia il sito rotto convinto di essere a posto.
			update_option( self::OPZIONE_VERSIONE, SCC_VERSION );
			delete_option( 'scc_upgrade_error' );
			self::log( 'Aggiornamento a ' . SCC_VERSION . ' completato.' );

		} catch ( Throwable $e ) {
			// Versione non scritta: al prossimo caricamento si riprova.
			// Per questo ogni passaggio deve essere idempotente.
			self::log( 'Aggiornamento interrotto: ' . $e->getMessage() );
			self::segnala_errore( $e->getMessage() );
		}

		delete_transient( self::LUCCHETTO );
	}

	/**
	 * 2.2.0 - La chiave degli override dei testi porta la lingua in testa.
	 * Da `pagina:sezione:campo` a `lingua:pagina:sezione:campo`.
	 * Su un sito monolingua non fa niente.
	 * Idempotente: una chiave gia' qualificata viene riconosciuta e saltata.
	 */
	private static function passaggio_2_2_0() {
        // GUARDIA. Rinominare le chiavi senza che il lato lettura sappia
        // cercarle col prefisso farebbe sparire i testi dalla pagina.
        // Finche' SCC_Editable_Text non espone supporta_lingua(), questo
        // passaggio non fa niente: la riscrittura vera arriva nella versione
        // che introduce la lettura per lingua.
        if ( ! method_exists( 'SCC_Editable_Text', 'supporta_lingua' ) ) {
            self::log( '2.2.0: lato lettura non pronto, riscrittura chiavi rimandata.' );
            return;
        }

		$lingue = self::lingue_attive();
		if ( count( $lingue ) < 2 ) {
			self::log( '2.2.0: sito monolingua, nessuna chiave da riscrivere.' );
			return;
		}

		$default = self::lingua_default();
		if ( ! $default ) {
			throw new Exception( 'Lingua di default non determinabile: migrazione rinviata.' );
		}

		$override = get_option( 'scc_text_overrides', array() );
		if ( ! is_array( $override ) || empty( $override ) ) {
			self::log( '2.2.0: nessun override da riscrivere.' );
			return;
		}

		$nuovi     = array();
		$riscritte = 0;

		foreach ( $override as $chiave => $valore ) {
			$parti = explode( ':', (string) $chiave, 2 );

			if ( isset( $parti[0] ) && in_array( $parti[0], $lingue, true ) ) {
				$nuovi[ $chiave ] = $valore;
				continue;
			}

			$nuova = $default . ':' . $chiave;

			// Se la versione qualificata esiste gia', quella vince.
			if ( ! isset( $override[ $nuova ] ) ) {
				$nuovi[ $nuova ] = $valore;
				$riscritte++;
			}
		}

		if ( $riscritte > 0 ) {
			// Copia di sicurezza: se va storto i testi sono recuperabili a mano.
			update_option( 'scc_text_overrides_backup_2_1_0', $override, false );
			update_option( 'scc_text_overrides', $nuovi, false );
		}

		self::log( '2.2.0: ' . $riscritte . ' chiavi riscritte con il prefisso ' . $default . '.' );
	}

	/**
	 * 2.8.0 - Il ruolo "developer" arriva anche sui siti gia' attivi, non
	 * solo su un'attivazione nuova (register_activation_hook non gira di
	 * nuovo a un aggiornamento). Si crea il ruolo e basta: assegnarlo a
	 * qualcuno qui non avrebbe un "chi lo sta installando" a cui
	 * affidarsi , un aggiornamento parte dal battito verso l'hub, non da
	 * un click di un amministratore specifico.
	 */
	private static function passaggio_2_8_0() {
		if ( class_exists( 'SCC_Install' ) ) {
			SCC_Install::crea_ruolo_developer();
		}
	}
	/** Lingue attive: strato del tema, poi i plugin, poi una sola lingua. */
	private static function lingue_attive() {
		if ( function_exists( 'mama_lingue' ) ) {
			return (array) mama_lingue();
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			return (array) pll_languages_list( array( 'fields' => 'slug' ) );
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$attive = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $attive ) ) {
				return array_keys( $attive );
			}
		}
		return array( substr( get_locale(), 0, 2 ) );
	}

	private static function lingua_default() {
		if ( function_exists( 'mama_lingua_default' ) ) {
			return mama_lingua_default();
		}
		if ( function_exists( 'pll_default_language' ) ) {
			return pll_default_language( 'slug' );
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return apply_filters( 'wpml_default_language', null );
		}
		return substr( get_locale(), 0, 2 );
	}

	/**
	 * Registro nel database, non su file: su hosting condiviso i log su
	 * file si perdono e non si trovano. Ultime 50 righe.
	 */
	private static function log( $messaggio ) {
		$righe = get_option( 'scc_upgrade_log', array() );
		if ( ! is_array( $righe ) ) {
			$righe = array();
		}
		$righe[] = array(
			'quando' => current_time( 'mysql' ),
			'da'     => get_option( self::OPZIONE_VERSIONE, '0' ),
			'a'      => SCC_VERSION,
			'testo'  => (string) $messaggio,
		);
		if ( count( $righe ) > 50 ) {
			$righe = array_slice( $righe, -50 );
		}
		update_option( 'scc_upgrade_log', $righe, false );
	}

	private static function segnala_errore( $messaggio ) {
		update_option( 'scc_upgrade_error', array(
			'quando'   => current_time( 'mysql' ),
			'versione' => SCC_VERSION,
			'testo'    => (string) $messaggio,
		), false );
	}

	/** Senza questo avviso il fallimento e' silenzioso e si scopre tardi. */
	public static function avviso_admin() {
		$errore = get_option( 'scc_upgrade_error' );
		if ( ! $errore || ! is_array( $errore ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><em>%s</em></p></div>',
			esc_html__( 'Site Core: aggiornamento non completato.', 'site-core' ),
			esc_html( $errore['testo'] ),
			esc_html( 'Rilevato il ' . $errore['quando'] . '. Il plugin riprovera al prossimo caricamento.' )
		);
	}
}

add_action( 'plugins_loaded', array( 'SCC_Upgrade', 'esegui' ), 5 );
add_action( 'admin_notices', array( 'SCC_Upgrade', 'avviso_admin' ) );

