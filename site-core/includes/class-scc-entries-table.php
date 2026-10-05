<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Entries screen: WP_List_Table with filters for form/date/status.
 * Detail view (single entry, ?entry=ID) shows the per-stage log timeline
 * from wp_scc_logs, which is what makes a rejected/failed submission
 * debuggable without touching the database directly.
 */
class SCC_Entries_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'id'         => __( 'ID', 'site-core' ),
			'form_slug'  => __( 'Form', 'site-core' ),
			'summary'    => __( 'Summary', 'site-core' ),
			'status'     => __( 'Status', 'site-core' ),
			'created_at' => __( 'Submitted', 'site-core' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'form_slug'  => array( 'form_slug', false ),
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current_form = isset( $_GET['scc_form_filter'] ) ? sanitize_key( $_GET['scc_form_filter'] ) : '';
		$current_status = isset( $_GET['scc_status_filter'] ) ? sanitize_key( $_GET['scc_status_filter'] ) : '';
		?>
		<div class="alignleft actions">
			<select name="scc_form_filter">
				<option value=""><?php esc_html_e( 'All forms', 'site-core' ); ?></option>
				<?php foreach ( $this->get_distinct_slugs() as $slug ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_form, $slug ); ?>><?php echo esc_html( $slug ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="scc_status_filter">
				<option value=""><?php esc_html_e( 'All statuses', 'site-core' ); ?></option>
				<?php foreach ( array( 'received', 'read', 'spam' ) as $status ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current_status, $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'site-core' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	private function get_distinct_slugs() {
		global $wpdb;
		$table = $wpdb->prefix . 'scc_entries';
		return $wpdb->get_col( "SELECT DISTINCT form_slug FROM {$table} ORDER BY form_slug ASC" );
	}

	public function prepare_items() {
		global $wpdb;
		$table = $wpdb->prefix . 'scc_entries';

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $_GET['scc_form_filter'] ) ) {
			$where[]  = 'form_slug = %s';
			$params[] = sanitize_key( $_GET['scc_form_filter'] );
		}
		if ( ! empty( $_GET['scc_status_filter'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $_GET['scc_status_filter'] );
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = ! empty( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'created_at', 'form_slug' ), true )
			? sanitize_sql_orderby( $_GET['orderby'] )
			: 'created_at';
		$order = ! empty( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total_items = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$offset = ( $current_page - 1 ) * $per_page;
		$data_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, $query_params ), ARRAY_A );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return '#' . intval( $item['id'] );
			case 'form_slug':
				return esc_html( $item['form_slug'] );
			case 'status':
				return esc_html( ucfirst( $item['status'] ) );
			case 'created_at':
				return esc_html( $item['created_at'] );
			case 'summary':
				$data = json_decode( $item['data'], true );
				if ( ! is_array( $data ) ) {
					return '';
				}
				$parts = array();
				foreach ( array_slice( $data, 0, 2, true ) as $k => $v ) {
					$parts[] = esc_html( $k ) . ': ' . esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
				}
				$detail_url = add_query_arg( array( 'page' => 'scc-entries', 'entry' => $item['id'] ), admin_url( 'admin.php' ) );
				return implode( ', ', $parts ) . ' , <a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View', 'site-core' ) . '</a>';
			default:
				return '';
		}
	}
}

