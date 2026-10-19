<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integration between the Products (shop) extension and the core Member Event
 * system.
 *
 * Wiring (all hooks live in the core plugin, each degrades gracefully):
 *   • pcio_me_all_caps / pcio_me_cap_labels   — caps in the Vis Roles matrix
 *   • pcio_me_default_role_caps / pcio_me_sync_caps — default-cap sync
 *   • pcio_me_home_cards                        — "Shop" card on /vis/
 *   • pcio_me_nav_items                         — "Shop" nav link
 *   • pcio_me_member_fields / pcio_me_member_row — subscription status column
 *   • pcio_me_finance_lists                     — read-only "Shop Orders" list
 */
class PCIO_VIS_Products_Integration {

    public function register(): void {
        // Capabilities in the Vis Roles matrix.
        add_filter( 'pcio_me_all_caps',   [ $this, 'register_caps' ] );
        add_filter( 'pcio_me_cap_labels', [ $this, 'register_cap_labels' ] );

        // Default-cap sync (the "Sync capabilities" button on Vis → Roles).
        add_filter( 'pcio_me_default_role_caps', [ $this, 'register_default_role_caps' ] );
        add_action( 'pcio_me_sync_caps',         [ $this, 'grant_native_role_caps' ] );

        // Landing page card + navigation link.
        add_action( 'pcio_me_home_cards', [ $this, 'render_home_card' ], 20 );
        add_filter( 'pcio_me_nav_items',  [ $this, 'add_nav_item' ], 10, 2 );

        // Subscription status on the member list.
        add_filter( 'pcio_me_member_fields', [ $this, 'add_member_field' ] );
        add_filter( 'pcio_me_member_row',    [ $this, 'merge_member_row' ] );

        // Refresh coverage when a member is (re)assigned a membership number.
        add_action( 'pcio_me_member_saved',  [ $this, 'on_member_saved' ], 10, 2 );

        // Finance section: read-only "Shop Orders" list.
        add_filter( 'pcio_me_finance_lists', [ $this, 'register_finance_list' ] );
        add_filter( 'pcio_me_finance_tabs', [ $this, 'register_finance_tabs' ] );

        // Statistics dashboard: canonical "Active memberships" count.
        add_filter( 'pcio_me_active_subscription_count', [ $this, 'active_subscription_count' ] );

        // Shortcode reference page.
        add_action( 'pcio_me_shortcodes_nav_items', [ $this, 'render_shortcode_nav_items' ] );
        add_action( 'pcio_me_shortcodes_after',     [ $this, 'render_shortcode_docs' ] );

        // Document the subscription tags this plugin adds to [pcio_me_member_signup].
        add_filter( 'pcio_me_signup_help_tags', [ $this, 'add_signup_help_tags' ], 10 );
    }

    // ── Capabilities ──────────────────────────────────────────────

