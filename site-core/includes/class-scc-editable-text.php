<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Testi editabili in-place su pagine costruite a codice nativo.
 *
 * Stessa filosofia di SCC_Site_Content: il default vive nel codice (la
 * stringa passata come 2o argomento a scc_text(), o il contenuto racchiuso
 * dallo shortcode [scc_text]); un eventuale override vive nel database.
 * Svuotando un testo si torna al default.
 *
 * Due modi d'uso, a seconda di DOVE sta il markup:
 *
 *   1) Nei TEMPLATE PHP del tema (header.php, page.php, ecc.):
 *        <h1><?php scc_text( 'home:hero:titolo', 'Benvenuto' ); ?></h1>
 *
 *   2) Nel CONTENUTO delle pagine WordPress (post_content, HTML a mano):
 *        <h1>[scc_text id="home:hero:titolo"]Benvenuto[/scc_text]</h1>
 *      Lo shortcode e' necessario perche' dentro post_content il PHP non gira.
 *
 * ID ESPLICITO e stabile, convenzione pagina:sezione:campo. Descrive il
 * SIGNIFICATO del testo, non la posizione, cosi' l'override resta agganciato
 * anche se il markup viene rifattorizzato.
 *
 * Sono ammessi pochi tag inline nel testo (a, em, strong, b, i, u, small, br,
 * span) cosi' un titolo con <em> resta editabile senza perdere il markup.
 *
 * L'editor inline si attiva SOLO per utenti loggati con 'edit_theme_options',
 * e solo quando l'utente lo ha acceso (preferenza per-utente, toggle dalla
 * barra admin o dalla schermata dedicata). Per un visitatore anonimo l'output
 * e' solo il testo, senza markup aggiuntivo: zero impronta sul front-end.
 */
class SCC_Editable_Text {

