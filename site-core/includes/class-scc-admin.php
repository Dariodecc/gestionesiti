<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu: Form Configuration + Entries only.
 *
 * Theme Settings (colors/fonts via wp_theme_json_data_theme) is deliberately
 * NOT part of this plugin as of v2.0.0 , that direction now comes from
 * wp-design-tokens / Novamira's DESIGN.md. Site Core is scoped to owning
 * forms end to end and nothing else, so it stays a small, auditable
 * dependency on every site regardless of which design/build pipeline that
 * site uses.
 */
class SCC_Admin {

	const OPTION_KEY = 'scc_form_configs';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_scc_save_form_config', array( $this, 'save_form_config' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Site Core', 'site-core' ),
			__( 'Site Core', 'site-core' ),
			'manage_options',
			'scc-entries',
			array( $this, 'render_entries_page' ),
			'dashicons-feedback',
			58
		);
		add_submenu_page(
			'scc-entries',
			__( 'Entries', 'site-core' ),
			__( 'Entries', 'site-core' ),
			'manage_options',
			'scc-entries',
			array( $this, 'render_entries_page' )
		);
		add_submenu_page(
			'scc-entries',
			__( 'Form Configuration', 'site-core' ),
			__( 'Form Configuration', 'site-core' ),
			'manage_options',
			'scc-form-config',
			array( $this, 'render_form_config_page' )
		);
	}

	/* ---------------------------------------------------------------- */
	/* Form Configuration                                                */
	/* ---------------------------------------------------------------- */

	public static function get_all_form_configs() {
		$configs = get_option( self::OPTION_KEY, array() );
		return is_array( $configs ) ? $configs : array();
	}

	public static function get_form_config( $slug ) {
		$slug    = sanitize_key( $slug );
		$configs = self::get_all_form_configs();
		return isset( $configs[ $slug ] ) ? $configs[ $slug ] : array(
			'transport'        => 'ajax',
			'webhook_enabled'  => false,
			'webhook_url'      => '',
			'success_behavior' => 'inline',
			'success_message'  => '',
			'success_redirect' => '',
		);
	}

	public function save_form_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'site-core' ) );
		}
		check_admin_referer( 'scc_save_form_config' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';
		if ( empty( $slug ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=scc-form-config' ) );
			exit;
		}

		$configs = self::get_all_form_configs();
		$prima   = self::get_form_config( $slug );

		// A licenza non attiva Transport e Redirect non compaiono nel modulo
		// (vedi render_form_config_page()): anche se qualcuno scrivesse questi
		// campi a mano in una richiesta POST, restano quelli gia' salvati.
		// Il modulo nativo deve continuare a funzionare uguale a prima, non
		// cambiare comportamento mentre la licenza e' ferma.
		$licenza_attiva = ! class_exists( 'SCC_License' ) || SCC_License::is_active();

		if ( $licenza_attiva ) {
			$transport        = isset( $_POST['transport'] ) && 'php' === $_POST['transport'] ? 'php' : 'ajax';
			$success_behavior = isset( $_POST['success_behavior'] ) && 'redirect' === $_POST['success_behavior'] ? 'redirect' : 'inline';
			$success_redirect = isset( $_POST['success_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['success_redirect'] ) ) : '';
		} else {
			$transport        = $prima['transport'];
			$success_behavior = $prima['success_behavior'];
			$success_redirect = $prima['success_redirect'];
		}

		$configs[ $slug ] = array(
			'transport'        => $transport,
			'webhook_enabled'  => ! empty( $_POST['webhook_enabled'] ),
			'webhook_url'      => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
			'success_behavior' => $success_behavior,
			'success_message'  => isset( $_POST['success_message'] ) ? sanitize_text_field( wp_unslash( $_POST['success_message'] ) ) : '',
			'success_redirect' => $success_redirect,
		);
		update_option( self::OPTION_KEY, $configs );

		wp_safe_redirect( admin_url( 'admin.php?page=scc-form-config&saved=' . $slug ) );
		exit;
	}

	public function render_form_config_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$forms = SCC_Form_Registry::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form Configuration', 'site-core' ); ?></h1>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php printf( esc_html__( 'Configuration saved for "%s".', 'site-core' ), esc_html( sanitize_key( $_GET['saved'] ) ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $forms ) ) : ?>
				<p><?php esc_html_e( 'No forms are registered yet. Forms register themselves via the scc_register_forms action , nothing to configure until at least one is registered on this site.', 'site-core' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $forms as $slug => $def ) :
				$cfg = self::get_form_config( $slug );
				$licenza_attiva = ! class_exists( 'SCC_License' ) || SCC_License::is_active();
				?>
				<h2><?php echo esc_html( $def['label'] ); ?> <code><?php echo esc_html( $slug ); ?></code></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'scc_save_form_config' ); ?>
					<input type="hidden" name="action" value="scc_save_form_config" />
					<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
					<table class="form-table">
						<?php if ( $licenza_attiva ) : ?>
						<tr>
							<th><?php esc_html_e( 'Transport', 'site-core' ); ?></th>
							<td>
								<label><input type="radio" name="transport" value="ajax" <?php checked( $cfg['transport'], 'ajax' ); ?> /> <?php esc_html_e( 'AJAX (fetch, no page reload)', 'site-core' ); ?></label><br />
								<label><input type="radio" name="transport" value="php" <?php checked( $cfg['transport'], 'php' ); ?> /> <?php esc_html_e( 'PHP reload (admin-post.php)', 'site-core' ); ?></label>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'Webhook', 'site-core' ); ?></th>
							<td>
								<label><input type="checkbox" name="webhook_enabled" value="1" <?php checked( ! empty( $cfg['webhook_enabled'] ) ); ?> /> <?php esc_html_e( 'Enabled', 'site-core' ); ?></label><br />
								<input type="url" name="webhook_url" class="regular-text" placeholder="https://…" value="<?php echo esc_attr( $cfg['webhook_url'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Success behavior', 'site-core' ); ?></th>
							<td>
								<label><input type="radio" name="success_behavior" value="inline" <?php checked( $cfg['success_behavior'], 'inline' ); ?> /> <?php esc_html_e( 'Inline message', 'site-core' ); ?></label>
								<input type="text" name="success_message" class="regular-text" placeholder="<?php esc_attr_e( 'Thanks , your submission has been received.', 'site-core' ); ?>" value="<?php echo esc_attr( $cfg['success_message'] ); ?>" /><br />
								<?php if ( $licenza_attiva ) : ?><br />
								<label><input type="radio" name="success_behavior" value="redirect" <?php checked( $cfg['success_behavior'], 'redirect' ); ?> /> <?php esc_html_e( 'Redirect', 'site-core' ); ?></label>
								<input type="url" name="success_redirect" class="regular-text" placeholder="https://…" value="<?php echo esc_attr( $cfg['success_redirect'] ); ?>" />
							<?php endif; ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'site-core' ) ); ?>
				</form>
				<hr />
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Entries                                                           */
	/* ---------------------------------------------------------------- */

	public function render_entries_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( $_GET['entry'] ) ) {
			$this->render_entry_detail( (int) $_GET['entry'] );
			return;
		}

		$table = new SCC_Entries_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form Entries', 'site-core' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="scc-entries" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private function render_entry_detail( $entry_id ) {
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scc_entries WHERE id = %d", $entry_id ), ARRAY_A );

		if ( ! $entry ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Entry not found.', 'site-core' ) . '</p></div>';
			return;
		}

		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scc_logs WHERE entry_id = %d ORDER BY id ASC", $entry_id ), ARRAY_A );
		$data = json_decode( $entry['data'], true );
		?>
		<div class="wrap">
			<h1><?php printf( esc_html__( 'Entry #%d', 'site-core' ), $entry_id ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=scc-entries' ) ); ?>">&larr; <?php esc_html_e( 'Back to entries', 'site-core' ); ?></a></p>

			<h2><?php esc_html_e( 'Submitted data', 'site-core' ); ?></h2>
			<table class="widefat striped">
				<?php foreach ( (array) $data as $k => $v ) : ?>
					<tr>
						<th style="width:200px;"><?php echo esc_html( $k ); ?></th>
						<td><?php echo esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Pipeline log', 'site-core' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Stage', 'site-core' ); ?></th>
					<th><?php esc_html_e( 'Result', 'site-core' ); ?></th>
					<th><?php esc_html_e( 'Message', 'site-core' ); ?></th>
					<th><?php esc_html_e( 'When', 'site-core' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['stage'] ); ?></td>
						<td><?php echo esc_html( $log['result'] ); ?></td>
						<td><?php echo esc_html( $log['message'] ); ?></td>
						<td><?php echo esc_html( $log['created_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

