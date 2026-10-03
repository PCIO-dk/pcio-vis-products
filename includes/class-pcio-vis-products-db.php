<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; caching would hide real-time shop/order changes.

/**
 * Database layer for the Products (shop) extension.
 *
 * Tables (all prefixed with $wpdb->prefix)
 * ────────────────────────────────────────
 *   me_p_products      – sellable products. `product_name` is unique. When
 *                        `is_subscription` is set, a subscription row is created
 *                        on purchase; `is_membership` additionally links the
 *                        buyer to a Member record.
 *   me_p_subscriptions – one row per purchased subscription. `license_token` is
 *                        the licence key the customer pastes into their PCIO
 *                        plugin (verified via the public /shop/verify endpoint);
 *                        `verify_token` authorises quick links in emails.
 *   me_p_customers     – buyers (deduplicated by email).
 *   me_p_orders        – one purchase. `status` tracks the lifecycle.
 *   me_p_order_items   – the chosen products + quantities for an order.
 *
 * Amounts are stored in minor units (øre / cents) as integers.
 */
class PCIO_VIS_Products_DB {

    const TABLE_PRODUCTS      = 'me_p_products';
    const TABLE_SUBSCRIPTIONS = 'me_p_subscriptions';
    const TABLE_CUSTOMERS     = 'me_p_customers';
    const TABLE_ORDERS        = 'me_p_orders';
    const TABLE_ORDER_ITEMS   = 'me_p_order_items';

    /** Valid values for the subscription_period product column. */
    const VALID_PERIODS = [ 'floating', 'fixed', 'fixed_cut' ];

    const ORDER_STATUSES = [
        'pending',          // awaiting payment (Stripe session open)
        'paid',             // Stripe payment completed
        'free_confirmed',   // 0-cost order confirmed without Stripe
        'refunded',         // fully refunded
        'partly_refunded',  // partially refunded
        'cancelled',        // abandoned / failed
    ];

    // ── Table-name helpers ────────────────────────────────────────

