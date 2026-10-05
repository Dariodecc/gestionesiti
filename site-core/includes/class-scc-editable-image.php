<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immagini editabili in-place sulle stesse aree gestite da SCC_Editable_Text:
 * stesso interruttore ON/OFF, stessa capability, stesso blocco a licenza non
 * attiva. Non e' un blocco nuovo utilizzabile ovunque: e' un secondo tipo di
 * contenuto (immagine, non testo) dentro l'infrastruttura gia' esistente
 * dell'editor inline.
 *
 * Quattro cose si possono cambiare, tutte confinate all'immagine stessa (mai
 * al box/contenitore che la ospita, che resta del tema):
 *  - l'immagine stessa (sostituzione dalla Media Library)
 *  - l'allineamento del blocco immagine (sinistra / centro / destra)
 *  - il riempimento CSS (object-fit: cover/contain/fill/none/scale-down)
 *  - la rotazione a passi di 90 gradi
 *
 * Il default vive nel codice (src/alt/class passati a scc_image()); ogni
 * override vive nel database. Fit e rotate vengono applicati SOLO se
 * esplicitamente impostati (mai un default a caso), quindi finche' nessuno
 * tocca i controlli il markup resta identico a prima: e' un override
 * volontario e dichiarato dell'aspetto scelto dal tema, non un valore che
 * lo sostituisce di nascosto.
 *
 *   <?php scc_image( 'home:hero:foto', 'https://.../default.jpg', 'Foto team', 'cover__media__img--basso', array( 'size' => 'full', 'eager' => true ) ); ?>
 *   [scc_image id="home:hero:foto" src="https://.../default.jpg" alt="Foto team" class="cover__media__img--basso" size="full" eager="1" attachment_id="189"]
 */
class SCC_Editable_Image {

