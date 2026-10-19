<?php
/**
 * Plugin Name: SCC Hub
 * Description: Hub dei siti Site Core. Il sito si crea solo con il nome del cliente: l'indirizzo arriva dal primo battito.
 * Version: 0.1.0
 *
 * @package scc-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Hub_App {

	const CPT = 'scchub_sito';

	public function __construct() {
		add_action( 'init', array( $this, 'registra' ) );
		add_action( 'rest_api_init', array( $this, 'rotte' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_scchub_crea', array( $this, 'crea' ) );
	}

	public function registra(): void {
		register_post_type( self::CPT, array( 'public' => false, 'label' => 'Siti' ) );
	}

	/* ---------------- Battito ---------------- */

	public function rotte(): void {
		register_rest_route(
			'scchub/v1',
			'/battito',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'battito' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function trova_sito( string $chiave ): ?int {
		if ( '' === $chiave ) {
			return null;
		}
		$q = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_chiave_hash',
				'meta_value'     => hash( 'sha256', 'scchub|' . $chiave ),
			)
		);
		return $q ? (int) $q[0] : null;
	}

	public function battito( WP_REST_Request $r ) {
		$id = $this->trova_sito( (string) $r->get_header( 'X-Licence-Key' ) );
		if ( ! $id ) {
			return new WP_REST_Response( array( 'errore' => 'chiave non valida' ), 401 );
		}

		$dati = (array) $r->get_json_params();

		// L'indirizzo lo manda il sito stesso: non si scrive a mano.
		$url = esc_url_raw( (string) ( $dati['url'] ?? $r->get_header( 'X-Site-Url' ) ) );
		if ( $url ) {
			update_post_meta( $id, '_url', untrailingslashit( $url ) );
		}

		update_post_meta( $id, '_dati', $dati );
		update_post_meta( $id, '_ultimo_battito', time() );

		return new WP_REST_Response( array(), 200 );
	}

	/* ---------------- Admin ---------------- */

	public function menu(): void {
		add_menu_page( 'Siti', 'Siti', 'manage_options', 'scchub-siti', array( $this, 'schermata' ), 'dashicons-admin-site', 3 );
	}

	/** Nome del sito + referente: nessun campo per l'indirizzo. */
	public function crea(): void {
		check_admin_referer( 'scchub_crea' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Non autorizzato' );
		}

		$cliente = sanitize_text_field( wp_unslash( $_POST['cliente'] ?? '' ) );
		if ( '' === $cliente ) {
			wp_safe_redirect( admin_url( 'admin.php?page=scchub-siti' ) );
			exit;
		}

		$chiave = wp_generate_password( 32, false );
		$id     = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $cliente ) );
		update_post_meta( $id, '_chiave_hash', hash( 'sha256', 'scchub|' . $chiave ) );

		// La chiave si mostra una volta sola, da incollare in Site Core.
		set_transient( 'scchub_chiave_' . get_current_user_id(), $chiave, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=scchub-siti&nuovo=' . $id ) );
		exit;
	}

	public function schermata(): void {
		$chiave = get_transient( 'scchub_chiave_' . get_current_user_id() );
		$siti   = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1 ) );
		?>
		<div class="wrap">
			<h1>Siti</h1>
			<?php if ( $chiave && isset( $_GET['nuovo'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p>Sito creato. Chiave licenza (mostrata una volta): <code><?php echo esc_html( $chiave ); ?></code><br>Incollala in Site Core: l'indirizzo del sito arriva da solo al primo battito.</p></div>
				<?php delete_transient( 'scchub_chiave_' . get_current_user_id() ); ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scchub_crea">
				<?php wp_nonce_field( 'scchub_crea' ); ?>
				<input type="text" name="cliente" placeholder="Nome cliente" required>
				<button class="button button-primary">Nuovo sito</button>
			</form>

			<table class="widefat striped" style="margin-top:16px">
				<thead><tr><th>Sito</th><th>Cliente</th><th>Stato</th><th>WP</th></tr></thead>
				<tbody>
				<?php foreach ( $siti as $s ) : ?>
					<?php
					$url   = (string) get_post_meta( $s->ID, '_url', true );
					$ultimo = (int) get_post_meta( $s->ID, '_ultimo_battito', true );
					$dati  = (array) get_post_meta( $s->ID, '_dati', true );
					$online = $ultimo && ( time() - $ultimo ) < 2 * HOUR_IN_SECONDS;
					?>
					<tr>
						<td>
							<?php if ( $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '#^https?://#', '', $url ) ); ?></a>
							<?php else : ?>
								<em>In attesa del primo battito</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $s->post_title ); ?></td>
						<td><?php echo $online ? 'Online' : ( $ultimo ? 'Offline' : '—' ); ?></td>
						<td><?php echo esc_html( $dati['wp'] ?? '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

new SCC_Hub_App();