	const OPTION_KEY = 'scc_text_overrides';
	const USER_META  = 'scc_inline_editor_on';
	const CAPABILITY = 'edit_theme_options';
	const STYLE_OPTION_KEY = 'scc_style_overrides';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_toggle' ), 90 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_post_scc_toggle_inline_editor', array( $this, 'handle_toggle' ) );
		add_shortcode( 'scc_text', array( $this, 'shortcode' ) );
	}

	/* ---------------- Stato editor ---------------- */

	public static function user_can_edit() {
		if ( class_exists( 'SCC_License' ) && ! SCC_License::is_active() ) {
			return false;
		}
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}

	public static function is_editing() {
		if ( ! self::user_can_edit() ) {
			return false;
		}
		return '1' === (string) get_user_meta( get_current_user_id(), self::USER_META, true );
	}

	/* ---------------- Storage ---------------- */

	public static function clean_id( $id ) {
		return preg_replace( '/[^a-z0-9:_-]/i', '', (string) $id );
	}

	public static function get_all() {
		$v = get_option( self::OPTION_KEY, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function get_value( $id, $default = '' ) {
		$id  = self::clean_id( $id );
		$all = self::get_all();
		return ( array_key_exists( $id, $all ) && '' !== $all[ $id ] ) ? (string) $all[ $id ] : (string) $default;
	}

	/**
	 * Larghezza/altezza salvate per un box di testo editabile (feature
	 * separata dal valore del testo: due opzioni distinte cosi' svuotare
	 * il testo non tocca lo stile e viceversa).
	 */
	public static function get_style( $id ) {
		$id  = self::clean_id( $id );
		$all = get_option( self::STYLE_OPTION_KEY, array() );
		$all = is_array( $all ) ? $all : array();
		$s   = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		return array(
			'width'  => isset( $s['width'] ) ? (string) $s['width'] : '',
			'height' => isset( $s['height'] ) ? (string) $s['height'] : '',
		);
	}

	/**
	 * Whitelist di lunghezze CSS ammesse per larghezza/altezza del box di
	 * testo: solo un numero con una unita' nota, oppure 'auto' o stringa
	 * vuota. Qualsiasi altra cosa viene scartata (mai salvata, mai
	 * stampata) cosi' questi due campi non diventano una porta per
	 * iniettare CSS o markup arbitrario nello style inline.
	 */
	public static function sanitize_css_length( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 'auto' === $value ) {
			return $value;
		}
		return preg_match( '/^[0-9]+(\.[0-9]+)?(px|%|em|rem|vw|vh)$/', $value ) ? $value : '';
	}

	/**
	 * Tag inline ammessi nei testi editabili (sia in output sia in salvataggio).
	 */
	public static function allowed_html() {
		return array(
			'a'      => array( 'href' => true, 'title' => true, 'class' => true, 'target' => true, 'rel' => true ),
			'em'     => array(),
			'strong' => array(),
			'b'      => array(),
			'i'      => array(),
			'u'      => array(),
			'small'  => array(),
			'br'     => array(),
			'span'   => array( 'class' => true ),
		);
	}

	/* ---------------- Helper di stampa ---------------- */

	public static function render( $id, $default = '', $echo = true ) {
		$id    = self::clean_id( $id );
		$value = self::get_value( $id, $default );
		$safe  = wp_kses( $value, self::allowed_html() );

		$style      = self::get_style( $id );
		$style_attr = '';
		if ( '' !== $style['width'] || '' !== $style['height'] ) {
			$decl = 'display:inline-block;';
			if ( '' !== $style['width'] ) {
				$decl .= 'width:' . $style['width'] . ';';
			}
			if ( '' !== $style['height'] ) {
				$decl .= 'height:' . $style['height'] . ';overflow:auto;';
			}
			$style_attr = ' style="' . esc_attr( $decl ) . '"';
		}

		if ( self::is_editing() ) {
			$html = '<span class="scc-editable" data-scc-edit="' . esc_attr( $id ) . '" data-scc-width="' . esc_attr( $style['width'] ) . '" data-scc-height="' . esc_attr( $style['height'] ) . '"' . $style_attr . '>' . $safe . '</span>';
		} else {
			$html = $style_attr ? '<span class="scc-styled"' . $style_attr . '>' . $safe . '</span>' : $safe;
		}

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses sopra.
		}
		return $html;
	}

	/**
	 * Shortcode [scc_text id="pagina:sezione:campo"]Testo predefinito[/scc_text]
	 * per usare i testi editabili dentro il contenuto delle pagine (post_content).
	 */
	public function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'scc_text' );
		if ( '' === self::clean_id( $atts['id'] ) ) {
			return $content; // id assente: stampa il testo cosi' com'e', non rompere la pagina.
		}
		return self::render( $atts['id'], (string) $content, false );
	}

	/* ---------------- REST ---------------- */

	public function register_routes() {
		register_rest_route(
			'scc/v1',
			'/text',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save' ),
				'permission_callback' => function () {
					// La licenza blocca solo la SCRITTURA: la lettura degli
					// override resta sempre attiva, un testo gia' personalizzato
					// dal cliente non deve sparire perche' scade una chiave.
					if ( class_exists( 'SCC_License' ) && ! SCC_License::is_active() ) {
						return false;
					}
					return current_user_can( self::CAPABILITY );
				},
			)
		);

		register_rest_route(
			'scc/v1',
			'/text-style',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_style' ),
				'permission_callback' => function () {
					if ( class_exists( 'SCC_License' ) && ! SCC_License::is_active() ) {
						return false;
					}
					return current_user_can( self::CAPABILITY );
				},
			)
		);
	}

	public function handle_save( WP_REST_Request $request ) {
		$id = self::clean_id( $request->get_param( 'id' ) );
		if ( '' === $id ) {
			return new WP_Error( 'scc_bad_id', __( 'ID mancante o non valido.', 'site-core' ), array( 'status' => 400 ) );
		}

		$value = wp_kses( wp_unslash( (string) $request->get_param( 'value' ) ), self::allowed_html() );
		$value = trim( $value );
		$all   = self::get_all();

		if ( '' === wp_strip_all_tags( $value ) ) {
			unset( $all[ $id ] ); // svuotare = tornare al default del codice.
		} else {
			$all[ $id ] = $value;
		}
		update_option( self::OPTION_KEY, $all );

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'value'   => $value,
			),
			200
		);
	}

	public function handle_save_style( WP_REST_Request $request ) {
		$id = self::clean_id( $request->get_param( 'id' ) );
		if ( '' === $id ) {
			return new WP_Error( 'scc_bad_id', __( 'ID mancante o non valido.', 'site-core' ), array( 'status' => 400 ) );
		}

		$width  = self::sanitize_css_length( $request->get_param( 'width' ) );
		$height = self::sanitize_css_length( $request->get_param( 'height' ) );

		$all = get_option( self::STYLE_OPTION_KEY, array() );
		$all = is_array( $all ) ? $all : array();

		if ( '' === $width && '' === $height ) {
			unset( $all[ $id ] ); // entrambi vuoti = torna alla dimensione naturale.
		} else {
			$all[ $id ] = array(
				'width'  => $width,
				'height' => $height,
			);
		}
		update_option( self::STYLE_OPTION_KEY, $all );

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'width'   => $width,
				'height'  => $height,
			),
			200
		);
	}

	/* ---------------- Asset front-end ---------------- */

	public function enqueue_editor_assets() {
		if ( ! self::is_editing() ) {
			return;
		}
		// Serve la Media Library JS per la sostituzione immagini (SCC_Editable_Image).
		wp_enqueue_media();
		wp_enqueue_script( 'scc-editor', SCC_URL . 'assets/js/editor.js', array(), SCC_VERSION, true );
		wp_localize_script(
			'scc-editor',
			'sccEditor',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'scc/v1/text' ) ),
				'restUrlStyle' => esc_url_raw( rest_url( 'scc/v1/text-style' ) ),
				'restUrlImage' => esc_url_raw( rest_url( 'scc/v1/image' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			)
		);
		wp_enqueue_style( 'scc-editor', SCC_URL . 'assets/css/editor.css', array(), SCC_VERSION );
	}

	/* ---------------- Toggle: barra admin + schermata ---------------- */

	public function admin_bar_toggle( $wp_admin_bar ) {
		if ( ! self::user_can_edit() ) {
			return;
		}
		$on  = self::is_editing();
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=scc_toggle_inline_editor' ), 'scc_toggle_inline_editor' );
		$wp_admin_bar->add_node(
			array(
				'id'    => 'scc-inline-editor',
				'title' => $on ? 'Editor testi: ON' : 'Editor testi: OFF',
				'href'  => $url,
				'meta'  => array( 'title' => __( 'Attiva o disattiva la modifica dei testi in pagina', 'site-core' ) ),
			)
		);
	}

	public function handle_toggle() {
		if ( ! self::user_can_edit() ) {
			wp_die( esc_html__( 'Non autorizzato.', 'site-core' ) );
		}
		check_admin_referer( 'scc_toggle_inline_editor' );

		$uid = get_current_user_id();
		$on  = '1' === (string) get_user_meta( $uid, self::USER_META, true );
		update_user_meta( $uid, self::USER_META, $on ? '0' : '1' );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : home_url( '/' ) );
		exit;
	}

	public function register_menu() {
		add_submenu_page(
			'scc-entries',
			__( 'Editor inline', 'site-core' ),
			__( 'Editor inline', 'site-core' ),
			self::CAPABILITY,
			'scc-inline-editor',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! self::user_can_edit() ) {
			return;
		}
		$on = self::is_editing();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Editor inline dei testi', 'site-core' ); ?></h1>
			<p class="description" style="max-width:820px">
				<?php esc_html_e( 'Quando e attivo, aprendo il sito da loggato puoi cliccare i testi predisposti con scc_text() o lo shortcode [scc_text] e modificarli direttamente in pagina. La modifica riguarda solo il tuo account e non cambia il codice: il testo predefinito resta nei template o nel contenuto, mentre l override viene salvato nel database. Svuotando un testo si torna al predefinito.', 'site-core' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scc_toggle_inline_editor' ); ?>
				<input type="hidden" name="action" value="scc_toggle_inline_editor" />
				<p>
					<strong><?php esc_html_e( 'Stato attuale:', 'site-core' ); ?></strong>
					<?php echo $on ? '<span style="color:#1a7f37">ON</span>' : '<span style="color:#c62828">OFF</span>'; ?>
				</p>
				<?php submit_button( $on ? __( 'Disattiva editor', 'site-core' ) : __( 'Attiva editor', 'site-core' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Testi salvati', 'site-core' ); ?></h2>
			<?php $all = self::get_all(); ?>
			<?php if ( empty( $all ) ) : ?>
				<p><?php esc_html_e( 'Nessun override salvato: tutti i testi mostrano il predefinito del codice.', 'site-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:820px">
					<thead><tr><th><?php esc_html_e( 'ID', 'site-core' ); ?></th><th><?php esc_html_e( 'Testo', 'site-core' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $all as $id => $text ) : ?>
						<tr><td><code><?php echo esc_html( $id ); ?></code></td><td><?php echo wp_kses( $text, self::allowed_html() ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

/**
 * Helper globale per i TEMPLATE PHP: stampa un testo editabile.
 *
 *   <h1><?php scc_text( 'home:hero:titolo', 'Benvenuto' ); ?></h1>
 *
 * Nel CONTENUTO delle pagine (post_content) usa invece lo shortcode:
 *   <h1>[scc_text id="home:hero:titolo"]Benvenuto[/scc_text]</h1>
 */
function scc_text( $id, $default = '' ) {
	return SCC_Editable_Text::render( $id, $default, true );
}

/** Variante che ritorna la stringa (gia' resa) invece di stamparla. */
function scc_get_text( $id, $default = '' ) {
	return SCC_Editable_Text::render( $id, $default, false );
}