	const OPTION_KEY = 'scc_image_overrides';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_shortcode( 'scc_image', array( $this, 'shortcode' ) );
	}

	/* ---------------- Storage ---------------- */

	public static function get_all() {
		$v = get_option( self::OPTION_KEY, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function get_override( $id ) {
		$id  = SCC_Editable_Text::clean_id( $id );
		$all = self::get_all();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
	}

	public static function allowed_aligns() {
		return array( '', 'left', 'center', 'right' );
	}

	/**
	 * object-fit ammessi. La chiave vuota = "predefinito", cioe' nessun
	 * override: l'immagine si comporta come senza questo modulo (il tema
	 * decide tramite il proprio CSS/class).
	 */
	public static function fit_labels() {
		return array(
			''           => __( 'Predefinito (tema)', 'site-core' ),
			'cover'      => __( 'Riempi e ritaglia (cover)', 'site-core' ),
			'contain'    => __( 'Adatta intera (contain)', 'site-core' ),
			'fill'       => __( 'Deforma per riempire (fill)', 'site-core' ),
			'none'       => __( 'Dimensione originale (none)', 'site-core' ),
			'scale-down' => __( 'Riduci se serve (scale-down)', 'site-core' ),
		);
	}

	public static function allowed_fits() {
		return array_keys( self::fit_labels() );
	}

	/** Rotazione a passi di 90 gradi soltanto: niente angoli liberi. */
	public static function allowed_rotations() {
		return array( 0, 90, 180, 270 );
	}

	/**
	 * Ripulisce una lista di classi CSS separate da spazio con
	 * sanitize_html_class(), scartando i token vuoti/non validi.
	 */
	public static function sanitize_class_list( $class ) {
		$class = (string) $class;
		if ( '' === trim( $class ) ) {
			return '';
		}
		$tokens = preg_split( '/\s+/', trim( $class ) );
		$clean  = array();
		foreach ( $tokens as $t ) {
			$t = sanitize_html_class( $t );
			if ( '' !== $t ) {
				$clean[] = $t;
			}
		}
		return implode( ' ', $clean );
	}

	/* ---------------- Helper di stampa ---------------- */

	/**
	 * @param string $id            Stesso schema id di scc_text(): pagina:sezione:campo.
	 * @param string $default_src   URL immagine di default (codice).
	 * @param string $default_alt   Testo alternativo di default.
	 * @param string $default_class Classi CSS del tema da applicare all'<img> (codice).
	 * @param array  $args {
	 *     Opzionale.
	 *     @type string $size  Dimensione registrata WP da usare con wp_get_attachment_image() (default 'full').
	 *     @type bool   $eager Se true: loading="eager" + fetchpriority="high" invece del lazy-load di default.
	 * }
	 */
	public static function render( $id, $default_src = '', $default_alt = '', $default_class = '', $args = array(), $echo = true ) {
		$id       = SCC_Editable_Text::clean_id( $id );
		$override = self::get_override( $id );

		$src           = ! empty( $override['src'] ) ? esc_url_raw( $override['src'] ) : $default_src;
		$alt           = isset( $override['alt'] ) && '' !== $override['alt'] ? $override['alt'] : $default_alt;
		$align_raw     = $override['align'] ?? '';
		$align         = in_array( $align_raw, self::allowed_aligns(), true ) ? $align_raw : '';
		$fit_raw       = $override['fit'] ?? '';
		$fit           = in_array( $fit_raw, self::allowed_fits(), true ) ? $fit_raw : '';
		$rotate_raw    = isset( $override['rotate'] ) ? (int) $override['rotate'] : 0;
		$rotate        = in_array( $rotate_raw, self::allowed_rotations(), true ) ? $rotate_raw : 0;
		$class         = self::sanitize_class_list( $default_class );
		$attachment_id = ! empty( $override['attachment_id'] ) ? (int) $override['attachment_id'] : 0;

		$args  = is_array( $args ) ? $args : array();
		$size  = ! empty( $args['size'] ) ? sanitize_key( $args['size'] ) : 'large';
		$eager = ! empty( $args['eager'] );

		// L'attachment_id puo' arrivare gia' noto dal chiamante (caso tipico:
		// una migrazione da wp_get_attachment_image()/mama_img, che conosce
		// l'ID originale) - ha priorita' sulla ricerca per URL, che FALLISCE
		// per gli URL di dimensioni ridimensionate (es. "...-1024x1024.webp":
		// attachment_url_to_postid() risolve solo il file originale caricato).
		if ( ! $attachment_id && ! empty( $args['attachment_id'] ) ) {
			$attachment_id = (int) $args['attachment_id'];
		}

		// Ultima risorsa: prova a risalire dall'URL (funziona solo se $src e'
		// il file originale, non una dimensione derivata).
		if ( ! $attachment_id && $src ) {
			$maybe_id = attachment_url_to_postid( $src );
			if ( $maybe_id ) {
				$attachment_id = $maybe_id;
			}
		}

		$align_style = '';
		if ( 'left' === $align || 'right' === $align ) {
			$align_style = 'text-align:' . $align . ';';
		} elseif ( 'center' === $align ) {
			$align_style = 'text-align:center;';
		}

		// Style inline sull'<img> stesso: SOLO se fit/rotate sono stati
		// esplicitamente impostati dall'editor. Altrimenti resta vuoto e il
		// tema controlla tutto via CSS/class, come da 2.7.2/2.7.3.
		$img_style = '';
		if ( $fit ) {
			$img_style .= 'object-fit:' . $fit . ';';
		}
		if ( $rotate ) {
			$img_style .= 'transform:rotate(' . $rotate . 'deg);';
		}

		$img = self::build_img_tag( $attachment_id, $src, $alt, $class, $size, $eager, $img_style );

		if ( self::user_can_edit() ) {
			$html  = '<span class="scc-editable-image" data-scc-edit-image="' . esc_attr( $id ) . '"';
			$html .= ' data-scc-align="' . esc_attr( $align ) . '"';
			$html .= ' data-scc-fit="' . esc_attr( $fit ) . '"';
			$html .= ' data-scc-rotate="' . esc_attr( $rotate ) . '"';
			$html .= $align_style ? ' style="' . esc_attr( $align_style ) . '"' : '';
			$html .= '>';
			$html .= $img;
			$html .= '<button type="button" class="scc-image-edit-btn">' . esc_html__( 'Cambia immagine', 'site-core' ) . '</button>';
			$html .= '<span class="scc-image-align-controls">';
			$html .= '<button type="button" data-scc-align-set="left" title="' . esc_attr__( 'Allinea a sinistra', 'site-core' ) . '">&#8676;</button>';
			$html .= '<button type="button" data-scc-align-set="center" title="' . esc_attr__( 'Centra', 'site-core' ) . '">&#8942;</button>';
			$html .= '<button type="button" data-scc-align-set="right" title="' . esc_attr__( 'Allinea a destra', 'site-core' ) . '">&#8677;</button>';
			$html .= '</span>';
			$html .= '<span class="scc-image-extra-controls">';
			$html .= '<button type="button" class="scc-image-rotate-btn" title="' . esc_attr__( 'Ruota 90°', 'site-core' ) . '">&#8635;</button>';
			$html .= '<select class="scc-image-fit-select" title="' . esc_attr__( 'Riempimento', 'site-core' ) . '">';
			foreach ( self::fit_labels() as $fit_value => $fit_label ) {
				$html .= '<option value="' . esc_attr( $fit_value ) . '"' . selected( $fit, $fit_value, false ) . '>' . esc_html( $fit_label ) . '</option>';
			}
			$html .= '</select>';
			$html .= '</span>';
			$html .= '</span>';
		} elseif ( $align_style ) {
			$html = '<span class="scc-image-wrap" style="' . esc_attr( $align_style ) . '">' . $img . '</span>';
		} else {
			$html = $img;
		}

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito sopra con esc_url/esc_attr/wp_get_attachment_image.
		}
		return $html;
	}

	/**
	 * Costruisce il tag <img>. Se l'attachment e' risolvibile usa
	 * wp_get_attachment_image() (width/height/srcset/sizes/lazy-load reali,
	 * stesso motore del resto del tema). Altrimenti ripiega su un <img>
	 * semplice. $extra_style (object-fit/transform) viene applicato in
	 * entrambi i casi, ma resta vuoto finche' l'editor non lo imposta.
	 */
	private static function build_img_tag( $attachment_id, $src, $alt, $class, $size, $eager, $extra_style = '' ) {
		if ( $attachment_id > 0 ) {
			$attr = array(
				'decoding' => 'async',
			);
			$attr['class'] = $class;
			if ( '' !== $alt ) {
				$attr['alt'] = $alt;
			}
			if ( $eager ) {
				$attr['loading']       = 'eager';
				$attr['fetchpriority'] = 'high';
			} else {
				$attr['loading'] = 'lazy';
			}
			if ( $extra_style ) {
				$attr['style'] = $extra_style;
			}
			$html = wp_get_attachment_image( $attachment_id, $size, false, $attr );
			if ( $html ) {
				return $html;
			}
		}

		$class_attr = $class ? ' class="' . esc_attr( $class ) . '"' : '';
		$style_attr = $extra_style ? ' style="' . esc_attr( $extra_style ) . '"' : '';
		return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . $class_attr . $style_attr . ' />';
	}

	public static function user_can_edit() {
		return SCC_Editable_Text::is_editing();
	}

	/**
	 * Shortcode [scc_image id="pagina:sezione:campo" src="..." alt="..." class="..." size="full" eager="1"]
	 * per usare le immagini editabili dentro il contenuto delle pagine.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'            => '',
				'src'           => '',
				'alt'           => '',
				'class'         => '',
				'size'          => 'large',
				'eager'         => '',
				'attachment_id' => '',
			),
			$atts,
			'scc_image'
		);
		if ( '' === SCC_Editable_Text::clean_id( $atts['id'] ) ) {
			return '';
		}
		$args = array(
			'size'          => $atts['size'],
			'eager'         => ! empty( $atts['eager'] ),
			'attachment_id' => (int) $atts['attachment_id'],
		);
		return self::render( $atts['id'], $atts['src'], $atts['alt'], $atts['class'], $args, false );
	}

	/* ---------------- REST ---------------- */

	public function register_routes() {
		register_rest_route(
			'scc/v1',
			'/image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save' ),
				'permission_callback' => function () {
					// Stessa regola di SCC_Editable_Text: la licenza blocca solo
					// la SCRITTURA, mai la lettura di un'immagine gia' salvata.
					if ( class_exists( 'SCC_License' ) && ! SCC_License::is_active() ) {
						return false;
					}
					return current_user_can( SCC_Editable_Text::CAPABILITY );
				},
			)
		);
	}

	public function handle_save( WP_REST_Request $request ) {
		$id = SCC_Editable_Text::clean_id( $request->get_param( 'id' ) );
		if ( '' === $id ) {
			return new WP_Error( 'scc_bad_id', __( 'ID mancante o non valido.', 'site-core' ), array( 'status' => 400 ) );
		}

		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$align         = (string) $request->get_param( 'align' );
		$align         = in_array( $align, self::allowed_aligns(), true ) ? $align : '';

		$fit = (string) $request->get_param( 'fit' );
		$fit = in_array( $fit, self::allowed_fits(), true ) ? $fit : '';

		$rotate = (int) $request->get_param( 'rotate' );
		$rotate = in_array( $rotate, self::allowed_rotations(), true ) ? $rotate : 0;

		$all      = self::get_all();
		$existing = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();

		// Un attachment_id valido aggiorna l'immagine; 0/mancante lascia
		// quella gia' salvata (permette di cambiare solo allineamento/fit/rotazione).
		if ( $attachment_id > 0 ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( ! $url ) {
				return new WP_Error( 'scc_bad_attachment', __( 'Immagine non valida.', 'site-core' ), array( 'status' => 400 ) );
			}
			$existing['src']           = $url;
			$existing['attachment_id'] = $attachment_id;
			$alt_text                  = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $alt_text ) {
				$existing['alt'] = sanitize_text_field( $alt_text );
			}
		}
		$existing['align']  = $align;
		$existing['fit']    = $fit;
		$existing['rotate'] = $rotate;

		$all[ $id ] = $existing;
		update_option( self::OPTION_KEY, $all );

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'src'     => $existing['src'] ?? '',
				'alt'     => $existing['alt'] ?? '',
				'align'   => $align,
				'fit'     => $fit,
				'rotate'  => $rotate,
			),
			200
		);
	}
}

/**
 * Helper globale per i TEMPLATE PHP: stampa un'immagine editabile.
 *
 *   <?php scc_image( 'home:hero:foto', 'https://.../default.jpg', 'Foto team', 'cover__media__img--basso', array( 'size' => 'full', 'eager' => true ) ); ?>
 */
function scc_image( $id, $default_src = '', $default_alt = '', $default_class = '', $args = array() ) {
	return SCC_Editable_Image::render( $id, $default_src, $default_alt, $default_class, $args, true );
}

/** Variante che ritorna la stringa (gia' resa) invece di stamparla. */
function scc_get_image( $id, $default_src = '', $default_alt = '', $default_class = '', $args = array() ) {
	return SCC_Editable_Image::render( $id, $default_src, $default_alt, $default_class, $args, false );
}
