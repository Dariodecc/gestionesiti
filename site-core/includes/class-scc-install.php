<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates/upgrades the two dedicated tables Site Core owns.
 * Never wp_postmeta or wp_options for entries , a dedicated table is what
 * keeps the Entries screen's filters (form/date/status) fast at scale, and
 * keeps this plugin's data self-contained per site ($wpdb->prefix, no
 * collision risk across multiple site-core installs on different sites).
 */
class SCC_Install {

	public static function activate() {
		self::create_tables();
		update_option( 'scc_db_version', SCC_VERSION );
		// MCP grants are exclusively managed by the hub.
		// Developer provisioning is explicit; activating a plugin never grants MCP access.
	}

	/** Legacy upgrade hook: no role is created anymore. */
	public static function crea_ruolo_developer() {}

	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$entries_table    = $wpdb->prefix . 'scc_entries';
		$logs_table       = $wpdb->prefix . 'scc_logs';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_entries = "CREATE TABLE {$entries_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_slug VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			data LONGTEXT NOT NULL,
			ip_hash VARCHAR(64) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_slug (form_slug),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED DEFAULT NULL,
			form_slug VARCHAR(191) NOT NULL,
			stage VARCHAR(40) NOT NULL,
			result VARCHAR(20) NOT NULL,
			message TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY form_slug (form_slug),
			KEY stage (stage)
		) {$charset_collate};";

		dbDelta( $sql_entries );
		dbDelta( $sql_logs );
	}

	/**
	 * Called on 'plugins_loaded' so an upgrade (not just a fresh activation)
	 * also gets the tables/columns it needs when SCC_VERSION bumps.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'scc_db_version' ) !== SCC_VERSION ) {
			self::create_tables();
			update_option( 'scc_db_version', SCC_VERSION );
		}
	}
}
// Sostituito da SCC_Upgrade (2.2.0): scriveva la versione anche se
// una migrazione dati falliva, e non sapeva fare passaggi per versione.
// add_action( 'plugins_loaded', array( 'SCC_Install', 'maybe_upgrade' ) );
