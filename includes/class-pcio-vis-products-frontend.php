<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public-facing storefront for the Products extension.
 *
 * Storefront is a shortcode that can be placed on any page:
 *   [pcio_me_shop group='']
 *
 * Standalone pages (own rewrites, flushed on activation, query var `pcio_mep`):
 *   /shop/accepted?order={token}  – payment accepted / free order confirmed
 *   /shop/rejected?order={token}  – payment cancelled or failed
 *   /shop/renew?token={token}     – subscription renewal landing
 */
class PCIO_VIS_Products_Frontend {

    public function register(): void {
        add_action( 'init',              [ $this, 'register_rewrites' ] );
        add_action( 'init',              [ $this, 'register_shortcode' ] );
        add_action( 'template_redirect', [ $this, 'serve_templates' ] );
    }

    public function register_rewrites(): void {
        add_rewrite_rule( '^shop/accepted/?$', 'index.php?pcio_mep=accepted', 'top' );
        add_rewrite_rule( '^shop/rejected/?$', 'index.php?pcio_mep=rejected', 'top' );
        add_rewrite_rule( '^shop/renew/?$',    'index.php?pcio_mep=renew',    'top' );
        add_rewrite_tag( '%pcio_mep%', '([^&]+)' );
    }

    public function register_shortcode(): void {
        add_shortcode( 'pcio_me_shop',               [ $this, 'render_shortcode' ] );
        add_shortcode( 'pcio_me_product_order',      [ $this, 'render_product_order' ] );
        add_shortcode( 'pcio_me_member_subscription_count', [ $this, 'render_subscription_count' ] );
    }

    // ── Storefront shortcode ──────────────────────────────────────

    /**
     * Renders an animated count of currently active (non-expired) subscriptions.
     * Usage: [pcio_me_member_subscription_count]
     */
    public function render_subscription_count(): string {
        $count  = PCIO_VIS_Products_DB::count_active_member_subscriptions();
        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );

        wp_enqueue_style(  'pcio-me-member-count', $assets . 'member-count.css', [], '1.0.0' );
        wp_enqueue_script( 'pcio-me-member-count', $assets . 'member-count.js',  [], '1.0.0', true );