    public static function t_products(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_PRODUCTS;
    }
    public static function t_subscriptions(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUBSCRIPTIONS;
    }
    public static function t_customers(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_CUSTOMERS;
    }
    public static function t_orders(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_ORDERS;
    }
    public static function t_order_items(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_ORDER_ITEMS;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        if ( get_option( 'pcio_mep_db_version' ) === PCIO_VIS_PRODUCTS_VERSION ) {
            return; // schema already current
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset  = $wpdb->get_charset_collate();
        $products = self::t_products();
        $subs     = self::t_subscriptions();
        $cust     = self::t_customers();
        $orders   = self::t_orders();
        $items    = self::t_order_items();

        // 1.3 migration: member_subscription_number -> member_number. Rename the
        // existing column before dbDelta runs, otherwise dbDelta would add an
        // empty member_number column and orphan the stored membership numbers.
        self::migrate_member_number_column( $subs );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$products} (
                id              INT          NOT NULL AUTO_INCREMENT,
                group_name      VARCHAR(120) NOT NULL DEFAULT '',
                product_name    VARCHAR(190) NOT NULL DEFAULT '',
                image_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
                title           VARCHAR(200) NOT NULL DEFAULT '',
                description     LONGTEXT,
                price_cents     INT          NOT NULL DEFAULT 0,
                currency        VARCHAR(3)   NOT NULL DEFAULT 'DKK',
                is_subscription TINYINT(1)   NOT NULL DEFAULT 0,
                is_membership   TINYINT(1)   NOT NULL DEFAULT 0,
                subscription_period VARCHAR(10)  NOT NULL DEFAULT 'floating',
                sort            INT          NOT NULL DEFAULT 0,
                active          TINYINT(1)   NOT NULL DEFAULT 1,
                created         DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated         DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_products_name (product_name),
                KEY idx_products_group (group_name)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$subs} (
                id               INT          NOT NULL AUTO_INCREMENT,
                license_token    VARCHAR(64)  NOT NULL DEFAULT '',
                verify_token     VARCHAR(64)  NOT NULL DEFAULT '',
                product_name     VARCHAR(190) NOT NULL DEFAULT '',
                customer_name    VARCHAR(200) NOT NULL DEFAULT '',
                customer_email   VARCHAR(190) NOT NULL DEFAULT '',
                expire_date      DATE         NULL DEFAULT NULL,
                member_id        INT          NOT NULL DEFAULT 0,
                member_number    INT          NOT NULL DEFAULT 0,
                order_id         INT          NOT NULL DEFAULT 0,
                status           VARCHAR(20)  NOT NULL DEFAULT 'active',
                reminder_sent_at DATETIME     NULL DEFAULT NULL,
                created          DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated          DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_subs_license (license_token),
                KEY idx_subs_verify (verify_token),
                KEY idx_subs_email (customer_email),
                KEY idx_subs_member (member_id),
                KEY idx_subs_msn (member_number),
                KEY idx_subs_expire (expire_date)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$cust} (
                id       INT          NOT NULL AUTO_INCREMENT,
                email    VARCHAR(190) NOT NULL DEFAULT '',
                name     VARCHAR(200) NOT NULL DEFAULT '',
                created  DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_cust_email (email)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$orders} (
                id                    INT          NOT NULL AUTO_INCREMENT,
                order_no              VARCHAR(20)  NOT NULL DEFAULT '',
                token                 VARCHAR(64)  NOT NULL DEFAULT '',
                customer_id           INT          NOT NULL DEFAULT 0,
                email                 VARCHAR(190) NOT NULL DEFAULT '',
                status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
                summary               TEXT,
                amount_cents          INT          NOT NULL DEFAULT 0,
                refunded_cents        INT          NOT NULL DEFAULT 0,
                currency              VARCHAR(3)   NOT NULL DEFAULT 'DKK',
                kind                  VARCHAR(20)  NOT NULL DEFAULT 'shop',
                subscription_id       INT          NOT NULL DEFAULT 0,
                stripe_session_id     VARCHAR(255) NULL DEFAULT NULL,
                stripe_payment_intent VARCHAR(255) NULL DEFAULT NULL,
                created               DATETIME     DEFAULT CURRENT_TIMESTAMP,
                paid_at               DATETIME     NULL DEFAULT NULL,
                refunded_at           DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_orders_token (token),
                UNIQUE KEY idx_orders_no (order_no),
                KEY idx_orders_session (stripe_session_id)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$items} (
                id               INT NOT NULL AUTO_INCREMENT,
                order_id         INT NOT NULL,
                product_id       INT NOT NULL,
                product_name     VARCHAR(190) NOT NULL DEFAULT '',
                qty              INT NOT NULL DEFAULT 0,
                unit_price_cents INT NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY idx_items_order (order_id)
                ) {$charset};"
        );

        // Delivery-feature tables and order column migrations.
        PCIO_VIS_Products_Delivery_DB::install();

        // Note: the subscription_period column is part of the products CREATE TABLE
        // definition above, so dbDelta adds it to existing tables automatically.