    /**
     * @param string[] $caps
     * @return string[]
     */
    public function register_caps( array $caps ): array {
        return array_merge( $caps, array_keys( PCIO_VIS_Products_Plugin::CAP_ROLE_MAP ) );
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function register_cap_labels( array $labels ): array {
        $labels['vis_shop_manage']   = __( 'Manage shop products & subscriptions', 'pcio-vis-products' );
        $labels['vis_shop_refund']   = __( 'Refund shop orders', 'pcio-vis-products' );
        $labels['vis_shop_delivery'] = __( 'Manage deliveries', 'pcio-vis-products' );
        return $labels;
    }

    /**
     * @param array<string,string[]> $defaults Map of vis-role slug => cap slugs.
     * @return array<string,string[]>
     */
    public function register_default_role_caps( array $defaults ): array {
        $slug = 'system-admin';
        $defaults[ $slug ] = array_values( array_unique( array_merge(
            $defaults[ $slug ] ?? [],
            array_keys( PCIO_VIS_Products_Plugin::CAP_ROLE_MAP )
        ) ) );
        return $defaults;
    }

    /**
     * Re-grant the shop caps to their native WP roles on core "Sync
     * capabilities" (mirrors plugin-activation behaviour).
     */
    public function grant_native_role_caps(): void {
        foreach ( PCIO_VIS_Products_Plugin::CAP_ROLE_MAP as $cap => $roles ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }

    // ── Landing page + navigation ─────────────────────────────────

    public function render_home_card(): void {
        if ( current_user_can( 'vis_shop_manage' ) || current_user_can( 'vis_manage_finance' ) ) {
            $url = home_url( 'vis/shop' );
            ?>
        <a class="me-home-card" href="<?php echo esc_url( $url ); ?>">
            <div class="me-home-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1"/>
                    <circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
            </div>
            <div class="me-home-card-body">
                <h2 class="me-home-card-title">
                    <?php esc_html_e( 'Shop', 'pcio-vis-products' ); ?>
                </h2>
                <p class="me-home-card-desc">
                    <?php esc_html_e( 'Manage products, subscriptions and online sales.', 'pcio-vis-products' ); ?>
                </p>
            </div>
            <div class="me-home-card-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </div>
        </a>
            <?php
        }

    }

    /**
     * @param array  $items
     * @param string $active
     * @return array
     */
    public function add_nav_item( array $items, string $active ): array {
        $can_manage = current_user_can( 'vis_shop_manage' );
        if ( $can_manage || current_user_can( 'vis_manage_finance' ) ) {
            $children = [];
            if ( $can_manage ) {
                $children['products'] = [ 'label' => __( 'Products', 'pcio-vis-products' ), 'url' => home_url( 'vis/shop#products' ) ];
            }
            $children['subscriptions'] = [ 'label' => __( 'Subscriptions', 'pcio-vis-products' ), 'url' => home_url( 'vis/shop#subscriptions' ) ];
            if ( $can_manage ) {
                $children['delivery-methods'] = [ 'label' => __( 'Delivery Methods', 'pcio-vis-products' ), 'url' => home_url( 'vis/shop#delivery-methods' ) ];
            }
            $items['shop'] = [ 'label' => __( 'Shop', 'pcio-vis-products' ), 'url' => home_url( 'vis/shop' ), 'children' => $children ];
        }
        return $items;
    }

    /** Add the existing delivery workflow to the Finance section. */
    public function register_finance_tabs( array $tabs ): array {
        if ( current_user_can( 'vis_shop_delivery' ) ) {
            $tabs['delivery'] = [ 'label' => __( 'Deliveries', 'pcio-vis-products' ), 'url' => home_url( 'vis/delivery' ) ];
        }
        return $tabs;
    }

    // ── Member subscription status ────────────────────────────────

    /**
     * @param array $fields
     * @return array
     */
    public function add_member_field( array $fields ): array {
        $fields[] = [
            'key'             => 'mep_subscription_status',
            'label'           => __( 'Subscription', 'pcio-vis-products' ),
            'type'            => 'text',
            'sortable'        => true,
            'list_col'        => true,
            'shortcode_col'   => false,
            'profile_readonly' => true,
            'meta'            => true,
        ];
        return $fields;
    }

    /**
     * Merge the subscription status meta into the member row for display.
     *
     * @param array $member
     * @return array
     */
    public function merge_member_row( array $member ): array {
        if ( empty( $member['id'] ) ) {
            return $member;
        }
        if ( ! class_exists( 'PCIO_VIS_Meta_DB' ) ) {
            return $member;
        }
        $meta = PCIO_VIS_Meta_DB::get_member_meta( (int) $member['id'] );
        $member['mep_subscription_status'] = isset( $meta['mep_subscription_status'] )
            ? (string) $meta['mep_subscription_status']
            : '';
        return $member;
    }

    /**
     * When a member is saved (e.g. assigned to a membership number in the admin),
     * refresh the coverage status for that whole membership-number group.
     */
    public function on_member_saved( int $member_id, array $data ): void {
        if ( $member_id <= 0 || ! class_exists( 'PCIO_VIS_DB' ) ) {
            return;
        }
        $number = PCIO_VIS_DB::get_member_number_by_id( $member_id );
        if ( $number > 0 ) {
            PCIO_VIS_Products_Fulfillment::refresh_number_status( $number );
        }
    }

    // ── Finance integration ───────────────────────────────────────

    /**
     * Register a read-only "Shop Orders" list on the Finance page. Data is
     * served by GET /shop/orders (PCIO_VIS_Products_Rest::list_orders_finance()).
     *
     * @param array $lists
     * @return array
     */
    public function register_finance_list( array $lists ): array {
        $actions = [];
        if ( current_user_can( 'vis_shop_refund' ) ) {
            $actions[] = [
                'id'            => 'refund',
                'label'         => __( 'Refund', 'pcio-vis-products' ),
                'icon'          => 'refund',
                'kind'          => 'danger',
                'path'          => '/refund',
                'method'        => 'POST',
                'visibleField'  => 'can_refund',
                'amountParam'   => 'amount_cents',
                'maxField'      => 'refundable_cents',
                'totalField'    => 'amount_cents',
                'currencyField' => 'currency',
                'i18n'          => [
                    'title'       => __( 'Refund order', 'pcio-vis-products' ),
                    'amountLabel' => __( 'Refund amount', 'pcio-vis-products' ),
                    'amountHint'  => __( 'Up to the order total can be refunded. The maximum performs a full refund.', 'pcio-vis-products' ),
                    'submit'      => __( 'Refund', 'pcio-vis-products' ),
                    'working'     => __( 'Working…', 'pcio-vis-products' ),
                    'toast'       => __( 'Order refunded.', 'pcio-vis-products' ),
                    'error'       => __( 'Refund failed.', 'pcio-vis-products' ),
                ],
            ];
        }

        $lists[] = [
            'id'         => 'shop-orders',
            'label'      => __( 'Shop Orders', 'pcio-vis-products' ),
            'rest'       => rest_url( PCIO_VIS_REST_NS . '/shop/orders' ),
            'editable'   => false,
            'canEdit'    => false,
            'rowActions' => $actions,
            'columns'    => [
                [ 'key' => 'entry_date',   'label' => __( 'Date',   'pcio-vis-products' ), 'type' => 'datetime', 'sortable' => true ],
                [ 'key' => 'order_no',     'label' => __( 'Order',  'pcio-vis-products' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'buyer',        'label' => __( 'Buyer',  'pcio-vis-products' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'email',        'label' => __( 'Email',  'pcio-vis-products' ), 'type' => 'text',     'sortable' => true ],
                [ 'key' => 'summary',      'label' => __( 'Items',  'pcio-vis-products' ), 'type' => 'text',     'sortable' => false ],
                [ 'key' => 'amount_cents', 'label' => __( 'Amount', 'pcio-vis-products' ), 'type' => 'money',    'sortable' => true ],
                [ 'key' => 'status',   'label' => __( 'Status', 'pcio-vis-products' ), 'type' => 'text',    'sortable' => true, 'align' => 'center' ],
                [ 'key' => 'stripe_url','label' => __( 'Stripe', 'pcio-vis-products' ), 'type' => 'extlink', 'linkLabel' => __( 'View', 'pcio-vis-products' ), 'align' => 'center' ],
            ],
        ];
        return $lists;
    }

    // ── Statistics ──────────────────────────────────────────────

    /**
     * Provide the canonical "Active memberships" count to the core Statistics
     * dashboard: distinct members holding an active membership subscription product.
     * Filter: pcio_me_active_subscription_count
     *
     * @param int $count
     * @return int
     */
    public function active_subscription_count( $count ): int {
        return PCIO_VIS_Products_DB::count_active_member_subscriptions();
    }

    // ── Shortcode reference ─────────────────────────────────────

    /**
     * Document the welcome-email tags this plugin provides on the
     * [pcio_me_member_signup] shortcode reference page.
     * Filter: pcio_me_signup_help_tags
     *
     * @param array<int,array{tag:string,source:string,desc:string}> $rows
     * @return array<int,array{tag:string,source:string,desc:string}>
     */
    public function add_signup_help_tags( array $rows ): array {
        $rows[] = [
            'tag'    => '{subscription_name}',
            'source' => 'pcio-vis-products',
            'desc'   => __( 'Name of the membership subscription product', 'pcio-vis-products' ),
        ];
        $rows[] = [
            'tag'    => '{expire_date}',
            'source' => 'pcio-vis-products',
            'desc'   => __( 'Subscription expiry date (YYYY-MM-DD)', 'pcio-vis-products' ),
        ];
        return $rows;
    }

    public function render_shortcode_nav_items(): void {
        ?>
        <div class="pcio-sc-nav-group">
            <div class="pcio-sc-nav-heading"><?php esc_html_e( 'PCIO VIS Products', 'pcio-vis-products' ); ?></div>
            <a href="#sc-shop" class="pcio-sc-nav-item">[pcio_me_shop]</a>
            <a href="#sc-product-order" class="pcio-sc-nav-item">[pcio_me_product_order]</a>
            <a href="#sc-subscription-count" class="pcio-sc-nav-item">[pcio_me_member_subscription_count]</a>
        </div>
        <?php
    }

    public function render_shortcode_docs(): void {
        ?>
        <hr style="margin:36px 0">

        <!-- [───────────────────────────────────────────────] -->
        <h2 id="sc-shop"><?php esc_html_e( '[pcio_me_shop]', 'pcio-vis-products' ); ?></h2>
        <p><?php esc_html_e( 'Renders the public product storefront. Visitors browse products, add items to their cart, and check out via Stripe (or instantly for free products). Provided by the PCIO VIS Products plugin.', 'pcio-vis-products' ); ?></p>

        <table class="pcio-sc-table wp-list-table widefat">
            <thead><tr>
                <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-products' ); ?></th>
                <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-products' ); ?></th>
                <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-products' ); ?></th>
                <th><?php esc_html_e( 'Description', 'pcio-vis-products' ); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code class="pcio-sc-code">group</code></td>
                    <td><?php esc_html_e( 'string', 'pcio-vis-products' ); ?></td>
                    <td><code class="pcio-sc-code">""</code></td>
                    <td><?php esc_html_e( 'Limit the storefront to one product group. Leave empty to show all active products.', 'pcio-vis-products' ); ?></td>
                </tr>
            </tbody>
        </table>

        <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-products' ); ?></strong>
        <code class="pcio-sc-block">[pcio_me_shop]
[pcio_me_shop group="merchandise"]</code>

        <hr style="margin:32px 0">

        <!-- [───────────────────────────────────────────────] -->
        <h2 id="sc-product-order"><?php esc_html_e( '[pcio_me_product_order]', 'pcio-vis-products' ); ?></h2>
        <p><?php esc_html_e( 'Displays a single-product order form with delivery method selection and quantity picker. The member sees the product image, title, description, and base price. Delivery method radio buttons show any extra charge and update the displayed total in real time. Name and email are pre-filled from the member record. On submit the order is placed via Stripe (or confirmed immediately for free products). Requires the vis_member capability. Provided by the PCIO VIS Products plugin.', 'pcio-vis-products' ); ?></p>

        <table class="pcio-sc-table wp-list-table widefat">
            <thead><tr>
                <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-products' ); ?></th>
                <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-products' ); ?></th>
                <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-products' ); ?></th>
                <th><?php esc_html_e( 'Description', 'pcio-vis-products' ); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code class="pcio-sc-code">id</code></td>
                    <td><?php esc_html_e( 'integer', 'pcio-vis-products' ); ?></td>
                    <td><em><?php esc_html_e( 'required*', 'pcio-vis-products' ); ?></em></td>
                    <td><?php esc_html_e( 'The numeric ID of the product. Visible in the ID column on /vis/shop.', 'pcio-vis-products' ); ?></td>
                </tr>
                <tr>
                    <td><code class="pcio-sc-code">name</code></td>
                    <td><?php esc_html_e( 'string', 'pcio-vis-products' ); ?></td>
                    <td><em><?php esc_html_e( 'required*', 'pcio-vis-products' ); ?></em></td>
                    <td><?php esc_html_e( 'The internal product name (slug). Alternative to id — use whichever is more convenient. Visible in the Product name column on /vis/shop.', 'pcio-vis-products' ); ?></td>
                </tr>
                <tr>
                    <td><code class="pcio-sc-code">max_qty</code></td>
                    <td><?php esc_html_e( 'integer', 'pcio-vis-products' ); ?></td>
                    <td><code class="pcio-sc-code">10</code></td>
                    <td><?php esc_html_e( 'Maximum quantity the member can order. The quantity field accepts values from 1 up to this limit. Set to 1 to allow only single-unit orders.', 'pcio-vis-products' ); ?></td>
                </tr>
            </tbody>
        </table>

        <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Required capability', 'pcio-vis-products' ); ?>:</strong>
        <code class="pcio-sc-cap">vis_member</code>

        <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Example', 'pcio-vis-products' ); ?></strong>
        <code class="pcio-sc-block">[pcio_me_product_order id="42"]</code>

        <hr style="margin:32px 0">

        <!-- [───────────────────────────────────────────────] -->
        <h2 id="sc-subscription-count"><?php esc_html_e( '[pcio_me_member_subscription_count]', 'pcio-vis-products' ); ?></h2>
        <p><?php esc_html_e( 'Displays the number of members with a currently active (non-expired) membership subscription as an animated count-up number. Only subscriptions linked to a member record are counted — standalone licence-key products are excluded. Works in any page builder text or code module (e.g. Divi Text Module). Provided by the PCIO VIS Products plugin.', 'pcio-vis-products' ); ?></p>

        <table class="pcio-sc-table wp-list-table widefat">
            <thead><tr>
                <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-products' ); ?></th>
                <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-products' ); ?></th>
                <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-products' ); ?></th>
                <th><?php esc_html_e( 'Description', 'pcio-vis-products' ); ?></th>
            </tr></thead>
            <tbody>
                <tr><td colspan="4"><em><?php esc_html_e( 'No attributes.', 'pcio-vis-products' ); ?></em></td></tr>
            </tbody>
        </table>

        <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Example', 'pcio-vis-products' ); ?></strong>
        <code class="pcio-sc-block">[pcio_me_member_subscription_count]</code>
        <?php
    }
}