        return '<span class="pcio-me-member-count" data-target="' . esc_attr( (string) $count ) . '">0</span>';
    }

    /**
     * @param array|string $atts Shortcode attributes.
     */
    public function render_shortcode( $atts ): string {
        $atts  = shortcode_atts( [ 'group' => '' ], $atts, 'pcio_me_shop' );
        $group = sanitize_text_field( (string) $atts['group'] );

        $this->enqueue_shop_assets( $group );

        $id = 'pcio-me-shop-' . wp_rand( 1000, 9999 );
        return '<div class="pcio-me-shop" id="' . esc_attr( $id ) . '" data-group="' . esc_attr( $group ) . '"></div>';
    }

    private function enqueue_shop_assets( string $group ): void {
        wp_enqueue_style( 'pcio-mep-shop', plugins_url( 'assets/shop.css', PCIO_VIS_PRODUCTS_FILE ), [], PCIO_VIS_PRODUCTS_VERSION );
        wp_enqueue_script( 'pcio-mep-shop', plugins_url( 'assets/shop.js', PCIO_VIS_PRODUCTS_FILE ), [], PCIO_VIS_PRODUCTS_VERSION, true );

        wp_add_inline_script(
            'pcio-mep-shop',
            'window.PCIO_VIS_Products_SHOP = ' . wp_json_encode( [
                'rest'      => rest_url( PCIO_VIS_REST_NS . '/shop' ),
                'nonce'     => wp_create_nonce( 'pcio_mep_checkout' ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'group'     => $group,
                'currency'  => PCIO_VIS_Products_Plugin::currency(),
                'i18n'      => [
                    'seeDetails'  => __( 'See details', 'pcio-vis-products' ),
                    'addToCart'   => __( 'Add to cart', 'pcio-vis-products' ),
                    'close'       => __( 'Close', 'pcio-vis-products' ),
                    'cart'        => __( 'Cart', 'pcio-vis-products' ),
                    'checkout'    => __( 'Checkout', 'pcio-vis-products' ),
                    'qty'         => __( 'Qty', 'pcio-vis-products' ),
                    'remove'      => __( 'Remove', 'pcio-vis-products' ),
                    'empty'       => __( 'Your cart is empty.', 'pcio-vis-products' ),
                    'total'       => __( 'Total', 'pcio-vis-products' ),
                    'free'        => __( 'Free', 'pcio-vis-products' ),
                    'name'        => __( 'Your name', 'pcio-vis-products' ),
                    'email'       => __( 'Your email', 'pcio-vis-products' ),
                    'needName'    => __( 'Please enter your name.', 'pcio-vis-products' ),
                    'needEmail'   => __( 'Please enter a valid email address.', 'pcio-vis-products' ),
                    'processing'  => __( 'Processing…', 'pcio-vis-products' ),
                    'noProducts'  => __( 'No products available.', 'pcio-vis-products' ),
                    'genericErr'  => __( 'Something went wrong. Please try again.', 'pcio-vis-products' ),
                    /* translators: %d is the number of items in the cart (singular). */
                    'itemsOne'    => __( '%d item', 'pcio-vis-products' ),
                    /* translators: %d is the number of items in the cart (plural). */
                    'itemsMany'   => __( '%d items', 'pcio-vis-products' ),
                ],
            ] ) . ';',
            'before'
        );
    }

    // ── Standalone pages ──────────────────────────────────────────

    public function serve_templates(): void {
        $view = get_query_var( 'pcio_mep' );
        if ( ! $view ) {
            return;
        }
        switch ( $view ) {
            case 'accepted':
                $this->serve_result( 'accepted' );
                break;
            case 'rejected':
                $this->serve_result( 'rejected' );
                break;
            case 'renew':
                $this->serve_renew();
                break;
            default:
                $this->not_found();
        }
    }

    private function serve_result( string $view ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET token, read-only.
        $token = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order = $token ? PCIO_VIS_Products_DB::get_order_by_token( $token ) : null;

        // The accepted page is where we reconcile the Stripe payment and
        // fulfil the order (no webhook); idempotent if already fulfilled.
        if ( 'accepted' === $view && $order ) {
            PCIO_VIS_Products_Fulfillment::reconcile_paid( (int) $order['id'] );
            $order = PCIO_VIS_Products_DB::get_order( (int) $order['id'] );
        }

        wp_enqueue_style( 'pcio-mep-shop', plugins_url( 'assets/shop.css', PCIO_VIS_PRODUCTS_FILE ), [], PCIO_VIS_PRODUCTS_VERSION );

        $subscriptions = $order ? PCIO_VIS_Products_DB::get_subscriptions_for_order( (int) $order['id'] ) : [];

        $pcio_mep_view = [
            'view'          => $view,
            'order'         => $order,
            'subscriptions' => $subscriptions,
            'home_url'      => home_url( '/' ),
        ];
        $this->render( PCIO_VIS_PRODUCTS_DIR . 'templates/result.php', $pcio_mep_view );
    }

    private function serve_renew(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET token, read-only.
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        $sub = '';
        if ( $token ) {
            $sub = PCIO_VIS_Products_DB::get_subscription_by_verify( $token );
            if ( ! $sub ) {
                $sub = PCIO_VIS_Products_DB::get_subscription_by_license( $token );
            }
        }

        if ( ! $sub ) {
            $this->not_found();
            return;
        }

        $product = PCIO_VIS_Products_DB::get_product_by_name( (string) $sub['product_name'] );

        wp_enqueue_style( 'pcio-mep-shop', plugins_url( 'assets/shop.css', PCIO_VIS_PRODUCTS_FILE ), [], PCIO_VIS_PRODUCTS_VERSION );
        wp_enqueue_script( 'pcio-mep-shop', plugins_url( 'assets/shop.js', PCIO_VIS_PRODUCTS_FILE ), [], PCIO_VIS_PRODUCTS_VERSION, true );
        wp_add_inline_script(
            'pcio-mep-shop',
            'window.PCIO_VIS_Products_RENEW = ' . wp_json_encode( [
                'rest'           => rest_url( PCIO_VIS_REST_NS . '/shop' ),
                'nonce'          => wp_create_nonce( 'pcio_mep_renew' ),
                'restNonce'      => wp_create_nonce( 'wp_rest' ),
                'subscriptionId' => (int) $sub['id'],
                'token'          => $sub['verify_token'],
                'i18n'           => [
                    'processing' => __( 'Processing…', 'pcio-vis-products' ),
                    'genericErr' => __( 'Something went wrong. Please try again.', 'pcio-vis-products' ),
                ],
            ] ) . ';',
            'before'
        );

        $pcio_mep_view = [
            'subscription' => $sub,
            'product'      => $product,
            'currency'     => PCIO_VIS_Products_Plugin::currency(),
            'home_url'     => home_url( '/' ),
        ];
        $this->render( PCIO_VIS_PRODUCTS_DIR . 'templates/renew.php', $pcio_mep_view );
    }

    private function render( string $template, array $pcio_mep_view ): void {
        if ( ! file_exists( $template ) ) {
            $this->not_found();
            return;
        }
        status_header( 200 );
        nocache_headers();
        // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- used by template.
        require $template;
        exit;
    }

    private function not_found(): void {
        global $wp_query;
        if ( $wp_query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
    }

    // ── [pcio_me_product_order] shortcode ─────────────────────────

    /**
     * Single-product order form with delivery method selection.
     * Attribute: id (int, required) — the product ID.
     * Requires vis_member capability; shows login prompt otherwise.
     *
     * @param array|string $atts
     */
    public function render_product_order( $atts ): string {
        $atts       = shortcode_atts( [ 'id' => 0, 'name' => '', 'max_qty' => 10 ], $atts, 'pcio_me_product_order' );
        $product_id = absint( $atts['id'] );
        $name_attr  = sanitize_text_field( (string) $atts['name'] );
        $max_qty    = max( 1, min( 100, absint( $atts['max_qty'] ) ) );

        // Resolve by product_name slug when id is not given.
        if ( ! $product_id && $name_attr !== '' ) {
            $by_name    = PCIO_VIS_Products_DB::get_product_by_name( $name_attr );
            $product_id = $by_name ? (int) $by_name['id'] : 0;
        }

        if ( ! $product_id ) {
            return '';
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'vis_member' ) ) {
            return '<p class="mep-po-login">'
                . esc_html__( 'You must be logged in as a Vis member to order this product.', 'pcio-vis-products' )
                . '</p>';
        }

        $product = PCIO_VIS_Products_DB::get_product( $product_id );
        if ( ! $product || empty( $product['active'] ) ) {
            return '<p>' . esc_html__( 'This product is not available.', 'pcio-vis-products' ) . '</p>';
        }

        // Pre-fill name/email from the member record when available.
        $member_name  = '';
        $member_email = '';
        if ( class_exists( 'PCIO_VIS_Roles_DB' ) && class_exists( 'PCIO_VIS_DB' ) ) {
            $mid = PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() );
            if ( $mid ) {
                $member = PCIO_VIS_DB::get( $mid );
                if ( $member ) {
                    $member_name  = (string) ( $member['name']  ?? '' );
                    $member_email = (string) ( $member['email'] ?? '' );
                }
            }
        }
        if ( ! $member_email ) {
            $member_email = (string) ( wp_get_current_user()->user_email ?? '' );
        }

        wp_enqueue_style(
            'pcio-mep-product-order',
            plugins_url( 'assets/product-order.css', PCIO_VIS_PRODUCTS_FILE ),
            [],
            PCIO_VIS_PRODUCTS_VERSION
        );
        wp_enqueue_script(
            'pcio-mep-product-order',
            plugins_url( 'assets/product-order.js', PCIO_VIS_PRODUCTS_FILE ),
            [],
            PCIO_VIS_PRODUCTS_VERSION . '.1',
            true
        );
        wp_add_inline_script(
            'pcio-mep-product-order',
            'window.PCIO_VIS_Products_ORDER = ' . wp_json_encode( [
                'rest'          => rest_url( PCIO_VIS_REST_NS . '/shop' ),
                'checkoutNonce' => wp_create_nonce( 'pcio_mep_checkout' ),
                'restNonce'     => wp_create_nonce( 'wp_rest' ),
                'currency'      => PCIO_VIS_Products_Plugin::currency(),
                'product'       => $product,
                'maxQty'        => $max_qty,
                'member'        => [ 'name' => $member_name, 'email' => $member_email ],
                'i18n'          => [
                    'deliveryMethod' => __( 'Delivery method',                                            'pcio-vis-products' ),
                    'qty'            => __( 'Quantity',                                                   'pcio-vis-products' ),
                    'yourName'       => __( 'Your name',                                                  'pcio-vis-products' ),
                    'yourEmail'      => __( 'Your email',                                                 'pcio-vis-products' ),
                    'needName'       => __( 'Please enter your name.',                                    'pcio-vis-products' ),
                    'needEmail'      => __( 'Please enter a valid email address.',                        'pcio-vis-products' ),
                    'needMethod'     => __( 'Please select a delivery method.',                           'pcio-vis-products' ),
                    'order'          => __( 'Place order',                                                'pcio-vis-products' ),
                    'processing'     => __( 'Processing…',                                               'pcio-vis-products' ),
                    'unavailable'    => __( 'This product is not currently available.',                   'pcio-vis-products' ),
                ],
            ] ) . ';',
            'before'
        );

        return '<div class="pcio-mep-product-order"></div>';
    }
}