        // dbDelta sometimes skips ADD COLUMN on existing tables — apply directly.
        $subs = self::t_subscriptions();
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $subs, 'member_number' ) ) ) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `member_number` INT NOT NULL DEFAULT 0', $subs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        }
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $subs, 'idx_subs_msn' ) ) ) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY `idx_subs_msn` (`member_number`)', $subs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        update_option( 'pcio_mep_db_version', PCIO_VIS_PRODUCTS_VERSION );
        // Signal init to flush rewrite rules — new /vis/delivery route was added.
        update_option( 'pcio_mep_flush_rewrites', '1' );
    }

    /**
     * Rename wp_me_p_subscriptions.member_subscription_number to member_number.
     *
     * Data preserving and idempotent. No-op once the legacy column is gone, so
     * it is safe whether or not the core membership migration has already run:
     *
     * - only the legacy column exists  -> renamed in place, values untouched;
     * - both columns exist            -> values copied into member_number and
     *                                     the legacy column dropped (dbDelta
     *                                     can have added the new column
     *                                     already, so the copy is required).
     */
    private static function migrate_member_number_column( string $table ): void {
        global $wpdb;

        $legacy = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'member_subscription_number' ) );
        if ( ! $legacy ) {
            return;
        }

        $current = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'member_number' ) );
        if ( ! $current ) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN `member_subscription_number` `member_number` INT NOT NULL DEFAULT 0', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            return;
        }

        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `member_number` = `member_subscription_number` WHERE `member_number` = 0 AND `member_subscription_number` > 0', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN `member_subscription_number`', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    // ── Products ──────────────────────────────────────────────────

    /**
     * All products, optionally restricted to one group, ordered by sort then id.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_products( string $group = '', bool $active_only = false ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE ( %s = '' OR group_name = %s )
                   AND ( %d = 0 OR active = 1 )
                 ORDER BY sort ASC, id ASC",
                self::t_products(),
                $group,
                $group,
                $active_only ? 1 : 0
            ),
            ARRAY_A
        );
        return array_map( [ self::class, 'shape_product' ], $rows ?: [] );
    }

    public static function get_product( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_products(), $id ),
            ARRAY_A
        );
        return $row ? self::shape_product( $row ) : null;
    }

    public static function get_product_by_name( string $product_name ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE product_name = %s', self::t_products(), $product_name ),
            ARRAY_A
        );
        return $row ? self::shape_product( $row ) : null;
    }

    /**
     * Distinct group names that exist (for the filter dropdowns).
     *
     * @return string[]
     */
    public static function get_groups(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare( "SELECT DISTINCT group_name FROM %i WHERE group_name <> '' ORDER BY group_name ASC", self::t_products() )
        );
        return $rows ?: [];
    }

    /**
     * Create a product. Returns the new id, or false when the name collides.
     *
     * @return int|false
     */
    public static function create_product( array $data ) {
        global $wpdb;
        $clean = self::sanitize_product( $data );
        if ( empty( $clean['product_name'] ) ) {
            return false;
        }
        if ( self::get_product_by_name( $clean['product_name'] ) ) {
            return false; // product_name must be unique
        }
        $next_sort = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort),0)+1 FROM %i', self::t_products() ) );
        $clean['sort'] = $next_sort;
        $wpdb->insert( self::t_products(), $clean, self::product_formats( $clean ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update a subset of fields on a product. Refuses a duplicate product_name.
     */
    public static function update_product( int $id, array $data ): bool {
        global $wpdb;
        $clean = self::sanitize_product( $data );
        if ( ! $clean ) {
            return false;
        }
        if ( isset( $clean['product_name'] ) ) {
            $other = self::get_product_by_name( $clean['product_name'] );
            if ( $other && (int) $other['id'] !== $id ) {
                return false; // would collide with another product
            }
        }
        $clean['updated'] = current_time( 'mysql' );
        return false !== $wpdb->update( self::t_products(), $clean, [ 'id' => $id ], self::product_formats( $clean ), [ '%d' ] );
    }

    public static function delete_product( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( self::t_products(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Subscriptions ─────────────────────────────────────────────

    /**
     * All subscriptions, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_subscriptions(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', self::t_subscriptions() ), ARRAY_A );
        return array_map( [ self::class, 'shape_subscription' ], $rows ?: [] );
    }

    public static function get_subscription( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_subscriptions(), $id ),
            ARRAY_A
        );
        return $row ? self::shape_subscription( $row ) : null;
    }

    public static function get_subscription_by_license( string $token ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE license_token = %s', self::t_subscriptions(), $token ),
            ARRAY_A
        );
        return $row ? self::shape_subscription( $row ) : null;
    }

    public static function get_subscription_by_verify( string $token ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE verify_token = %s', self::t_subscriptions(), $token ),
            ARRAY_A
        );
        return $row ? self::shape_subscription( $row ) : null;
    }

    /**
     * All subscriptions created from a given order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_subscriptions_for_order( int $order_id ): array {
        global $wpdb;
        if ( $order_id < 1 ) {
            return [];
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC',
                self::t_subscriptions(),
                $order_id
            ),
            ARRAY_A
        );
        return array_map( [ self::class, 'shape_subscription' ], $rows ?: [] );
    }

    /**
     * Active subscriptions for a member (used for the member-list status column).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_subscriptions_for_member( int $member_id ): array {
        global $wpdb;
        if ( $member_id < 1 ) {
            return [];
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE member_id = %d ORDER BY expire_date DESC',
                self::t_subscriptions(),
                $member_id
            ),
            ARRAY_A
        );
        return array_map( [ self::class, 'shape_subscription' ], $rows ?: [] );
    }

    /**
     * Create a subscription row. `expire_date` is one year out by default.
     */
    public static function create_subscription( array $data ): int {
        global $wpdb;
        $expire = ! empty( $data['expire_date'] )
            ? sanitize_text_field( $data['expire_date'] )
            : gmdate( 'Y-m-d', strtotime( '+1 year' ) );
        $wpdb->insert(
            self::t_subscriptions(),
            [
                'license_token'  => self::unique_token( self::t_subscriptions(), 'license_token' ),
                'verify_token'   => self::generate_token(),
                'product_name'   => sanitize_text_field( $data['product_name'] ?? '' ),
                'customer_name'  => sanitize_text_field( $data['customer_name'] ?? '' ),
                'customer_email' => sanitize_email( $data['customer_email'] ?? '' ),
                'expire_date'    => $expire,
                'member_id'      => (int) ( $data['member_id'] ?? 0 ),
                'member_number'  => (int) ( $data['member_number'] ?? 0 ),
                'order_id'       => (int) ( $data['order_id'] ?? 0 ),
                'status'         => sanitize_text_field( $data['status'] ?? 'active' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function update_subscription( int $id, array $data, array $formats ): bool {
        global $wpdb;
        $data['updated'] = current_time( 'mysql' );
        $formats[]       = '%s';
        return false !== $wpdb->update( self::t_subscriptions(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public static function delete_subscription( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( self::t_subscriptions(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Extend a subscription's expiry by a number of years from the later of its
     * current expiry or today (so renewing early never loses paid-for time).
     */
    public static function renew_subscription( int $id, int $years = 1 ): bool {
        $sub = self::get_subscription( $id );
        if ( ! $sub ) {
            return false;
        }
        $base = $sub['expire_date'] && strtotime( $sub['expire_date'] ) > time()
            ? $sub['expire_date']
            : gmdate( 'Y-m-d' );
        $new = gmdate( 'Y-m-d', strtotime( $base . ' +' . max( 1, $years ) . ' year' ) );
        return self::update_subscription(
            $id,
            [ 'expire_date' => $new, 'status' => 'active', 'reminder_sent_at' => null ],
            [ '%s', '%s', '%s' ]
        );
    }

    /**
     * Subscriptions expiring within $days that have not yet been reminded.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function subscriptions_expiring_within( int $days ): array {
        global $wpdb;
        $today  = gmdate( 'Y-m-d' );
        $cutoff = gmdate( 'Y-m-d', strtotime( '+' . max( 1, $days ) . ' days' ) );
        $rows   = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i'
                . ' WHERE expire_date IS NOT NULL AND expire_date >= %s AND expire_date <= %s AND reminder_sent_at IS NULL',
                self::t_subscriptions(),
                $today,
                $cutoff
            ),
            ARRAY_A
        );
        return array_map( [ self::class, 'shape_subscription' ], $rows ?: [] );
    }

    /**
     * Subscriptions whose expiry date has already passed.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function subscriptions_expired(): array {
        global $wpdb;
        $today = gmdate( 'Y-m-d' );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE expire_date IS NOT NULL AND expire_date < %s AND status NOT IN ( 'expired', 'cancelled' )",
                self::t_subscriptions(),
                $today
            ),
            ARRAY_A
        );
        return array_map( [ self::class, 'shape_subscription' ], $rows ?: [] );
    }

    /**
     * Count distinct members that hold a currently active (non-expired) membership
     * subscription. Active is judged by the expiry date (>= today), not the stored
     * status column, so it stays correct even as that column is no longer maintained.
     */
    public static function count_active_member_subscriptions(): int {
        global $wpdb;
        $today = gmdate( 'Y-m-d' );
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT s.member_id)
                 FROM %i s
                 INNER JOIN %i p ON p.product_name = s.product_name
                 WHERE p.is_membership = 1
                   AND s.member_id > 0
                   AND s.status <> 'cancelled'
                   AND s.expire_date IS NOT NULL
                   AND s.expire_date >= %s",
                self::t_subscriptions(),
                self::t_products(),
                $today
            )
        );
    }

    /**
     * Latest expiry date covering a membership number (empty when none / all
     * refunded/cancelled). A member with this number is "paid" when the returned
     * date is today or later.
     */
    public static function coverage_expiry_for_number( int $number ): string {
        global $wpdb;
        if ( $number <= 0 ) {
            return '';
        }
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(s.expire_date)
                 FROM %i s
                 LEFT JOIN %i o ON o.id = s.order_id
                 WHERE s.member_number = %d
                   AND s.status <> 'cancelled'
                   AND ( o.status IS NULL OR o.status <> 'refunded' )",
                self::t_subscriptions(),
                self::t_orders(),
                $number
            )
        );
        return (string) ( $val ?? '' );
    }

    /**
     * All subscriptions for the admin list, joined with the linked member number
     * and the product's membership flag, plus a `counts` flag marking the rows
     * that count_active_member_subscriptions() (and the shortcode) includes.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_subscriptions_admin(): array {
        global $wpdb;
        $members = $wpdb->prefix . 'me_members';
        $today   = gmdate( 'Y-m-d' );
        $rows    = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, m.member_number AS member_number, o.status AS order_status
                 FROM %i s
                 LEFT JOIN %i m ON m.id = s.member_id
                 LEFT JOIN %i o ON o.id = s.order_id
                 ORDER BY s.id DESC",
                self::t_subscriptions(),
                $members,
                self::t_orders()
            ),
            ARRAY_A
        );

        $out = [];
        foreach ( $rows ?: [] as $row ) {
            $sub                  = self::shape_subscription( $row );
            $sub['member_number'] = (string) ( $row['member_number'] ?? '' );
            // Status is derived, not stored, so it never drifts from the expiry date.
            $sub['status']        = self::derive_subscription_status(
                (string) ( $row['status'] ?? '' ),
                (string) ( $row['order_status'] ?? '' ),
                (string) ( $row['expire_date'] ?? '' ),
                $today
            );
            $out[] = $sub;
        }
        return $out;
    }

    /**
     * Derive the display status: a manual cancel or a refunded order wins;
     * otherwise it is active/expired purely by the expiry date.
     */
    private static function derive_subscription_status( string $stored, string $order_status, string $expire, string $today ): string {
        if ( 'cancelled' === $stored ) {
            return 'cancelled';
        }
        if ( 'refunded' === $order_status ) {
            return 'refunded';
        }
        if ( '' !== $expire && $expire >= $today ) {
            return 'active';
        }
        return 'expired';
    }

    /**
     * Resolve a member number to the internal me_members.id (0 when not found).
     */
    public static function member_id_from_number( string $number ): int {
        global $wpdb;
        $number = trim( $number );
        if ( '' === $number ) {
            return 0;
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE member_number = %s LIMIT 1',
                $wpdb->prefix . 'me_members',
                $number
            )
        );
    }

    // ── Customers ─────────────────────────────────────────────────
    public static function get_customer( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_customers(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function upsert_customer( string $email, string $name = '' ): int {
        global $wpdb;
        $email = sanitize_email( $email );
        $id    = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', self::t_customers(), $email )
        );
        if ( $id ) {
            if ( $name !== '' ) {
                $wpdb->update( self::t_customers(), [ 'name' => sanitize_text_field( $name ) ], [ 'id' => $id ] );
            }
            return $id;
        }
        $wpdb->insert(
            self::t_customers(),
            [ 'email' => $email, 'name' => sanitize_text_field( $name ) ],
            [ '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    // ── Orders ────────────────────────────────────────────────────

    public static function create_order( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            self::t_orders(),
            [
                'order_no'     => self::generate_order_no(),
                'token'        => self::generate_token(),
                'customer_id'  => (int) ( $data['customer_id'] ?? 0 ),
                'email'        => sanitize_email( $data['email'] ?? '' ),
                'status'       => in_array( $data['status'] ?? 'pending', self::ORDER_STATUSES, true ) ? $data['status'] : 'pending',
                'summary'      => wp_kses_post( $data['summary'] ?? '' ),
                'amount_cents'    => (int) ( $data['amount_cents'] ?? 0 ),
                'currency'        => substr( strtoupper( $data['currency'] ?? 'DKK' ), 0, 3 ),
                'kind'            => sanitize_key( $data['kind'] ?? 'shop' ),
                'subscription_id' => (int) ( $data['subscription_id'] ?? 0 ),
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function get_order( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t_orders(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_order_by_token( string $token ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', self::t_orders(), $token ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_order_by_session( string $session_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE stripe_session_id = %s', self::t_orders(), $session_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function update_order( int $id, array $data, array $formats ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::t_orders(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    /**
     * List orders for the finance screen, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_orders( array $args = [] ): array {
        global $wpdb;
        $limit  = max( 1, (int) ( $args['limit'] ?? 1000 ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
        $rows   = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
                self::t_orders(),
                $limit,
                $offset
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    /**
     * Atomically move an order from one status to another. Only one caller can
     * win the transition, which keeps confirmation idempotent across the free
     * path and the Stripe return-page reconciliation — no double fulfilment.
     */
    public static function claim_order( int $order_id, string $from_status, string $to_status ): bool {
        global $wpdb;
        if ( ! in_array( $to_status, self::ORDER_STATUSES, true ) ) {
            return false;
        }
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s WHERE id = %d AND status = %s',
                self::t_orders(),
                $to_status,
                $order_id,
                $from_status
            )
        );
        return 1 === (int) $updated;
    }

    /**
     * Cancel pending orders left abandoned for longer than $minutes.
     *
     * @return int Number of orders cancelled.
     */
    public static function cancel_stale_pending( int $minutes = 240 ): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS ) );
        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i' . " SET status = 'cancelled' WHERE status = 'pending' AND created < %s",
                self::t_orders(),
                $cutoff
            )
        );
    }

    // ── Order items ───────────────────────────────────────────────

    public static function add_order_item( int $order_id, int $product_id, string $product_name, int $qty, int $unit_price_cents ): void {
        global $wpdb;
        $wpdb->insert(
            self::t_order_items(),
            [
                'order_id'         => $order_id,
                'product_id'       => $product_id,
                'product_name'     => $product_name,
                'qty'              => $qty,
                'unit_price_cents' => $unit_price_cents,
            ],
            [ '%d', '%d', '%s', '%d', '%d' ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function get_order_items( int $order_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', self::t_order_items(), $order_id ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    // ── Internal helpers ──────────────────────────────────────────

    private static function shape_product( array $row ): array {
        return [
            'id'              => (int) $row['id'],
            'group_name'      => (string) $row['group_name'],
            'product_name'    => (string) $row['product_name'],
            'image_id'        => (int) $row['image_id'],
            'image_url'       => $row['image_id'] ? (string) wp_get_attachment_image_url( (int) $row['image_id'], 'large' ) : '',
            'title'           => (string) $row['title'],
            'description'     => (string) $row['description'],
            'price_cents'     => (int) $row['price_cents'],
            'currency'        => (string) $row['currency'],
            'is_subscription'     => (int) $row['is_subscription'],
            'is_membership'       => (int) $row['is_membership'],
            'subscription_period' => (string) ( $row['subscription_period'] ?? 'floating' ),
            'sort'                => (int) $row['sort'],
            'active'              => (int) $row['active'],
        ];
    }

    private static function shape_subscription( array $row ): array {
        return [
            'id'               => (int) $row['id'],
            'license_token'    => (string) $row['license_token'],
            'verify_token'     => (string) $row['verify_token'],
            'product_name'     => (string) $row['product_name'],
            'customer_name'    => (string) $row['customer_name'],
            'customer_email'   => (string) $row['customer_email'],
            'expire_date'      => $row['expire_date'],
            'member_id'        => (int) $row['member_id'],
            'member_number'    => (int) ( $row['member_number'] ?? 0 ),
            'order_id'         => (int) $row['order_id'],
            'status'           => (string) $row['status'],
            'reminder_sent_at' => $row['reminder_sent_at'],
            'created'          => $row['created'],
        ];
    }

    /**
     * Sanitize an incoming product payload to only the writable fields.
     *
     * @return array<string,mixed>
     */
    private static function sanitize_product( array $data ): array {
        $out = [];
        if ( array_key_exists( 'group_name', $data ) ) {
            $out['group_name'] = sanitize_text_field( $data['group_name'] );
        }
        if ( array_key_exists( 'product_name', $data ) ) {
            $out['product_name'] = sanitize_text_field( $data['product_name'] );
        }
        if ( array_key_exists( 'image_id', $data ) ) {
            $out['image_id'] = max( 0, (int) $data['image_id'] );
        }
        if ( array_key_exists( 'title', $data ) ) {
            $out['title'] = sanitize_text_field( $data['title'] );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $out['description'] = wp_kses_post( $data['description'] );
        }
        if ( array_key_exists( 'price_cents', $data ) ) {
            $out['price_cents'] = max( 0, (int) $data['price_cents'] );
        }
        if ( array_key_exists( 'currency', $data ) ) {
            $out['currency'] = substr( strtoupper( sanitize_text_field( $data['currency'] ) ), 0, 3 );
        }
        if ( array_key_exists( 'is_subscription', $data ) ) {
            $out['is_subscription'] = (int) (bool) $data['is_subscription'];
        }
        if ( array_key_exists( 'is_membership', $data ) ) {
            $out['is_membership'] = (int) (bool) $data['is_membership'];
        }
        if ( array_key_exists( 'subscription_period', $data ) ) {
            $v = sanitize_key( (string) $data['subscription_period'] );
            $out['subscription_period'] = in_array( $v, self::VALID_PERIODS, true ) ? $v : 'floating';
        }
        if ( array_key_exists( 'active', $data ) ) {
            $out['active'] = (int) (bool) $data['active'];
        }
        return $out;
    }

    /**
     * Build the $wpdb format array matching a sanitized product payload.
     *
     * @return string[]
     */
    private static function product_formats( array $clean ): array {
        $formats = [];
        foreach ( array_keys( $clean ) as $field ) {
            $formats[] = in_array( $field, [ 'group_name', 'product_name', 'title', 'description', 'currency', 'updated', 'subscription_period' ], true ) ? '%s' : '%d';
        }
        return $formats;
    }

    /**
     * Compute a subscription expiry date for the given period type.
     *
     * Period types:
     *  floating  – 1 year from today (ignores admin dates)
     *  fixed     – next occurrence of the admin-configured start date;
     *              price should be prorated to the remaining days
     *  fixed_cut – like fixed, but on/after the admin cutting date the expiry
     *              becomes start_date + 1 year (buyer gets current tail for free)
     *
     * @param string $period One of VALID_PERIODS.
     * @return string Y-m-d date string.
     */
    public static function compute_expire_date( string $period ): string {
        if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
            $period = 'floating';
        }
        if ( $period === 'floating' ) {
            return gmdate( 'Y-m-d', strtotime( '+1 year' ) );
        }

        $start_m = max( 1, min( 12, (int) get_option( 'pcio_mep_mem_start_month', 1 ) ) );
        $start_d = max( 1, min( 28, (int) get_option( 'pcio_mep_mem_start_day',   1 ) ) );
        $tz      = wp_timezone();
        $today   = new DateTimeImmutable( 'today', $tz );
        $year    = (int) $today->format( 'Y' );

        $start_this = new DateTimeImmutable(
            sprintf( '%04d-%02d-%02d', $year, $start_m, $start_d ),
            $tz
        );
        $next_start = $today < $start_this ? $start_this : $start_this->modify( '+1 year' );

        if ( $period === 'fixed' ) {
            return $next_start->format( 'Y-m-d' );
        }

        // fixed_cut: check cutting date.
        $cut_m = (int) get_option( 'pcio_mep_mem_cutting_month', 0 );
        $cut_d = (int) get_option( 'pcio_mep_mem_cutting_day',   0 );
        if ( $cut_m >= 1 && $cut_m <= 12 && $cut_d >= 1 && $cut_d <= 28 ) {
            $cut_this = new DateTimeImmutable(
                sprintf( '%04d-%02d-%02d', $year, $cut_m, $cut_d ),
                $tz
            );
            if ( $today >= $cut_this ) {
                // On or after cutting date: free tail, pay for next full season.
                return $next_start->modify( '+1 year' )->format( 'Y-m-d' );
            }
        }
        return $next_start->format( 'Y-m-d' );
    }

    private static function generate_token(): string {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Generate a token that is unique within the given table column.
     */
    private static function unique_token( string $table, string $column ): string {
        global $wpdb;
        do {
            $token  = self::generate_token();
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %s', $table, $column, $token )
            );
        } while ( $exists );
        return $token;
    }

    /**
     * Human-friendly, unique order number (e.g. "M7K3Q2P9").
     */
    private static function generate_order_no(): string {
        global $wpdb;
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ( $i = 0; $i < 8; $i++ ) {
                $code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
            }
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_no = %s', self::t_orders(), $code )
            );
        } while ( $exists );
        return $code;
    }
}
