<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for delivery methods and delivery order management.
 *
 * Public:
 *   GET  /shop/delivery-methods
 *
 * Shop manager (vis_shop_manage):
 *   POST   /shop/delivery-methods
 *   PUT    /shop/delivery-methods/{id}
 *   DELETE /shop/delivery-methods/{id}
 *
 * Delivery manager (vis_shop_delivery):
 *   GET   /shop/delivery-orders
 *   PATCH /shop/delivery-orders/{id}
 */
class PCIO_VIS_Products_Delivery_Rest {

	public function register(): void {
		$ns = PCIO_VIS_REST_NS;

		register_rest_route( $ns, '/shop/delivery-methods', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_methods' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_method' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'name'        => [ 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
					'description' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
					'price_cents' => [ 'type' => 'integer', 'required' => false, 'default' => 0, 'minimum' => 0 ],
					'active'      => [ 'type' => 'boolean', 'required' => false, 'default' => true ],
				],
			],
		] );

		register_rest_route( $ns, '/shop/delivery-methods/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_method' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_method' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
		] );

		register_rest_route( $ns, '/shop/delivery-orders', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_delivery_orders' ],
				'permission_callback' => [ $this, 'can_delivery' ],
				'args'                => [
					'status'    => [ 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'method_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0, 'minimum' => 0 ],
				],
			],
		] );

		register_rest_route( $ns, '/shop/delivery-orders/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_delivery_order' ],
				'permission_callback' => [ $this, 'can_delivery' ],
				'args'                => [
					'id'              => self::id_arg(),
					'delivery_status' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );
	}

	// ── Permissions ───────────────────────────────────────────────

	public function can_manage(): bool {
		return current_user_can( 'vis_shop_manage' );
	}

	public function can_delivery(): bool {
		return current_user_can( 'vis_shop_delivery' );
	}

	// ── Handlers: delivery methods ────────────────────────────────

	public function list_methods(): WP_REST_Response {
		return new WP_REST_Response( PCIO_VIS_Products_Delivery_DB::get_active(), 200 );
	}

	public function create_method( WP_REST_Request $req ): WP_REST_Response {
		$body = (array) $req->get_json_params();
		$name = sanitize_text_field( $body['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Method name is required.', 'pcio-vis-products' ) ],
				422
			);
		}
		$id = PCIO_VIS_Products_Delivery_DB::create( $body );
		if ( ! $id ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Could not create delivery method.', 'pcio-vis-products' ) ],
				500
			);
		}
		return new WP_REST_Response( PCIO_VIS_Products_Delivery_DB::get( $id ), 201 );
	}

	public function update_method( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = (array) $req->get_json_params();
		if ( ! PCIO_VIS_Products_Delivery_DB::get( $id ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Delivery method not found.', 'pcio-vis-products' ) ], 404 );
		}
		PCIO_VIS_Products_Delivery_DB::update( $id, $body );
		return new WP_REST_Response( PCIO_VIS_Products_Delivery_DB::get( $id ), 200 );
	}

	public function delete_method( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! PCIO_VIS_Products_Delivery_DB::get( $id ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Delivery method not found.', 'pcio-vis-products' ) ], 404 );
		}
		PCIO_VIS_Products_Delivery_DB::delete( $id );
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	// ── Handlers: delivery orders ─────────────────────────────────

	public function list_delivery_orders( WP_REST_Request $req ): WP_REST_Response {
		$args = [
			'status'    => (string) $req->get_param( 'status' ),
			'method_id' => (int) $req->get_param( 'method_id' ),
		];
		return new WP_REST_Response( PCIO_VIS_Products_Delivery_DB::get_delivery_orders( $args ), 200 );
	}

	public function update_delivery_order( WP_REST_Request $req ): WP_REST_Response {
		$id     = (int) $req->get_param( 'id' );
		$status = sanitize_text_field( (string) $req->get_param( 'delivery_status' ) );

		if ( ! in_array( $status, PCIO_VIS_Products_Delivery_DB::DELIVERY_STATUSES, true ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Invalid delivery status.', 'pcio-vis-products' ) ],
				422
			);
		}
		if ( ! PCIO_VIS_Products_Delivery_DB::update_delivery_status( $id, $status ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Could not update delivery status.', 'pcio-vis-products' ) ],
				500
			);
		}
		$orders = PCIO_VIS_Products_Delivery_DB::get_delivery_orders( [] );
		foreach ( $orders as $o ) {
			if ( (int) $o['id'] === $id ) {
				return new WP_REST_Response( $o, 200 );
			}
		}
		return new WP_REST_Response( [ 'id' => $id, 'delivery_status' => $status ], 200 );
	}

	private static function id_arg(): array {
		return [
			'validate_callback' => static fn( $v ) => is_numeric( $v ),
			'sanitize_callback' => 'absint',
		];
	}
}
