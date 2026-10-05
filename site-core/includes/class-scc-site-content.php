<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Testata e pie' di pagina, modificabili a codice.
 *
 * Sono gli unici pezzi del sito che non stanno nel contenuto di una pagina:
 * compaiono ovunque, quindi non c'e' una pagina che li possieda. Qui si
 * modificano come markup, con la stessa logica delle pagine: si scrive HTML,
 * si salva, esce identico.
 *
 * Se un campo resta vuoto il tema stampa il proprio markup predefinito, cosi'
 * il sito funziona anche prima che qualcuno tocchi qualcosa e si puo' sempre
 * tornare indietro svuotando il campo.
 *
 * Le voci di navigazione non stanno qui: sono menu nativi di WordPress, in
 * Aspetto > Menu. Nel markup si richiamano con uno shortcode.
 */
class SCC_Site_Content {

	const OPTION_KEY = 'scc_site_markup';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'registra_menu' ), 20 );
		add_action( 'admin_post_scc_salva_site_markup', array( $this, 'salva' ) );
	}

	/**
	 * Le due aree modificabili.
	 */
	public static function aree() {
		return apply_filters(
			'scc_site_markup_aree',
			array(
				'testata' => array(
					'label' => __( 'Testata', 'site-core' ),
					'nota'  => __( 'Markup che apre ogni pagina, subito dopo il tag body.', 'site-core' ),
				),
				'pie' => array(
					'label' => __( 'Pie di pagina', 'site-core' ),
					'nota'  => __( 'Markup che chiude ogni pagina.', 'site-core' ),
				),
			)
		);
	}

	/**
	 * A licenza non attiva la personalizzazione di testata e pie' si
	 * ferma del tutto: torna il markup predefinito del tema, non solo
	 * l'editing si blocca. E' diverso dai testi editabili (SCC_Editable_Text),
	 * dove il contenuto gia' scritto resta sempre visibile: qui la
	 * licenza scaduta spegne anche l'output, per scelta esplicita.
	 */
	public static function licenza_attiva() {
		return ! class_exists( 'SCC_License' ) || SCC_License::is_active();
	}

	/**
	 * Markup salvato per un'area. Stringa vuota se non e' mai stato scritto,
	 * o se la licenza non e' attiva: in quel caso e' come se non ci fosse
	 * mai stata nessuna personalizzazione, e il tema stampa il suo default.
	 */
	public static function markup( $area ) {
		if ( ! self::licenza_attiva() ) {
			return '';
		}
		$valori = get_option( self::OPTION_KEY, array() );
		$area   = sanitize_key( $area );
		if ( ! is_array( $valori ) || empty( $valori[ $area ] ) ) {
			return '';
		}
		return (string) $valori[ $area ];
	}

	/**
	 * True se l'area ha un markup personalizzato: il tema lo usa per decidere
	 * se stampare il proprio predefinito.
	 */
	public static function ha_markup( $area ) {
		return '' !== trim( self::markup( $area ) );
	}

	/**
	 * Stampa il markup dell'area, risolvendo gli shortcode.
	 */
	public static function stampa( $area ) {
		$codice = self::markup( $area );
		if ( '' === trim( $codice ) ) {
			return false;
		}
		echo do_shortcode( $codice ); // phpcs:ignore WordPress.Security.EscapeOutput
		return true;
	}

	/* ---------------------------------------------------------------- */

	public function registra_menu() {
		add_submenu_page(
			'scc-entries',
			__( 'Testata e pie di pagina', 'site-core' ),
			__( 'Testata e pie', 'site-core' ),
			'edit_theme_options',
			'scc-site-content',
			array( $this, 'render' )
		);
	}

	public function salva() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'site-core' ) );
		}
		if ( ! self::licenza_attiva() ) {
			wp_die( esc_html__( 'La licenza non e\' attiva: la modifica di testata e pie\' e\' ferma finche\' non torna valida.', 'site-core' ) );
		}
		check_admin_referer( 'scc_salva_site_markup' );

		$valori = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $valori ) ) {
			$valori = array();
		}

		foreach ( array_keys( self::aree() ) as $area ) {
			if ( ! isset( $_POST[ $area ] ) ) {
				continue;
			}
			$codice = wp_unslash( $_POST[ $area ] );
			// Chi non ha il permesso di scrivere HTML libero passa da KSES.
			$valori[ $area ] = current_user_can( 'unfiltered_html' ) ? $codice : wp_kses_post( $codice );
		}

		update_option( self::OPTION_KEY, $valori );

		wp_safe_redirect( admin_url( 'admin.php?page=scc-site-content&salvato=1' ) );
		exit;
	}

	/**
	 * Shortcode disponibili nel markup, elencati nella schermata.
	 */
	private static function aiuto_shortcode() {
		return array(
			'[scc_menu posizione="testata-sinistra"]' => __( 'Stampa un menu di WordPress. Le posizioni disponibili sono elencate in Aspetto > Menu.', 'site-core' ),
			'[scc_url pagina="contatti"]'             => __( 'URL assoluto di una pagina interna, senza scrivere il dominio a mano.', 'site-core' ),
			'[scc_home]'                              => __( 'URL della home.', 'site-core' ),
			'[scc_anno]'                              => __( 'Anno corrente.', 'site-core' ),
		);
	}

	public function render() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$valori = get_option( self::OPTION_KEY, array() );
		$valori = is_array( $valori ) ? $valori : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Testata e pie di pagina', 'site-core' ); ?></h1>

			<p class="description" style="max-width:820px">
				<?php esc_html_e( 'Questi due blocchi compaiono su tutte le pagine, quindi non stanno nel contenuto di nessuna di esse. Si scrivono come markup, esattamente come il contenuto delle pagine. Lasciando un campo vuoto resta attivo il markup predefinito del tema.', 'site-core' ); ?>
			</p>

			<?php if ( ! empty( $_GET['salvato'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Salvato.', 'site-core' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! self::licenza_attiva() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'La licenza non e\' attiva: testata e pie\' di pagina mostrano il markup predefinito del tema, e da qui non si puo\' salvare nulla finche\' la licenza non torna valida.', 'site-core' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! current_user_can( 'unfiltered_html' ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Il tuo ruolo non consente HTML libero: il markup verra filtrato al salvataggio.', 'site-core' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Shortcode disponibili', 'site-core' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
				<?php foreach ( self::aiuto_shortcode() as $codice => $spiegazione ) : ?>
					<tr>
						<td style="width:280px"><code><?php echo esc_html( $codice ); ?></code></td>
						<td><?php echo esc_html( $spiegazione ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scc_salva_site_markup' ); ?>
				<input type="hidden" name="action" value="scc_salva_site_markup" />

				<?php foreach ( self::aree() as $area => $meta ) : ?>
					<?php $valore = isset( $valori[ $area ] ) ? $valori[ $area ] : ''; ?>
					<h2><?php echo esc_html( $meta['label'] ); ?></h2>
					<p class="description"><?php echo esc_html( $meta['nota'] ); ?></p>
					<p>
						<textarea
							name="<?php echo esc_attr( $area ); ?>"
							id="scc-area-<?php echo esc_attr( $area ); ?>"
							rows="20"
							spellcheck="false"
							style="width:100%;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6;tab-size:2"
							placeholder="<?php esc_attr_e( 'Vuoto: resta il markup predefinito del tema.', 'site-core' ); ?>"><?php echo esc_textarea( $valore ); ?></textarea>
					</p>
				<?php endforeach; ?>

				<?php submit_button( __( 'Salva', 'site-core' ) ); ?>
			</form>
		</div>
		<?php
	}
}

/* ---------------------------------------------------------------------
 * Shortcode utilizzabili dentro il markup di testata e pie'.
 * ------------------------------------------------------------------ */

/**
 * [scc_menu posizione="testata-sinistra" classe="..."]
 * Stampa un menu nativo di WordPress assegnato a quella posizione.
 */
function scc_shortcode_menu( $atts ) {
	$a = shortcode_atts(
		array(
			'posizione' => '',
			'classe'    => '',
			'profondita' => 2,
		),
		$atts,
		'scc_menu'
	);

	$posizione = sanitize_key( $a['posizione'] );
	if ( ! $posizione || ! has_nav_menu( $posizione ) ) {
		return '';
	}

	$args = array(
		'theme_location' => $posizione,
		'container'      => false,
		'menu_class'     => sanitize_text_field( $a['classe'] ),
		'depth'          => absint( $a['profondita'] ),
		'echo'           => false,
		'fallback_cb'    => '__return_empty_string',
	);

	if ( class_exists( 'Mama_Walker_Menu' ) ) {
		$args['walker'] = new Mama_Walker_Menu();
	}

	return wp_nav_menu( $args );
}
add_shortcode( 'scc_menu', 'scc_shortcode_menu' );

/** [scc_url pagina="contatti"] */
function scc_shortcode_url( $atts ) {
	$a    = shortcode_atts( array( 'pagina' => '' ), $atts, 'scc_url' );
	$slug = sanitize_title( $a['pagina'] );
	return esc_url( $slug ? home_url( '/' . $slug . '/' ) : home_url( '/' ) );
}
add_shortcode( 'scc_url', 'scc_shortcode_url' );

/** [scc_home] */
add_shortcode( 'scc_home', fn() => esc_url( home_url( '/' ) ) );

/** [scc_anno] */
add_shortcode( 'scc_anno', fn() => esc_html( gmdate( 'Y' ) ) );

