<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery management admin page at /vis/delivery.
 * Gated behind vis_shop_delivery capability.
 * Two tabs: "Delivery Orders" and "Delivery Methods".
 */
class PCIO_VIS_Products_Delivery_Admin {

	public function register(): void {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_action( 'template_redirect', [ $this, 'serve' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_rule( '^vis/delivery/?$', 'index.php?pcio_mep_delivery=1', 'top' );
		add_rewrite_tag( '%pcio_mep_delivery%', '([0-9]+)' );
	}

	public function serve(): void {
		if ( ! get_query_var( 'pcio_mep_delivery' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		if ( ! current_user_can( 'vis_shop_delivery' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'pcio-vis-products' ),
				esc_html__( 'Access denied', 'pcio-vis-products' ),
				[ 'response' => 403 ]
			);
		}

		$this->enqueue_assets();

		status_header( 200 );
		nocache_headers();
		require PCIO_VIS_PRODUCTS_DIR . 'templates/delivery-admin.php';
		exit;
	}

	private function enqueue_assets(): void {
		wp_enqueue_style( 'pcio-me-app', plugins_url( 'assets/members.css', PCIO_VIS_PLUGIN_FILE ), [], '1.4.0' );
		wp_enqueue_style(
			'pcio-mep-delivery-admin',
			plugins_url( 'assets/delivery-admin.css', PCIO_VIS_PRODUCTS_FILE ),
			[ 'pcio-me-app' ],
			PCIO_VIS_PRODUCTS_VERSION . '.2'
		);
		wp_enqueue_script(
			'pcio-mep-delivery-admin',
			plugins_url( 'assets/delivery-admin.js', PCIO_VIS_PRODUCTS_FILE ),
			[],
			PCIO_VIS_PRODUCTS_VERSION . '.4',
			true
		);
		wp_add_inline_script(
			'pcio-mep-delivery-admin',
			'window.PCIO_VIS_Products_DELIVERY = ' . wp_json_encode( [
				'rest'      => rest_url( PCIO_VIS_REST_NS . '/shop' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'currency'  => PCIO_VIS_Products_Plugin::currency(),
				'canManage' => current_user_can( 'vis_shop_manage' ),
				'i18n'      => [
					// Tabs
					'tabOrders'      => __( 'Delivery Orders', 'pcio-vis-products' ),
					'tabMethods'     => __( 'Delivery Methods', 'pcio-vis-products' ),
					// Orders table
					'colOrder'       => __( 'Order', 'pcio-vis-products' ),
					'colCustomer'    => __( 'Customer', 'pcio-vis-products' ),
					'colProduct'     => __( 'Product', 'pcio-vis-products' ),
					'colMethod'      => __( 'Delivery method', 'pcio-vis-products' ),
					'colDate'        => __( 'Date', 'pcio-vis-products' ),
					'colStatus'      => __( 'Status', 'pcio-vis-products' ),
					'statusPending'  => __( 'Pending', 'pcio-vis-products' ),
					'statusDelivered'=> __( 'Delivered', 'pcio-vis-products' ),
					'markDelivered'  => __( 'Mark delivered', 'pcio-vis-products' ),
					'markPending'    => __( 'Mark pending', 'pcio-vis-products' ),
					'filterAll'      => __( 'All statuses', 'pcio-vis-products' ),
					'filterMethod'   => __( 'All methods', 'pcio-vis-products' ),
					'noOrders'       => __( 'No delivery orders yet.', 'pcio-vis-products' ),
					// Methods CRUD
					'newMethod'      => __( '+ New method', 'pcio-vis-products' ),
					'colName'        => __( 'Name', 'pcio-vis-products' ),
					'colDescription' => __( 'Description', 'pcio-vis-products' ),
					'colPrice'       => __( 'Extra price', 'pcio-vis-products' ),
					'colActive'      => __( 'Active', 'pcio-vis-products' ),
					'colSort'        => __( 'Sort', 'pcio-vis-products' ),
					'noMethods'      => __( 'No delivery methods yet.', 'pcio-vis-products' ),
					'createMethod'   => __( 'New delivery method', 'pcio-vis-products' ),
					'editMethod'     => __( 'Edit delivery method', 'pcio-vis-products' ),
					'fieldName'      => __( 'Name', 'pcio-vis-products' ),
					'phName'         => __( 'e.g. By mail', 'pcio-vis-products' ),
					'fieldDesc'      => __( 'Description', 'pcio-vis-products' ),
					'phDesc'         => __( 'Short description shown to members', 'pcio-vis-products' ),
					'fieldPrice'     => __( 'Extra price', 'pcio-vis-products' ),
					'priceHint'      => __( 'Additional charge on top of the product price (0 = included).', 'pcio-vis-products' ),
					'fieldActive'    => __( 'Active', 'pcio-vis-products' ),
					'fieldSort'      => __( 'Sort order', 'pcio-vis-products' ),
					// Common
					'save'           => __( 'Save', 'pcio-vis-products' ),
					'cancel'         => __( 'Cancel', 'pcio-vis-products' ),
					'edit'           => __( 'Edit', 'pcio-vis-products' ),
					'delete'         => __( 'Delete', 'pcio-vis-products' ),
					'confirmDelete'  => __( 'Delete this delivery method?', 'pcio-vis-products' ),
					'saveFailed'     => __( 'Save failed.', 'pcio-vis-products' ),
					'search'         => __( 'Search…', 'pcio-vis-products' ),
					'free'           => __( 'Free', 'pcio-vis-products' ),
					'yes'            => __( 'Yes', 'pcio-vis-products' ),
					'no'             => __( 'No', 'pcio-vis-products' ),
					'colAddress'     => __( 'Address', 'pcio-vis-products' ),
				],
			] ) . ';',
			'before'
		);
	}
}
