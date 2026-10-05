<?php
/**
 * Cartelle per la Libreria media.
 *
 * WordPress non ha cartelle sui media: una tassonomia gerarchica sugli
 * allegati e' il modo nativo di ottenerle senza inventare uno storage
 * parallelo. Creare/rinominare/cancellare cartelle usa lo schermo nativo
 * di WordPress per i termini (edit-tags.php) , non serve reinventarlo.
 *
 * Una cartella per allegato, non piu' di una: e' una cartella, non un
 * tag. wp_set_object_terms() viene sempre chiamata con append=false.
 *
 * @package site-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Media_Folders {

	const TASSONOMIA = 'scc_media_folder';

	public function __construct() {
		add_action( 'init', array( $this, 'registra_tassonomia' ) );
		add_action( 'admin_menu', array( $this, 'registra_voce_menu' ), 32 );

		// Vista Elenco della Libreria media: tendina di filtro + colonna
		// (la colonna la aggiunge gia' show_admin_column da sola).
		add_action( 'restrict_manage_posts', array( $this, 'filtro_elenco' ) );
		add_action( 'parse_query', array( $this, 'applica_filtro_elenco' ) );

		// Campo "Cartella" nel pannello dettagli: compare sia nella vista a
		// due colonne della Griglia (via get_compat_media_markup) sia nello
		// schermo classico "Modifica media".
		add_filter( 'attachment_fields_to_edit', array( $this, 'campo_cartella' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'salva_campo_cartella' ), 10, 2 );

		// Griglia: tendina di filtro nella toolbar (JS) + query AJAX filtrata di conseguenza.
		add_action( 'wp_enqueue_media', array( $this, 'carica_script_griglia' ) );
		add_filter( 'ajax_query_attachments_args', array( $this, 'applica_filtro_griglia' ) );

		add_action( 'wp_ajax_scc_media_folder_create', array( $this, 'ajax_crea_cartella' ) );
		add_action( 'wp_ajax_scc_media_folder_update', array( $this, 'ajax_aggiorna_cartella' ) );
		add_action( 'wp_ajax_scc_media_folder_delete', array( $this, 'ajax_elimina_cartella' ) );
		add_action( 'wp_ajax_scc_media_folder_assign', array( $this, 'ajax_assegna_media' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Tassonomia                                                        */
	/* ---------------------------------------------------------------- */

	public function registra_tassonomia() {
		register_taxonomy(
			self::TASSONOMIA,
			'attachment',
			array(
				'labels'            => array(
					'name'          => __( 'Cartelle', 'site-core' ),
					'singular_name' => __( 'Cartella', 'site-core' ),
					'menu_name'     => __( 'Cartelle media', 'site-core' ),
					'add_new_item'  => __( 'Nuova cartella', 'site-core' ),
					'edit_item'     => __( 'Modifica cartella', 'site-core' ),
					'parent_item'   => __( 'Cartella padre', 'site-core' ),
					'search_items'  => __( 'Cerca cartelle', 'site-core' ),
					'not_found'     => __( 'Nessuna cartella.', 'site-core' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => self::TASSONOMIA,
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'upload_files',
					'edit_terms'   => 'upload_files',
					'delete_terms' => 'upload_files',
					'assign_terms' => 'upload_files',
				),
			)
		);
	}

	/**
	 * Link nel menu di Site Core allo schermo nativo dei termini: la
	 * tassonomia e' su 'attachment', che non ha un menu proprio in
	 * bacheca, quindi WordPress non ci mette da solo una voce.
	 */
	public function registra_voce_menu() {
		add_submenu_page(
			'scc-entries',
			__( 'Cartelle media', 'site-core' ),
			__( 'Cartelle media', 'site-core' ),
			'upload_files',
			'edit-tags.php?taxonomy=' . self::TASSONOMIA . '&post_type=attachment'
		);
	}

	/* ---------------------------------------------------------------- */
	/* Vista Elenco                                                      */
	/* ---------------------------------------------------------------- */

	public function filtro_elenco( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$cartelle = get_terms( array( 'taxonomy' => self::TASSONOMIA, 'hide_empty' => false ) );
		if ( is_wp_error( $cartelle ) || ! $cartelle ) {
			return;
		}

		$selezionata = isset( $_GET[ self::TASSONOMIA ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TASSONOMIA ] ) ) : '';
		?>
		<select name="<?php echo esc_attr( self::TASSONOMIA ); ?>">
			<option value=""><?php esc_html_e( 'Tutte le cartelle', 'site-core' ); ?></option>
			<?php foreach ( $cartelle as $cartella ) : ?>
				<option value="<?php echo esc_attr( $cartella->slug ); ?>" <?php selected( $selezionata, $cartella->slug ); ?>>
					<?php echo esc_html( str_repeat( '-- ', self::profondita( $cartella, $cartelle ) ) . $cartella->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function applica_filtro_elenco( $query ) {
		if ( ! is_admin() || 'upload.php' !== $GLOBALS['pagenow'] || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( empty( $_GET[ self::TASSONOMIA ] ) ) {
			return;
		}
		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => self::TASSONOMIA,
					'field'    => 'slug',
					'terms'    => sanitize_text_field( wp_unslash( $_GET[ self::TASSONOMIA ] ) ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------- */
	/* Pannello dettagli allegato (Griglia + schermo classico)          */
	/* ---------------------------------------------------------------- */

	public function campo_cartella( $form_fields, $post ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $form_fields;
		}

		$cartelle    = get_terms( array( 'taxonomy' => self::TASSONOMIA, 'hide_empty' => false ) );
		$assegnata   = wp_get_object_terms( $post->ID, self::TASSONOMIA, array( 'fields' => 'ids' ) );
		$selezionata = ( ! is_wp_error( $assegnata ) && ! empty( $assegnata ) ) ? (int) $assegnata[0] : 0;

		$opzioni = '<option value="0">' . esc_html__( '-- nessuna --', 'site-core' ) . '</option>';
		if ( ! is_wp_error( $cartelle ) ) {
			foreach ( $cartelle as $cartella ) {
				$opzioni .= sprintf(
					'<option value="%d" %s>%s</option>',
					$cartella->term_id,
					selected( $selezionata, $cartella->term_id, false ),
					esc_html( str_repeat( '-- ', self::profondita( $cartella, $cartelle ) ) . $cartella->name )
				);
			}
		}

		// Attenzione al nome del campo: NON puo' chiamarsi come la
		// tassonomia (scc_media_folder). WordPress, dopo aver applicato
		// questo filtro, scorre da solo get_attachment_taxonomies() e per
		// ognuna chiama wp_set_post_terms() se $attachment_data[$tassonomia]
		// e' presente , trattando pero' il valore grezzo come un NOME di
		// termine da creare al volo, non come un ID. Con lo stesso nome qui
		// succedevano due cose in conflitto: la nostra assegnazione corretta
		// (per id) veniva subito dopo sovrascritta da quella automatica di
		// WordPress (per nome), che oltretutto creava una cartella nuova e
		// sbagliata chiamata come l'id numerico (es. una cartella "11").
		// Un nome di campo diverso dalla tassonomia evita la collisione.
		$form_fields['scc_media_folder'] = array(
			'label' => __( 'Cartella', 'site-core' ),
			'input' => 'html',
			'html'  => '<select name="attachments[' . (int) $post->ID . '][scc_cartella]">' . $opzioni . '</select>',
			'helps' => __( 'Solo per organizzare la Libreria media: non cambia dove il file e\' salvato sul server.', 'site-core' ),
		);

		return $form_fields;
	}

	public function salva_campo_cartella( $post, $attachment ) {
		if ( ! current_user_can( 'upload_files' ) || ! isset( $attachment['scc_cartella'] ) ) {
			return $post;
		}

		$id = (int) $attachment['scc_cartella'];
		wp_set_object_terms( (int) $post['ID'], $id > 0 ? array( $id ) : array(), self::TASSONOMIA, false );

		return $post;
	}

	/* ---------------------------------------------------------------- */
	/* Griglia                                                           */
	/* ---------------------------------------------------------------- */

	public function carica_script_griglia() {
		$dati = $this->dati_cartelle();
		wp_enqueue_style( 'scc-media-folders', SCC_URL . 'assets/css/media-folders.css', array(), SCC_VERSION );
		wp_enqueue_script( 'scc-media-folders', SCC_URL . 'assets/js/media-folders.js', array( 'jquery', 'media-views' ), SCC_VERSION, true );
		wp_localize_script( 'scc-media-folders', 'sccMediaFolders', array_merge( $dati, array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'scc_media_folders' ),
			'labels' => array(
				'titolo' => __( 'Cartelle', 'site-core' ), 'trascina' => __( 'Trascina qui i media per organizzarli.', 'site-core' ),
				'nuova' => __( 'Nuova', 'site-core' ), 'rinomina' => __( 'Rinomina cartella', 'site-core' ),
				'elimina' => __( 'Elimina cartella', 'site-core' ), 'nonAssegnati' => __( 'Non assegnati', 'site-core' ),
				'vuota' => __( 'Crea la prima cartella per iniziare a organizzare i media.', 'site-core' ),
				'confermaElimina' => __( 'Eliminare “%s”? I media resteranno nella libreria senza cartella.', 'site-core' ),
				'cerca' => __( 'Cerca cartelle', 'site-core' ), 'nome' => __( 'Nome', 'site-core' ),
				'parent' => __( 'Dentro', 'site-core' ), 'radice' => __( 'Nessuna cartella padre', 'site-core' ),
				'annulla' => __( 'Annulla', 'site-core' ), 'salva' => __( 'Salva', 'site-core' ),
				'attendi' => __( 'Salvataggio…', 'site-core' ), 'errore' => __( 'Non è stato possibile completare l’operazione.', 'site-core' ),
			),
		) ) );
	}

	/**
	 * WordPress lascia passare un query_var custom nel filtro AJAX della
	 * Griglia solo se la tassonomia e' 'public' (register_taxonomy():
	 * senza 'public' => true, $taxonomy->query_var resta false anche se
	 * si e' chiesto esplicitamente un query_var , e wp_ajax_query_attachments()
	 * costruisce il suo elenco di chiavi ammesse leggendo proprio quello).
	 * 'public' => true qui aprirebbe pero' un archivio pubblico in
	 * front-end che non serve a nessuno: si legge $_REQUEST['query'] cosi'
	 * come arriva davvero, prima che WordPress lo filtri, invece di fidarsi
	 * del $query gia' passato al filtro (che a quel punto puo' gia' averlo
	 * perso).
	 */
	public function applica_filtro_griglia( $query ) {
		$valore = 0;
		if ( ! empty( $query['scc_media_folder'] ) ) {
			$valore = (int) $query['scc_media_folder'];
		} elseif ( isset( $_REQUEST['query'][ self::TASSONOMIA ] ) ) {
			$valore = (int) $_REQUEST['query'][ self::TASSONOMIA ];
		}

		// Va tolta da qui: se resta, WordPress la riconosce ANCHE da sola
		// come query_var della tassonomia e costruisce un secondo tax_query
		// automatico (per slug, non per id) in AND con questo , due filtri
		// sulla stessa tassonomia non possono mai essere veri insieme, e la
		// query non trova piu' niente.
		unset( $query['scc_media_folder'] );

		if ( -1 === $valore ) {
			$query['tax_query'] = array( array( 'taxonomy' => self::TASSONOMIA, 'operator' => 'NOT EXISTS' ) );
		} elseif ( $valore > 0 ) {
			$query['tax_query'] = array(
				array(
					'taxonomy' => self::TASSONOMIA,
					'field'    => 'term_id',
					'terms'    => $valore,
				),
			);
		}
		return $query;
	}

	/* ---------------------------------------------------------------- */
	/* Azioni AJAX                                                      */
	/* ---------------------------------------------------------------- */
	private function autorizza_ajax() {
		check_ajax_referer( 'scc_media_folders', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( array( 'message' => __( 'Operazione non consentita.', 'site-core' ) ), 403 );
	}
	private function dati_cartelle() {
		$cartelle = get_terms( array( 'taxonomy' => self::TASSONOMIA, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		$lista = array();
		if ( ! is_wp_error( $cartelle ) ) foreach ( $cartelle as $cartella ) $lista[] = array( 'id' => (int) $cartella->term_id, 'nome' => $cartella->name, 'parent' => (int) $cartella->parent, 'count' => (int) $cartella->count );
		$conteggi = wp_count_posts( 'attachment' );
		$senza = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => self::TASSONOMIA, 'operator' => 'NOT EXISTS' ) ) ) );
		return array( 'cartelle' => $lista, 'totale' => isset( $conteggi->inherit ) ? (int) $conteggi->inherit : 0, 'nonAssegnati' => (int) $senza->found_posts );
	}
	private function risposta_cartelle( $messaggio ) {
		wp_send_json_success( array_merge( $this->dati_cartelle(), array( 'message' => $messaggio ) ) );
	}
	public function ajax_crea_cartella() {
		$this->autorizza_ajax();
		$nome = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		if ( '' === $nome ) wp_send_json_error( array( 'message' => __( 'Inserisci un nome per la cartella.', 'site-core' ) ), 400 );
		$risultato = wp_insert_term( $nome, self::TASSONOMIA, array( 'parent' => $parent ) );
		if ( is_wp_error( $risultato ) ) wp_send_json_error( array( 'message' => $risultato->get_error_message() ), 400 );
		$this->risposta_cartelle( __( 'Cartella creata.', 'site-core' ) );
	}
	public function ajax_aggiorna_cartella() {
		$this->autorizza_ajax();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; $nome = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; $parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		if ( ! $id || '' === $nome || $id === $parent ) wp_send_json_error( array( 'message' => __( 'Dati della cartella non validi.', 'site-core' ) ), 400 );
		$risultato = wp_update_term( $id, self::TASSONOMIA, array( 'name' => $nome, 'parent' => $parent ) );
		if ( is_wp_error( $risultato ) ) wp_send_json_error( array( 'message' => $risultato->get_error_message() ), 400 );
		$this->risposta_cartelle( __( 'Cartella aggiornata.', 'site-core' ) );
	}
	public function ajax_elimina_cartella() {
		$this->autorizza_ajax(); $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) wp_send_json_error( array( 'message' => __( 'Cartella non valida.', 'site-core' ) ), 400 );
		$risultato = wp_delete_term( $id, self::TASSONOMIA );
		if ( is_wp_error( $risultato ) || false === $risultato ) wp_send_json_error( array( 'message' => is_wp_error( $risultato ) ? $risultato->get_error_message() : __( 'Cartella non trovata.', 'site-core' ) ), 400 );
		$this->risposta_cartelle( __( 'Cartella eliminata. I media non sono stati cancellati.', 'site-core' ) );
	}
	public function ajax_assegna_media() {
		$this->autorizza_ajax(); $ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['ids'] ) ) : array(); $cartella = isset( $_POST['folder'] ) ? absint( $_POST['folder'] ) : 0;
		if ( ! $ids ) wp_send_json_error( array( 'message' => __( 'Seleziona almeno un media.', 'site-core' ) ), 400 );
		foreach ( $ids as $id ) if ( 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) wp_set_object_terms( $id, $cartella ? array( $cartella ) : array(), self::TASSONOMIA, false );
		$this->risposta_cartelle( _n( 'Media spostato.', 'Media spostati.', count( $ids ), 'site-core' ) );
	}


	/* ---------------------------------------------------------------- */
	/* Aiuto                                                             */
	/* ---------------------------------------------------------------- */

	/** Profondita' di una cartella nell'albero, per indentare le liste piatte. */
	private static function profondita( $cartella, $tutte ) {
		$livello  = 0;
		$corrente = $cartella;

		while ( $corrente->parent ) {
			$livello++;
			$trovata = null;
			foreach ( $tutte as $t ) {
				if ( (int) $t->term_id === (int) $corrente->parent ) {
					$trovata = $t;
					break;
				}
			}
			if ( ! $trovata ) {
				break;
			}
			$corrente = $trovata;
		}

		return $livello;
	}
}
