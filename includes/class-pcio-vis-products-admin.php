<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Management page for the Products (shop) extension, served at /vis/shop.
 *
 * The core plugin serves a fixed set of /vis/ pages and exposes no filter to
 * register new ones, so this extension registers its own rewrite rule and
 * renders its own template (mirroring how the public /shop/* pages work). The
 * page is gated behind login + the vis_shop_manage capability and presents two
 * tabs: Products and Subscriptions.
 */
class PCIO_VIS_Products_Admin {

    public function register(): void {
        add_action( 'init',                        [ $this, 'register_rewrites' ] );
        add_action( 'template_redirect',           [ $this, 'serve' ] );
        add_action( 'admin_init',                  [ $this, 'handle_test_mode_toggle' ] );
        add_action( 'admin_menu',                  [ $this, 'register_admin_menu' ] );
        add_action( 'admin_post_pcio_mep_save_mem_rules', [ $this, 'handle_save_mem_rules' ] );
        add_action( 'pcio_me_sync_page_after',     [ $this, 'render_sync_test_mode_section' ] );
    }

    public function register_rewrites(): void {
        add_rewrite_rule( '^vis/shop/?$', 'index.php?pcio_mep_admin=1', 'top' );
        add_rewrite_tag( '%pcio_mep_admin%', '([0-9]+)' );
    }

    public function serve(): void {
        if ( ! get_query_var( 'pcio_mep_admin' ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }
        if ( ! current_user_can( 'vis_shop_manage' ) && ! current_user_can( 'vis_manage_finance' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to access this page.', 'pcio-vis-products' ),
                esc_html__( 'Access denied', 'pcio-vis-products' ),
                [ 'response' => 403 ]
            );
        }

        $this->enqueue_assets();

        status_header( 200 );
        nocache_headers();
        require PCIO_VIS_PRODUCTS_DIR . 'templates/admin.php';
        exit;
    }

    /**
     * Handle the cron test-mode toggle POST from wp-admin (sync page).
     * Redirects back to the sync page when done.
     */
    public function handle_test_mode_toggle(): void {
        if ( ! isset( $_POST['pcio_mep_toggle_test_mode'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'pcio_mep_test_mode' );
        update_option( 'pcio_mep_cron_test_mode', ! get_option( 'pcio_mep_cron_test_mode', false ) );
        wp_safe_redirect( admin_url( 'admin.php?page=pcio-me-sync' ) );
        exit;
    }

    // ── Membership Rules admin submenu ────────────────────────────

    /**
     * Add "Membership Rules" under the Vis top-level admin menu.
     */
    public function register_admin_menu(): void {
        add_submenu_page(
            'pcio-me-settings',
            __( 'Membership Rules', 'pcio-vis-products' ),
            __( 'Membership Rules', 'pcio-vis-products' ),
            'vis_shop_manage',
            'pcio-mep-membership-rules',
            [ $this, 'render_membership_rules_page' ]
        );
    }

    /**
     * Handle the Membership Rules form POST (admin-post action).
     */
    public function handle_save_mem_rules(): void {
        if ( ! current_user_can( 'vis_shop_manage' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-products' ) );
        }
        check_admin_referer( 'pcio_mep_membership_rules' );

        $rule_type = absint( wp_unslash( $_POST['mem_rule_type'] ?? 1 ) );
        if ( ! in_array( $rule_type, [ 1, 2, 3 ], true ) ) {
            $rule_type = 1;
        }
        update_option( 'pcio_mep_mem_rule_type',      $rule_type );
        update_option( 'pcio_mep_mem_start_month',    max( 1, min( 12, absint( wp_unslash( $_POST['mem_start_month']    ?? 1 ) ) ) ) );
        update_option( 'pcio_mep_mem_start_day',      max( 1, min( 28, absint( wp_unslash( $_POST['mem_start_day']      ?? 1 ) ) ) ) );
        update_option( 'pcio_mep_mem_cutting_month',  max( 0, min( 12, absint( wp_unslash( $_POST['mem_cutting_month']  ?? 0 ) ) ) ) );
        update_option( 'pcio_mep_mem_cutting_day',    max( 0, min( 28, absint( wp_unslash( $_POST['mem_cutting_day']    ?? 0 ) ) ) ) );

        set_transient( 'pcio_mep_mem_rules_saved', 1, 30 );
        wp_safe_redirect( admin_url( 'admin.php?page=pcio-mep-membership-rules' ) );
        exit;
    }

    /**
     * Render the Membership Rules configuration page.
     */
    public function render_membership_rules_page(): void {
        if ( ! current_user_can( 'vis_shop_manage' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-products' ) );
        }

        $rule_type    = (int) get_option( 'pcio_mep_mem_rule_type',     1 );
        $start_month  = (int) get_option( 'pcio_mep_mem_start_month',   1 );
        $start_day    = (int) get_option( 'pcio_mep_mem_start_day',     1 );
        $cut_month    = (int) get_option( 'pcio_mep_mem_cutting_month', 0 );
        $cut_day      = (int) get_option( 'pcio_mep_mem_cutting_day',   0 );

        $months = [];
        for ( $m = 1; $m <= 12; $m++ ) {
            $months[ $m ] = wp_date( 'F', mktime( 0, 0, 0, $m, 1 ) );
        }
        $page_url = admin_url( 'admin.php?page=pcio-mep-membership-rules' );
        ?>
        <div class="wrap">
        <h1><?php esc_html_e( 'Vis — Membership Rules', 'pcio-vis-products' ); ?></h1>

        <?php if ( get_transient( 'pcio_mep_mem_rules_saved' ) ) : delete_transient( 'pcio_mep_mem_rules_saved' ); ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Membership rules saved.', 'pcio-vis-products' ); ?></p></div>
        <?php endif; ?>

        <p><?php esc_html_e( 'These settings control how subscription expiry dates are calculated for products with the Membership flag. They also apply when a non-membership subscription product is set to "Fixed" or "Fixed with cutting date" in the product dialog.', 'pcio-vis-products' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pcio_mep_membership_rules' ); ?>
            <input type="hidden" name="action" value="pcio_mep_save_mem_rules">

            <table class="form-table" role="presentation">

                <!-- Rule type -->
                <tr>
                    <th scope="row"><?php esc_html_e( 'Active rule', 'pcio-vis-products' ); ?></th>
                    <td>
                        <fieldset>
                            <label style="display:block;margin-bottom:10px">
                                <input type="radio" name="mem_rule_type" value="1" <?php checked( $rule_type, 1 ); ?>>
                                <strong><?php esc_html_e( '1 — Floating', 'pcio-vis-products' ); ?></strong>
                                <span class="description" style="margin-left:6px"><?php esc_html_e( 'Membership runs 1 year from the purchase date.', 'pcio-vis-products' ); ?></span>
                            </label>
                            <label style="display:block;margin-bottom:10px">
                                <input type="radio" name="mem_rule_type" value="2" <?php checked( $rule_type, 2 ); ?>>
                                <strong><?php esc_html_e( '2 — Fixed annual start', 'pcio-vis-products' ); ?></strong>
                                <span class="description" style="margin-left:6px"><?php esc_html_e( 'Membership expires on the next annual start date. The checkout price should be prorated to the remaining days (implement in the checkout flow).', 'pcio-vis-products' ); ?></span>
                            </label>
                            <label style="display:block">
                                <input type="radio" name="mem_rule_type" value="3" <?php checked( $rule_type, 3 ); ?>>
                                <strong><?php esc_html_e( '3 — Fixed with cutting date', 'pcio-vis-products' ); ?></strong>
                                <span class="description" style="margin-left:6px"><?php esc_html_e( 'Before the cutting date: same as rule 2 (prorated). On or after the cutting date: member gets the remainder of the current season free and pays full price for the next full season (expiry = start date + 1 year).', 'pcio-vis-products' ); ?></span>
                            </label>
                        </fieldset>
                    </td>
                </tr>

                <!-- Annual start date -->
                <tr id="pcio-mep-start-row">
                    <th scope="row">
                        <label><?php esc_html_e( 'Annual start date', 'pcio-vis-products' ); ?></label>
                    </th>
                    <td>
                        <select name="mem_start_month" id="mem_start_month">
                            <?php foreach ( $months as $num => $name ) : ?>
                                <option value="<?php echo (int) $num; ?>" <?php selected( $start_month, $num ); ?>>
                                    <?php echo esc_html( $name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="mem_start_day" id="mem_start_day"
                               min="1" max="28" style="width:70px;margin-left:6px"
                               value="<?php echo (int) $start_day; ?>">
                        <p class="description"><?php esc_html_e( 'The day the membership year begins (used by rules 2 and 3). Day is capped at 28 to be safe in all months.', 'pcio-vis-products' ); ?></p>
                    </td>
                </tr>

                <!-- Cutting date -->
                <tr id="pcio-mep-cut-row">
                    <th scope="row">
                        <label><?php esc_html_e( 'Cutting date', 'pcio-vis-products' ); ?></label>
                    </th>
                    <td>
                        <select name="mem_cutting_month" id="mem_cutting_month">
                            <option value="0" <?php selected( $cut_month, 0 ); ?>><?php esc_html_e( '— None —', 'pcio-vis-products' ); ?></option>
                            <?php foreach ( $months as $num => $name ) : ?>
                                <option value="<?php echo (int) $num; ?>" <?php selected( $cut_month, $num ); ?>>
                                    <?php echo esc_html( $name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="mem_cutting_day" id="mem_cutting_day"
                               min="1" max="28" style="width:70px;margin-left:6px"
                               value="<?php echo (int) $cut_day; ?>">
                        <p class="description"><?php esc_html_e( 'Used by rule 3 only. On or after this date the buyer gets the rest of the current season for free and pays for the next full season. Leave month at "None" to omit.', 'pcio-vis-products' ); ?></p>
                    </td>
                </tr>

            </table>

            <?php submit_button( __( 'Save rules', 'pcio-vis-products' ) ); ?>
        </form>

        <script>
        ( function () {
            function sync() {
                var v = document.querySelector( 'input[name="mem_rule_type"]:checked' );
                var type = v ? parseInt( v.value, 10 ) : 1;
                document.getElementById( 'pcio-mep-start-row' ).style.display = type >= 2 ? '' : 'none';
                document.getElementById( 'pcio-mep-cut-row'   ).style.display = type === 3 ? '' : 'none';
            }
            document.querySelectorAll( 'input[name="mem_rule_type"]' ).forEach( function ( r ) {
                r.addEventListener( 'change', sync );
            } );
            sync();
        } )();
        </script>
        </div>
        <?php
    }

    /**
     * Render the cron test-mode toggle inside the Vis sync admin page.
     * Hooked to: pcio_me_sync_page_after
     */
    public function render_sync_test_mode_section(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $is_test     = PCIO_VIS_Products_Cron::is_test_mode();
        $admin_email = esc_html( (string) get_option( 'admin_email' ) );
        $bg          = $is_test ? '#fff8e5' : '#f0fdf4';
        $color       = $is_test ? '#92400e' : '#166534';
        $border      = $is_test ? '#f0c36d' : '#86efac';
        ?>
        <hr>
        <h2><?php esc_html_e( 'Shop Subscriptions — Cron Test Mode', 'pcio-vis-products' ); ?></h2>
        <div style="background:<?php echo esc_attr( $bg ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-radius:8px;padding:.7rem 1.1rem;display:inline-flex;align-items:center;justify-content:space-between;gap:1.5rem;min-width:480px">
            <span style="font-size:.875rem;color:<?php echo esc_attr( $color ); ?>;font-weight:600">
                <?php if ( $is_test ) : ?>
                    &#9888; <?php esc_html_e( 'Cron test mode ON', 'pcio-vis-products' ); ?> &mdash;
                    <?php
                    printf(
                        /* translators: %s: admin email address */
                        esc_html__( 'all subscription emails are redirected to %s. Expired subscriptions are NOT deleted.', 'pcio-vis-products' ),
                        '<strong>' . esc_html( $admin_email ) . '</strong>'
                    );
                    ?>
                <?php else : ?>
                    &#10003; <?php esc_html_e( 'Cron live mode — subscription emails go to members.', 'pcio-vis-products' ); ?>
                <?php endif; ?>
            </span>
            <form method="post" style="flex-shrink:0">
                <?php wp_nonce_field( 'pcio_mep_test_mode' ); ?>
                <button type="submit" name="pcio_mep_toggle_test_mode" value="1"
                        class="button button-small" style="color:<?php echo esc_attr( $color ); ?>">
                    <?php echo $is_test
                        ? esc_html__( 'Switch to live mode', 'pcio-vis-products' )
                        : esc_html__( 'Enable test mode', 'pcio-vis-products' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    private function enqueue_assets(): void {
        // Reuse the core Vis stylesheet for shared layout/table/modal styles.
        wp_enqueue_style( 'pcio-me-app', plugins_url( 'assets/members.css', PCIO_VIS_PLUGIN_FILE ), [], '1.0.6' );
        wp_enqueue_style( 'pcio-mep-admin', plugins_url( 'assets/shop-admin.css', PCIO_VIS_PRODUCTS_FILE ), [ 'pcio-me-app' ], PCIO_VIS_PRODUCTS_VERSION );

        // Image picker uses the WP media modal.
        wp_enqueue_media();

        wp_enqueue_script( 'pcio-mep-admin', plugins_url( 'assets/shop-admin.js', PCIO_VIS_PRODUCTS_FILE ), [], PCIO_VIS_PRODUCTS_VERSION . '.2', true );
        wp_add_inline_script(
            'pcio-mep-admin',
            'window.PCIO_VIS_Products_ADMIN = ' . wp_json_encode( [
                'rest'       => rest_url( PCIO_VIS_REST_NS . '/shop' ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'currency'   => PCIO_VIS_Products_Plugin::currency(),
                'membersUrl' => home_url( 'vis/members' ),
                'caps'      => [
                    'products'      => current_user_can( 'vis_shop_manage' ),
                    'subscriptions' => current_user_can( 'vis_manage_finance' ) || current_user_can( 'vis_shop_manage' ),
                    'members'       => current_user_can( 'vis_manage_users' ),
                ],
                'i18n'      => [
                    'products'        => __( 'Products', 'pcio-vis-products' ),
                    'subscriptions'   => __( 'Subscriptions', 'pcio-vis-products' ),
                    'newProduct'      => __( '+ New product', 'pcio-vis-products' ),
                    'search'          => __( 'Search…', 'pcio-vis-products' ),
                    'group'           => __( 'Group', 'pcio-vis-products' ),
                    'productName'     => __( 'Product name', 'pcio-vis-products' ),
                    'title'           => __( 'Title', 'pcio-vis-products' ),
                    'description'     => __( 'Description', 'pcio-vis-products' ),
                    'price'           => __( 'Price', 'pcio-vis-products' ),
                    'image'           => __( 'Image', 'pcio-vis-products' ),
                    'chooseImage'     => __( 'Choose image', 'pcio-vis-products' ),
                    'removeImage'     => __( 'Remove image', 'pcio-vis-products' ),
                    'isSubscription'  => __( 'Subscription', 'pcio-vis-products' ),
                    'isMembership'    => __( 'Membership', 'pcio-vis-products' ),
                    'subPeriod'       => __( 'Subscription period', 'pcio-vis-products' ),
                    'periodFloating'  => __( '1 — Floating (1 year from purchase)', 'pcio-vis-products' ),
                    'periodFixed'     => __( '2 — Fixed (expires on next annual start date)', 'pcio-vis-products' ),
                    'periodFixedCut'  => __( '3 — Fixed with cutting date', 'pcio-vis-products' ),
                    'memRuleApplied'  => __( 'Membership rule applied automatically — see Vis → Membership Rules.', 'pcio-vis-products' ),
                    'active'          => __( 'Active', 'pcio-vis-products' ),
                    'save'            => __( 'Save', 'pcio-vis-products' ),
                    'cancel'          => __( 'Cancel', 'pcio-vis-products' ),
                    'edit'            => __( 'Edit', 'pcio-vis-products' ),
                    'delete'          => __( 'Delete', 'pcio-vis-products' ),
                    'confirmDelete'   => __( 'Delete this item?', 'pcio-vis-products' ),
                    'editProduct'     => __( 'Edit product', 'pcio-vis-products' ),
                    'createProduct'   => __( 'New product', 'pcio-vis-products' ),
                    'licenseKey'      => __( 'Licence key', 'pcio-vis-products' ),
                    'clickToCopy'     => __( 'Click to copy', 'pcio-vis-products' ),
                    'customer'        => __( 'Customer', 'pcio-vis-products' ),
                    'email'           => __( 'Email', 'pcio-vis-products' ),
                    'expires'         => __( 'Expires', 'pcio-vis-products' ),
                    'status'          => __( 'Status', 'pcio-vis-products' ),
                    'memberNo'        => __( 'Member #', 'pcio-vis-products' ),
                    'membershipNo'    => __( 'Membership #', 'pcio-vis-products' ),
                    'counted'         => __( 'Counted', 'pcio-vis-products' ),
                    'newSubscription' => __( '+ New subscription', 'pcio-vis-products' ),
                    'editSubscription'=> __( 'Edit subscription', 'pcio-vis-products' ),
                    'createSubscription' => __( 'New subscription', 'pcio-vis-products' ),
                    'memberNumber'    => __( 'Member number', 'pcio-vis-products' ),
                    'memberNumberHint'=> __( 'Optional. Links the subscription to a member so it can count as an active membership.', 'pcio-vis-products' ),
                    'product'         => __( 'Product', 'pcio-vis-products' ),
                    'membershipProductHint' => __( 'Choose a membership product for it to count in the active-membership total.', 'pcio-vis-products' ),
                    'statusActive'    => __( 'Active', 'pcio-vis-products' ),
                    'statusExpired'   => __( 'Expired', 'pcio-vis-products' ),
                    'statusRefunded'  => __( 'Refunded', 'pcio-vis-products' ),
                    'statusCancelled' => __( 'Cancelled', 'pcio-vis-products' ),
                    'membershipTag'   => __( 'membership', 'pcio-vis-products' ),
                    'yes'             => __( 'Yes', 'pcio-vis-products' ),
                    'no'              => __( 'No', 'pcio-vis-products' ),
                    'priceHint'       => __( 'Amount in major units (e.g. 49.00). 0 = free.', 'pcio-vis-products' ),
                    'nameUnique'      => __( 'A unique internal identifier (must be unique).', 'pcio-vis-products' ),
                    'noProducts'      => __( 'No products yet.', 'pcio-vis-products' ),
                    'noSubs'          => __( 'No subscriptions yet.', 'pcio-vis-products' ),
                    'saveFailed'      => __( 'Save failed.', 'pcio-vis-products' ),
                    'allGroups'       => __( 'All groups', 'pcio-vis-products' ),
                    'filterWord'      => __( 'Filter', 'pcio-vis-products' ),
                    'showing'         => __( 'Showing', 'pcio-vis-products' ),
                    'of'              => __( 'of', 'pcio-vis-products' ),
                    'emptyFiltered'   => __( 'No matches.', 'pcio-vis-products' ),
                    // Delivery methods tab
                    'deliveryMethods' => __( 'Delivery Methods', 'pcio-vis-products' ),
                    'noDMethods'      => __( 'No delivery methods yet.', 'pcio-vis-products' ),
                    'newMethod'       => __( '+ New method', 'pcio-vis-products' ),
                    'editMethod'      => __( 'Edit delivery method', 'pcio-vis-products' ),
                    'dmName'          => __( 'Name', 'pcio-vis-products' ),
                    'dmNamePh'        => __( 'e.g. By mail', 'pcio-vis-products' ),
                    'dmDesc'          => __( 'Description', 'pcio-vis-products' ),
                    'dmDescPh'        => __( 'Short note shown to members', 'pcio-vis-products' ),
                    'dmSort'          => __( 'Sort', 'pcio-vis-products' ),
                ],
            ] ) . ';',
            'before'
        );
    }
}
