<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the Products (shop) extension (namespace pcio-vis/v1).
 *
 * Admin (vis_shop_manage)
 *   GET    /shop/products                products list
 *   POST   /shop/products                create product
 *   PUT    /shop/products/{id}           update product
 *   DELETE /shop/products/{id}           delete product
 *   GET    /shop/subscriptions           subscriptions list
 *   POST   /shop/subscriptions           create subscription (vis_manage_finance)
 *   PUT    /shop/subscriptions/{id}      update subscription (vis_manage_finance)
 *   DELETE /shop/subscriptions/{id}      delete subscription (vis_manage_finance)
 *   GET    /shop/orders                  Finance "Shop Orders" list
 *   POST   /shop/orders/{id}/refund      refund an order (vis_shop_refund)
 *
 * Public
 *   GET    /shop/catalog?group=          active products for the storefront
 *   GET    /shop/catalog/{id}            single product detail
 *   POST   /shop/checkout                cart → Stripe session (or free)
 *   POST   /shop/renew                   renewal checkout (verify_token)
 *   GET    /shop/verify?token=           licence verification (token only)
 */
class PCIO_VIS_Products_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/shop/products', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_products' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_product' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/shop/products/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_product' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_product' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/shop/subscriptions', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_subscriptions' ],
                'permission_callback' => [ $this, 'can_manage_subscriptions' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_subscription' ],
                'permission_callback' => [ $this, 'can_manage_subscriptions' ],
            ],
        ] );

        register_rest_route( $ns, '/shop/subscriptions/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_subscription_admin' ],
                'permission_callback' => [ $this, 'can_manage_subscriptions' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_subscription' ],
                'permission_callback' => [ $this, 'can_manage_subscriptions' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/shop/orders', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_orders_finance' ],
                'permission_callback' => [ $this, 'can_view_finance' ],
            ],
        ] );

        register_rest_route( $ns, '/shop/orders/(?P<id>\d+)/refund', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'refund_order' ],
                'permission_callback' => [ $this, 'can_refund' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        // Public storefront catalogue.
        register_rest_route( $ns, '/shop/catalog', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'catalog' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( $ns, '/shop/catalog/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'catalog_item' ],
                'permission_callback' => '__return_true',
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        // Public checkout — CSRF-guarded by a nonce; open to guests.
        register_rest_route( $ns, '/shop/checkout', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'checkout' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Public renewal checkout (authorised by the subscription verify token).
        register_rest_route( $ns, '/shop/renew', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'renew' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Public licence verification — the customer's plugin calls this with
        // the licence key it was given. Token-only: the key is the secret.
        register_rest_route( $ns, '/shop/verify', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'verify' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    private static function id_arg(): array {
        return [
            'validate_callback' => static fn( $v ) => is_numeric( $v ),
            'sanitize_callback' => 'absint',
        ];
    }

    // ── Permissions ───────────────────────────────────────────────

    public function can_manage(): bool {
        return current_user_can( 'vis_shop_manage' );
    }

    public function can_refund(): bool {
        return current_user_can( 'vis_shop_refund' );
    }

    public function can_view_finance(): bool {
        return current_user_can( 'vis_manage_finance' ) || current_user_can( 'vis_shop_manage' );
    }

    public function can_manage_subscriptions(): bool {
        return current_user_can( 'vis_manage_finance' ) || current_user_can( 'vis_shop_manage' );
    }

    // ── Admin: products ───────────────────────────────────────────

    public function list_products(): WP_REST_Response {
        return new WP_REST_Response( [
            'products' => PCIO_VIS_Products_DB::get_products(),
            'groups'   => PCIO_VIS_Products_DB::get_groups(),
        ], 200 );
    }

    public function create_product( WP_REST_Request $req ): WP_REST_Response {
        $body = (array) $req->get_json_params();
        $id   = PCIO_VIS_Products_DB::create_product( $body );
        if ( ! $id ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Could not create the product. The product name must be unique and non-empty.', 'pcio-vis-products' ) ],
                409
            );
        }
        return new WP_REST_Response( [ 'product' => PCIO_VIS_Products_DB::get_product( $id ) ], 201 );
    }

    public function update_product( WP_REST_Request $req ): WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $body = (array) $req->get_json_params();
        if ( ! PCIO_VIS_Products_DB::get_product( $id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Product not found.', 'pcio-vis-products' ) ], 404 );
        }
        if ( ! PCIO_VIS_Products_DB::update_product( $id, $body ) ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Could not update the product. The product name must be unique.', 'pcio-vis-products' ) ],
                409
            );
        }
        return new WP_REST_Response( [ 'product' => PCIO_VIS_Products_DB::get_product( $id ) ], 200 );
    }

    public function delete_product( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        PCIO_VIS_Products_DB::delete_product( $id );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── Admin: subscriptions ──────────────────────────────────────

    public function list_subscriptions(): WP_REST_Response {
        $rows = [];
        foreach ( PCIO_VIS_Products_DB::get_subscriptions_admin() as $sub ) {
            $rows[] = [
                'id'             => $sub['id'],
                'license_token'  => $sub['license_token'],
                'product_name'   => $sub['product_name'],
                'customer_name'  => $sub['customer_name'],
                'customer_email' => $sub['customer_email'],
                'expire_date'    => (string) $sub['expire_date'],
                'status'         => $sub['status'],
                'member_id'      => $sub['member_id'],
                'member_number'  => $sub['member_number'],
            ];
        }
        return new WP_REST_Response( [ 'subscriptions' => $rows ], 200 );
    }

    public function delete_subscription( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        PCIO_VIS_Products_DB::delete_subscription( $id );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    public function create_subscription( WP_REST_Request $req ): WP_REST_Response {
        $prepared = $this->prepare_subscription_data( (array) $req->get_json_params(), true );
        if ( is_wp_error( $prepared ) ) {
            return new WP_REST_Response( [ 'message' => $prepared->get_error_message() ], 400 );
        }
        $prepared['member_number'] = $this->resolve_membership_number( (int) ( $prepared['member_id'] ?? 0 ) );
        $id = PCIO_VIS_Products_DB::create_subscription( $prepared );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create the subscription.', 'pcio-vis-products' ) ], 500 );
        }
        if ( (int) $prepared['member_number'] > 0 && class_exists( 'PCIO_VIS_Products_Fulfillment' ) ) {
            PCIO_VIS_Products_Fulfillment::refresh_number_status( (int) $prepared['member_number'] );
        }
        return new WP_REST_Response( [ 'subscription' => PCIO_VIS_Products_DB::get_subscription( $id ) ], 201 );
    }

    /**
     * Reuse a member's membership number or allocate the next one (0 when unlinked).
     */
    private function resolve_membership_number( int $member_id ): int {
        if ( $member_id <= 0 || ! class_exists( 'PCIO_VIS_DB' ) ) {
            return 0;
        }
        $member_number = PCIO_VIS_DB::get_member_number_by_id( $member_id );
        if ( $member_number <= 0 ) {
            $member_number = PCIO_VIS_DB::get_next_free_member_number();
            PCIO_VIS_DB::set_member_number( $member_id, $member_number );
        }
        return $member_number;
    }

    public function update_subscription_admin( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        if ( ! PCIO_VIS_Products_DB::get_subscription( $id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Subscription not found.', 'pcio-vis-products' ) ], 404 );
        }
        $prepared = $this->prepare_subscription_data( (array) $req->get_json_params(), false );
        if ( is_wp_error( $prepared ) ) {
            return new WP_REST_Response( [ 'message' => $prepared->get_error_message() ], 400 );
        }
        if ( [] === $prepared ) {
            return new WP_REST_Response( [ 'message' => __( 'Nothing to update.', 'pcio-vis-products' ) ], 400 );
        }
        // Keep the membership number aligned when the linked member changes.
        if ( array_key_exists( 'member_id', $prepared ) ) {
            $prepared['member_number'] = $this->resolve_membership_number( (int) $prepared['member_id'] );
        }
        PCIO_VIS_Products_DB::update_subscription( $id, $prepared, self::subscription_formats( $prepared ) );
        $updated = PCIO_VIS_Products_DB::get_subscription( $id );
        if ( $updated && (int) ( $updated['member_number'] ?? 0 ) > 0 && class_exists( 'PCIO_VIS_Products_Fulfillment' ) ) {
            PCIO_VIS_Products_Fulfillment::refresh_number_status( (int) $updated['member_number'] );
        }
        return new WP_REST_Response( [ 'subscription' => $updated ], 200 );
    }

    /**
     * Sanitize and validate a subscription payload for create/update.
     *
     * @param array<string,mixed> $body      Raw JSON body.
     * @param bool                $is_create Whether this is a create (fills defaults).
     * @return array<string,mixed>|WP_Error
     */
    private function prepare_subscription_data( array $body, bool $is_create ) {
        $out = [];

        if ( $is_create || array_key_exists( 'product_name', $body ) ) {
            $out['product_name'] = sanitize_text_field( (string) ( $body['product_name'] ?? '' ) );
        }
        if ( $is_create || array_key_exists( 'customer_name', $body ) ) {
            $out['customer_name'] = sanitize_text_field( (string) ( $body['customer_name'] ?? '' ) );
        }
        if ( $is_create || array_key_exists( 'customer_email', $body ) ) {
            $out['customer_email'] = sanitize_email( (string) ( $body['customer_email'] ?? '' ) );
        }

        if ( $is_create || array_key_exists( 'expire_date', $body ) ) {
            $raw  = trim( (string) ( $body['expire_date'] ?? '' ) );
            $date = self::normalize_date( $raw );
            if ( '' !== $raw && '' === $date ) {
                return new WP_Error( 'bad_date', __( 'Expiry date must be a valid date (YYYY-MM-DD).', 'pcio-vis-products' ) );
            }
            // On create an empty date lets the DB default it to +1 year.
            if ( ! $is_create || '' !== $date ) {
                $out['expire_date'] = '' !== $date ? $date : null;
            }
        }

        if ( array_key_exists( 'member_number', $body ) ) {
            $num              = sanitize_text_field( (string) $body['member_number'] );
            $out['member_id'] = '' !== $num ? PCIO_VIS_Products_DB::member_id_from_number( $num ) : 0;
            if ( '' !== $num && 0 === $out['member_id'] ) {
                return new WP_Error( 'bad_member', __( 'No member found with that member number.', 'pcio-vis-products' ) );
            }
        } elseif ( array_key_exists( 'member_id', $body ) ) {
            $out['member_id'] = max( 0, (int) $body['member_id'] );
        }

        if ( $is_create || array_key_exists( 'status', $body ) ) {
            $status        = sanitize_key( (string) ( $body['status'] ?? 'active' ) );
            $out['status'] = in_array( $status, [ 'active', 'expired', 'cancelled' ], true ) ? $status : 'active';
        }

        if ( $is_create ) {
            $out['order_id'] = 0; // Manually created — not linked to a Stripe order.
        }

        return $out;
    }

    private static function normalize_date( string $value ): string {
        $value = trim( $value );
        if ( '' === $value ) {
            return '';
        }
        $ts = strtotime( $value );
        return false === $ts ? '' : gmdate( 'Y-m-d', $ts );
    }

    /**
     * $wpdb format specifiers matching the keys of a prepared subscription row.
     *
     * @param array<string,mixed> $data
     * @return string[]
     */
    private static function subscription_formats( array $data ): array {
        $formats = [];
        foreach ( array_keys( $data ) as $key ) {
            $formats[] = in_array( $key, [ 'member_id', 'order_id', 'member_number' ], true ) ? '%d' : '%s';
        }
        return $formats;
    }

    // ── Finance: orders ───────────────────────────────────────────

    public function list_orders_finance(): WP_REST_Response {
        $orders      = PCIO_VIS_Products_DB::list_orders( [ 'limit' => 1000 ] );
        $stripe_base = PCIO_VIS_Products_Plugin::stripe_mode() === 'live'
            ? 'https://dashboard.stripe.com/payments/'
            : 'https://dashboard.stripe.com/test/payments/';
        $rows = [];
        foreach ( $orders as $order ) {
            $customer = ! empty( $order['customer_id'] )
                ? PCIO_VIS_Products_DB::get_customer( (int) $order['customer_id'] )
                : null;
            $status     = (string) $order['status'];
            $total      = (int) $order['amount_cents'];
            $refunded   = (int) $order['refunded_cents'];
            $refundable = max( 0, $total - $refunded );
            $can_refund = in_array( $status, [ 'paid', 'partly_refunded' ], true ) && $refundable > 0;

            $rows[] = [
                'id'                   => (int) $order['id'],
                'entry_date'           => (string) ( $order['paid_at'] ?: $order['created'] ),
                'order_no'             => (string) $order['order_no'],
                'buyer'                => $customer && ! empty( $customer['name'] ) ? (string) $customer['name'] : (string) $order['email'],
                'email'                => (string) $order['email'],
                'summary'              => (string) $order['summary'],
                'amount_cents'         => $total,
                'refunded_cents'       => $refunded,
                'refundable_cents'     => $refundable,
                'currency'             => (string) $order['currency'],
                'status'               => self::order_status_label( $status ),
                'status_key'           => $status,
                'delivery_method_name' => (string) ( $order['delivery_method_name'] ?? '' ),
                'delivery_status'      => (string) ( $order['delivery_status'] ?? '' ),
                'stripe_url'           => ! empty( $order['stripe_payment_intent'] ) ? $stripe_base . $order['stripe_payment_intent'] : '',
                'can_refund'           => $can_refund,
            ];
        }
        return new WP_REST_Response( $rows, 200 );
    }

    public function refund_order( WP_REST_Request $req ): WP_REST_Response {
        $id    = (int) $req->get_param( 'id' );
        $order = PCIO_VIS_Products_DB::get_order( $id );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'message' => __( 'Order not found.', 'pcio-vis-products' ) ], 404 );
        }
        if ( ! in_array( (string) $order['status'], [ 'paid', 'partly_refunded' ], true ) ) {
            return new WP_REST_Response( [ 'message' => __( 'This order cannot be refunded.', 'pcio-vis-products' ) ], 409 );
        }
        $intent = (string) ( $order['stripe_payment_intent'] ?? '' );
        if ( '' === $intent ) {
            return new WP_REST_Response( [ 'message' => __( 'No payment to refund.', 'pcio-vis-products' ) ], 409 );
        }

        $body         = (array) $req->get_json_params();
        $total        = (int) $order['amount_cents'];
        $refunded     = (int) $order['refunded_cents'];
        $refundable   = max( 0, $total - $refunded );
        $amount_cents = isset( $body['amount_cents'] ) ? max( 0, (int) $body['amount_cents'] ) : $refundable;
        $amount_cents = min( $amount_cents, $refundable );

        $result = PCIO_VIS_Products_Stripe::create_refund( $intent, $amount_cents === $refundable ? 0 : $amount_cents );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 502 );
        }

        $new_refunded = $refunded + $amount_cents;
        $new_status   = $new_refunded >= $total ? 'refunded' : 'partly_refunded';
        PCIO_VIS_Products_DB::update_order(
            $id,
            [ 'refunded_cents' => $new_refunded, 'status' => $new_status, 'refunded_at' => current_time( 'mysql' ) ],
            [ '%d', '%s', '%s' ]
        );
        return new WP_REST_Response( [ 'refunded' => true ], 200 );
    }

    /** Human-readable label for an order status. */
    public static function order_status_label( string $status ): string {
        $labels = [
            'pending'         => __( 'Pending', 'pcio-vis-products' ),
            'paid'            => __( 'Paid', 'pcio-vis-products' ),
            'free_confirmed'  => __( 'Confirmed (free)', 'pcio-vis-products' ),
            'refunded'        => __( 'Refunded', 'pcio-vis-products' ),
            'partly_refunded' => __( 'Partly refunded', 'pcio-vis-products' ),
            'cancelled'       => __( 'Cancelled', 'pcio-vis-products' ),
        ];
        return $labels[ $status ] ?? $status;
    }

    // ── Public: catalogue ─────────────────────────────────────────

    public function catalog( WP_REST_Request $req ): WP_REST_Response {
        $group    = sanitize_text_field( (string) $req->get_param( 'group' ) );
        $products = PCIO_VIS_Products_DB::get_products( $group, true );
        $rows     = [];
        foreach ( $products as $p ) {
            $rows[] = [
                'id'          => $p['id'],
                'title'       => $p['title'],
                'price_cents' => $p['price_cents'],
                'currency'    => $p['currency'],
                'price'       => PCIO_VIS_Products_Plugin::format_money( $p['price_cents'], $p['currency'] ),
                'image_url'   => $p['image_url'],
                'group_name'  => $p['group_name'],
            ];
        }
        return new WP_REST_Response( [ 'products' => $rows ], 200 );
    }

    public function catalog_item( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $p  = PCIO_VIS_Products_DB::get_product( $id );
        if ( ! $p || empty( $p['active'] ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Product not found.', 'pcio-vis-products' ) ], 404 );
        }
        return new WP_REST_Response( [
            'product' => [
                'id'          => $p['id'],
                'title'       => $p['title'],
                'description' => wpautop( $p['description'] ),
                'price_cents' => $p['price_cents'],
                'currency'    => $p['currency'],
                'price'       => PCIO_VIS_Products_Plugin::format_money( $p['price_cents'], $p['currency'] ),
                'image_url'   => $p['image_url'],
            ],
        ], 200 );
    }

    // ── Public: checkout ──────────────────────────────────────────

    /**
     * Create an order from a public cart submission.
     *
     * Body: { nonce, name, email, items: [{ product_id, qty }] }
     *
     * All prices are re-validated server-side — the client total is never
     * trusted. Returns a redirect URL (Stripe session, or the accepted page for
     * free orders).
     */
    public function checkout( WP_REST_Request $req ): WP_REST_Response {
        $body = (array) $req->get_json_params();

        $nonce = isset( $body['nonce'] ) ? sanitize_text_field( $body['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'pcio_mep_checkout' ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Your session expired. Please reload the page and try again.', 'pcio-vis-products' ) ], 403 );
        }

        $name  = sanitize_text_field( $body['name'] ?? '' );
        $email = sanitize_email( $body['email'] ?? '' );
        if ( '' === $name || ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Please provide your name and a valid email address.', 'pcio-vis-products' ) ], 422 );
        }

        $items      = [];
        $line_items = [];
        $summary    = [];
        $total      = 0;
        $currency   = PCIO_VIS_Products_Plugin::currency();

        foreach ( (array) ( $body['items'] ?? [] ) as $raw ) {
            $product_id = absint( $raw['product_id'] ?? 0 );
            $qty        = absint( $raw['qty'] ?? 0 );
            if ( ! $product_id || $qty < 1 ) {
                continue;
            }
            $product = PCIO_VIS_Products_DB::get_product( $product_id );
            if ( ! $product || empty( $product['active'] ) ) {
                return new WP_REST_Response( [ 'message' => __( 'One of the selected products is not available.', 'pcio-vis-products' ) ], 409 );
            }
            $unit   = (int) $product['price_cents'];
            $total += $unit * $qty;
            $items[]      = [ 'product' => $product, 'qty' => $qty, 'unit' => $unit ];
            $line_items[] = [ 'name' => $product['title'], 'unit_amount_cents' => $unit, 'qty' => $qty ];
            $summary[]    = $qty . ' × ' . $product['title'];
        }

        if ( ! $items ) {
            return new WP_REST_Response( [ 'message' => __( 'Your cart is empty.', 'pcio-vis-products' ) ], 422 );
        }

        // Optional delivery method — validates and adds its price to the total.
        $delivery_method_id   = absint( $body['delivery_method_id'] ?? 0 );
        $delivery_method_name = '';
        if ( $delivery_method_id > 0 && class_exists( 'PCIO_VIS_Products_Delivery_DB' ) ) {
            $method = PCIO_VIS_Products_Delivery_DB::get( $delivery_method_id );
            if ( ! $method || empty( $method['active'] ) ) {
                return new WP_REST_Response( [ 'message' => __( 'The selected delivery method is not available.', 'pcio-vis-products' ) ], 409 );
            }
            $delivery_price = (int) $method['price_cents'];
            if ( $delivery_price > 0 ) {
                $total       += $delivery_price;
                $line_items[] = [ 'name' => (string) $method['name'], 'unit_amount_cents' => $delivery_price, 'qty' => 1 ];
            }
            $delivery_method_name = (string) $method['name'];
        }

        try {
            $customer_id = PCIO_VIS_Products_DB::upsert_customer( $email, $name );
            $order_id    = PCIO_VIS_Products_DB::create_order( [
                'customer_id'  => $customer_id,
                'email'        => $email,
                'status'       => 'pending',
                'summary'      => implode( ', ', $summary ),
                'amount_cents' => $total,
                'currency'     => $currency,
                'kind'         => 'shop',
            ] );
            if ( ! $order_id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create the order. Please try again.', 'pcio-vis-products' ) ], 500 );
            }
            if ( $delivery_method_id > 0 && class_exists( 'PCIO_VIS_Products_Delivery_DB' ) ) {
                PCIO_VIS_Products_DB::update_order(
                    $order_id,
                    [ 'delivery_method_id' => $delivery_method_id, 'delivery_method_name' => $delivery_method_name ],
                    [ '%d', '%s' ]
                );
            }
            foreach ( $items as $it ) {
                PCIO_VIS_Products_DB::add_order_item(
                    $order_id,
                    (int) $it['product']['id'],
                    (string) $it['product']['product_name'],
                    (int) $it['qty'],
                    (int) $it['unit']
                );
            }
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }

        $order = PCIO_VIS_Products_DB::get_order( $order_id );

        // FREE path — confirm immediately.
        if ( $total <= 0 ) {
            if ( PCIO_VIS_Products_DB::claim_order( $order_id, 'pending', 'free_confirmed' ) ) {
                PCIO_VIS_Products_DB::update_order( $order_id, [ 'paid_at' => current_time( 'mysql' ) ], [ '%s' ] );
                do_action( 'pcio_mep_order_confirmed', $order_id );
            }
            return new WP_REST_Response( [ 'free' => true, 'redirect' => $this->return_url( 'accepted', $order['token'] ) ], 200 );
        }

        // PAID path — require Stripe.
        if ( ! PCIO_VIS_Products_Plugin::is_configured() ) {
            return new WP_REST_Response( [ 'message' => __( 'Online payment is not available right now. Please contact us.', 'pcio-vis-products' ) ], 503 );
        }

        $session = PCIO_VIS_Products_Stripe::create_checkout_session( [
            'order_no'    => $order['order_no'],
            'order_id'    => $order_id,
            'token'       => $order['token'],
            'email'       => $email,
            'currency'    => $currency,
            'success_url' => $this->return_url( 'accepted', $order['token'] ),
            'cancel_url'  => $this->return_url( 'rejected', $order['token'] ),
            'line_items'  => $line_items,
        ] );
        if ( is_wp_error( $session ) ) {
            return new WP_REST_Response( [ 'message' => $session->get_error_message() ], 502 );
        }

        PCIO_VIS_Products_DB::update_order( $order_id, [ 'stripe_session_id' => (string) ( $session['id'] ?? '' ) ], [ '%s' ] );

        return new WP_REST_Response( [ 'free' => false, 'redirect' => (string) ( $session['url'] ?? '' ) ], 200 );
    }

    // ── Public: renewal ───────────────────────────────────────────

    /**
     * Start a renewal checkout for the subscription identified by its verify
     * token. The price is looked up from the product named on the subscription.
     *
     * Body: { nonce, token }
     */
    public function renew( WP_REST_Request $req ): WP_REST_Response {
        $body  = (array) $req->get_json_params();
        $nonce = isset( $body['nonce'] ) ? sanitize_text_field( $body['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'pcio_mep_renew' ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Your session expired. Please reload the page and try again.', 'pcio-vis-products' ) ], 403 );
        }

        $token = sanitize_text_field( $body['token'] ?? '' );
        $sub   = $token ? PCIO_VIS_Products_DB::get_subscription_by_verify( $token ) : null;
        if ( ! $sub ) {
            return new WP_REST_Response( [ 'message' => __( 'Subscription not found.', 'pcio-vis-products' ) ], 404 );
        }
        $product = PCIO_VIS_Products_DB::get_product_by_name( (string) $sub['product_name'] );
        if ( ! $product ) {
            return new WP_REST_Response( [ 'message' => __( 'This subscription can no longer be renewed online. Please contact us.', 'pcio-vis-products' ) ], 409 );
        }
        if ( ! PCIO_VIS_Products_Plugin::is_configured() ) {
            return new WP_REST_Response( [ 'message' => __( 'Online payment is not available right now. Please contact us.', 'pcio-vis-products' ) ], 503 );
        }

        $total    = (int) $product['price_cents'];
        $currency = PCIO_VIS_Products_Plugin::currency();
        $email    = (string) $sub['customer_email'];

        $customer_id = PCIO_VIS_Products_DB::upsert_customer( $email, (string) $sub['customer_name'] );
        $order_id    = PCIO_VIS_Products_DB::create_order( [
            'customer_id'     => $customer_id,
            'email'           => $email,
            'status'          => 'pending',
            'summary'         => sprintf( /* translators: %s: product name */ __( 'Renewal: %s', 'pcio-vis-products' ), $product['title'] ),
            'amount_cents'    => $total,
            'currency'        => $currency,
            'kind'            => 'renewal',
            'subscription_id' => (int) $sub['id'],
        ] );
        if ( ! $order_id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create the renewal order.', 'pcio-vis-products' ) ], 500 );
        }
        PCIO_VIS_Products_DB::add_order_item( $order_id, (int) $product['id'], (string) $product['product_name'], 1, $total );

        $order   = PCIO_VIS_Products_DB::get_order( $order_id );
        $session = PCIO_VIS_Products_Stripe::create_checkout_session( [
            'order_no'    => $order['order_no'],
            'order_id'    => $order_id,
            'token'       => $order['token'],
            'email'       => $email,
            'currency'    => $currency,
            'success_url' => $this->return_url( 'accepted', $order['token'] ),
            'cancel_url'  => $this->return_url( 'rejected', $order['token'] ),
            'line_items'  => [ [ 'name' => $product['title'], 'unit_amount_cents' => $total, 'qty' => 1 ] ],
        ] );
        if ( is_wp_error( $session ) ) {
            return new WP_REST_Response( [ 'message' => $session->get_error_message() ], 502 );
        }
        PCIO_VIS_Products_DB::update_order( $order_id, [ 'stripe_session_id' => (string) ( $session['id'] ?? '' ) ], [ '%s' ] );

        return new WP_REST_Response( [ 'redirect' => (string) ( $session['url'] ?? '' ) ], 200 );
    }

    // ── Public: licence verification ──────────────────────────────

    /**
     * Verify a licence key. The customer's plugin calls this endpoint with the
     * key it was issued; we report whether the subscription is still valid.
     *
     * GET /shop/verify?token={license_token}
     */
    public function verify( WP_REST_Request $req ): WP_REST_Response {
        $token = sanitize_text_field( (string) $req->get_param( 'token' ) );
        $sub   = $token ? PCIO_VIS_Products_DB::get_subscription_by_license( $token ) : null;
        if ( ! $sub ) {
            return new WP_REST_Response( [ 'valid' => false, 'reason' => 'not_found' ], 200 );
        }
        $valid = $sub['expire_date'] && strtotime( (string) $sub['expire_date'] ) >= strtotime( gmdate( 'Y-m-d' ) );
        return new WP_REST_Response( [
            'valid'   => (bool) $valid,
            'product' => (string) $sub['product_name'],
            'expires' => (string) $sub['expire_date'],
            'reason'  => $valid ? 'active' : 'expired',
        ], 200 );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function return_url( string $view, string $token ): string {
        return add_query_arg(
            [ 'order' => rawurlencode( $token ) ],
            home_url( 'shop/' . $view . '/' )
        );
    }
}
