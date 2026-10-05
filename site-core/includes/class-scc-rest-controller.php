<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX transport: POST /wp-json/scc/v1/submit/<slug>
 * Public + anonymous by design (form submission has to work for logged-out
 * visitors) but rate-limited per IP inside SCC_Form_Handler, and every
 * argument is sanitized/validated against the registered field schema
 * before anything is written.
 */
class SCC_REST_Controller {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'scc/v1',
			'/submit/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	public function handle_submit( WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );

		if ( ! SCC_Form_Registry::exists( $slug ) ) {
			return new WP_Error( 'scc_unknown_form', __( 'This form is not configured.', 'site-core' ), array( 'status' => 404 ) );
		}

		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			$body = $request->get_body_params();
		}

		$result = SCC_Form_Handler::process( $slug, (array) $body );

		$status = isset( $result['http_status'] ) ? (int) $result['http_status'] : 200;
		unset( $result['http_status'] );

		return new WP_REST_Response( $result, $status );
	}
}

/**
 * PHP-reload transport fallback (no JS/fetch): a <form> can post directly to
 * admin-post.php with action=scc_submit_form and a hidden scc_form_slug
 * field instead of using the REST endpoint. Same SCC_Form_Handler::process()
 * underneath, so security/validation logic is never duplicated.
 */
function scc_handle_admin_post_submit() {
	$slug = isset( $_POST['scc_form_slug'] ) ? sanitize_key( wp_unslash( $_POST['scc_form_slug'] ) ) : '';
	$result = SCC_Form_Handler::process( $slug, wp_unslash( $_POST ) );

	$redirect_base = wp_get_referer() ? wp_get_referer() : home_url();

	if ( ! empty( $result['redirect'] ) ) {
		wp_safe_redirect( $result['redirect'] );
		exit;
	}

	$redirect = add_query_arg(
		array(
			'scc_form'    => $slug,
			'scc_status'  => ! empty( $result['success'] ) ? 'success' : 'error',
			'scc_message' => rawurlencode( isset( $result['message'] ) ? $result['message'] : '' ),
		),
		$redirect_base
	);
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_nopriv_scc_submit_form', 'scc_handle_admin_post_submit' );
add_action( 'admin_post_scc_submit_form', 'scc_handle_admin_post_submit' );

