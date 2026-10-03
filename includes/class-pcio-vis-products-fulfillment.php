<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Order fulfilment for the Products (shop) extension.
 *
 * Listens for `pcio_mep_order_confirmed` (fired by the free checkout path and
 * by the Stripe return-page reconciliation) and, for each confirmed order:
 *   • creates a subscription row (with a licence key) for every purchased unit
 *     of an is_subscription product;
 *   • for an is_membership product, links the buyer to a Member record (creating
 *     a standard member + welcome email when the email is unknown) and stamps
 *     the member's subscription status;
 *   • emails the buyer a confirmation containing their licence keys.
 *
 * Sending is routed through the core `pcio_me_mailer_send` filter (so a
 * Postmark/SendGrid extension handles it when present) with a wp_mail() fallback.
 */
class PCIO_VIS_Products_Fulfillment {

    const MAIL_TYPE_ORDER_CONFIRMATION    = 'shop_order_confirmation';
    const MAIL_TYPE_DELIVERY_NOTIFICATION = 'shop_delivery_notification';
    const MAIL_TYPE_MEMBERSHIP_PROLONGED  = 'membership_prolonged';
    const MAIL_TYPE_SUBSCRIPTION_PURCHASED = 'subscription_purchased';

    /**
     * Per-order context recorded while creating subscriptions in fulfil()
     * (priority 10) and read back when the single purchase email is sent in
     * send_receipt() (priority 20) within the same request.
     *
     * @var array<int,array{is_membership:bool,is_new_membership:bool,member_id:int,member_number:string}>
     */
    private array $order_ctx = [];

    public function register(): void {
        add_action( 'pcio_mep_order_confirmed',    [ $this, 'fulfil' ],       10, 1 );
        add_action( 'pcio_mep_order_confirmed',    [ $this, 'send_receipt' ], 20, 1 );
        add_filter( 'pcio_me_mail_tags',            [ $this, 'register_mail_tags' ], 10, 2 );
        add_action( 'admin_init',                   [ __CLASS__, 'maybe_seed_mails' ] );
        add_action( 'pcio_me_sync_register_jobs',   [ __CLASS__, 'seed_mails' ] );
    }

    // ── Mail template seeding ─────────────────────────────────────

    public static function maybe_seed_mails(): void {
        if ( get_option( 'pcio_mep_fulfillment_mails_seeded_v2' ) ) {
            return;
        }
        self::seed_mails();
    }

    public static function seed_mails(): void {
        if ( ! class_exists( 'PCIO_VIS_Mails_DB' ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        PCIO_VIS_Mails_DB::install();

        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_ORDER_CONFIRMATION ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'Your order {order_no}', 'pcio-vis-products' ),
                'content'  =>
                    '<p>' . __( 'Thank you for your purchase!', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Order number: <strong>{order_no}</strong>', 'pcio-vis-products' ) . '</p>'
                    . '<p>{summary}</p>'
                    . '{licence_keys}'
                    . '<p>{site_name}</p>',
                'mailtype' => self::MAIL_TYPE_ORDER_CONFIRMATION,
            ] );
        }

        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_DELIVERY_NOTIFICATION ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'New delivery order: {order_no}', 'pcio-vis-products' ),
                'content'  =>
                    '<p>' . __( 'A new delivery order has been placed on {site_name}.', 'pcio-vis-products' ) . '</p>'
                    . '<h3>' . __( 'Order', 'pcio-vis-products' ) . '</h3>'
                    . '<p><strong>' . __( 'Order number', 'pcio-vis-products' ) . ':</strong> {order_no}</p>'
                    . '<p><strong>' . __( 'Delivery method', 'pcio-vis-products' ) . ':</strong> {delivery_method}</p>'
                    . '<h3>' . __( 'Items', 'pcio-vis-products' ) . '</h3>'
                    . '{items}'
                    . '<h3>' . __( 'Customer', 'pcio-vis-products' ) . '</h3>'
                    . '{customer_block}'
                    . '<p>{site_name}</p>',
                'mailtype' => self::MAIL_TYPE_DELIVERY_NOTIFICATION,
            ] );
        }

        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_MEMBERSHIP_PROLONGED ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'Your membership at {site_name} has been renewed', 'pcio-vis-products' ),
                'content'  =>
                    '<p>' . __( 'Hi {name},', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Thank you — your membership <strong>{product_name}</strong> has been renewed.', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'It is now valid until <strong>{expire_date}</strong>. Your member number is <strong>{member_number}</strong>.', 'pcio-vis-products' ) . '</p>'
                    . '<p>{site_name}</p>',
                'mailtype' => self::MAIL_TYPE_MEMBERSHIP_PROLONGED,
            ] );
        }

        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_SUBSCRIPTION_PURCHASED ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'Your {product_name} subscription', 'pcio-vis-products' ),
                'content'  =>
                    '<p>' . __( 'Hi {name},', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Thank you for subscribing to <strong>{product_name}</strong>.', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Your subscription is valid until <strong>{expire_date}</strong>.', 'pcio-vis-products' ) . '</p>'
                    . '{licence_keys}'
                    . '<p>{site_name}</p>',
                'mailtype' => self::MAIL_TYPE_SUBSCRIPTION_PURCHASED,
            ] );
        }

        if ( PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_ORDER_CONFIRMATION )
            && PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_DELIVERY_NOTIFICATION )
            && PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_MEMBERSHIP_PROLONGED )
            && PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_SUBSCRIPTION_PURCHASED ) ) {
            update_option( 'pcio_mep_fulfillment_mails_seeded_v2', '1' );
        }
    }

    /**
     * Expose tag chips for the fulfillment mail types.
     * Filter: pcio_me_mail_tags
     */
    public function register_mail_tags( array $tags, string $mailtype ): array {
        if ( self::MAIL_TYPE_ORDER_CONFIRMATION === $mailtype ) {
            return array_merge( $tags, [
                [ 'tag' => '{order_no}',     'label' => __( 'Order number',       'pcio-vis-products' ) ],
                [ 'tag' => '{summary}',      'label' => __( 'Items summary',      'pcio-vis-products' ) ],
                [ 'tag' => '{licence_keys}', 'label' => __( 'Licence keys block (empty if none)', 'pcio-vis-products' ) ],
                [ 'tag' => '{site_name}',    'label' => __( 'Site name',          'pcio-vis-products' ) ],
            ] );
        }
        if ( self::MAIL_TYPE_DELIVERY_NOTIFICATION === $mailtype ) {
            return array_merge( $tags, [
                [ 'tag' => '{order_no}',        'label' => __( 'Order number',     'pcio-vis-products' ) ],
                [ 'tag' => '{delivery_method}', 'label' => __( 'Delivery method',  'pcio-vis-products' ) ],
                [ 'tag' => '{items}',           'label' => __( 'Items list (HTML ul)', 'pcio-vis-products' ) ],
                [ 'tag' => '{customer_block}',  'label' => __( 'Customer details block', 'pcio-vis-products' ) ],
                [ 'tag' => '{site_name}',       'label' => __( 'Site name',        'pcio-vis-products' ) ],
            ] );
        }
        if ( self::MAIL_TYPE_MEMBERSHIP_PROLONGED === $mailtype ) {
            return array_merge( $tags, [
                [ 'tag' => '{name}',          'label' => __( 'Member name',    'pcio-vis-products' ) ],
                [ 'tag' => '{member_number}', 'label' => __( 'Member number',  'pcio-vis-products' ) ],
                [ 'tag' => '{product_name}',  'label' => __( 'Membership name', 'pcio-vis-products' ) ],
                [ 'tag' => '{expire_date}',   'label' => __( 'New expiry date', 'pcio-vis-products' ) ],
                [ 'tag' => '{site_name}',     'label' => __( 'Site name',      'pcio-vis-products' ) ],
            ] );
        }
        if ( self::MAIL_TYPE_SUBSCRIPTION_PURCHASED === $mailtype ) {
            return array_merge( $tags, [
                [ 'tag' => '{name}',          'label' => __( 'Customer name',  'pcio-vis-products' ) ],
                [ 'tag' => '{product_name}',  'label' => __( 'Product name',   'pcio-vis-products' ) ],
                [ 'tag' => '{expire_date}',   'label' => __( 'Expiry date',    'pcio-vis-products' ) ],
                [ 'tag' => '{licence_keys}',  'label' => __( 'Licence keys block (empty if none)', 'pcio-vis-products' ) ],
                [ 'tag' => '{site_name}',     'label' => __( 'Site name',      'pcio-vis-products' ) ],
            ] );
        }
        return $tags;
    }

    /**
     * Reconcile a still-pending order against Stripe and, if paid, confirm it.
     * Idempotent: only the first caller to win pending → paid fulfils.
     *
     * Called from the /shop/accepted return page (the shop reuses the Tickets
     * Stripe keys and therefore reconciles on return instead of via a separate
     * webhook endpoint with its own signing secret).
     *
     * @return bool True when the order is confirmed (now or already).
     */
    public static function reconcile_paid( int $order_id ): bool {
        $order = PCIO_VIS_Products_DB::get_order( $order_id );
        if ( ! $order ) {
            return false;
        }
        if ( in_array( (string) $order['status'], [ 'paid', 'free_confirmed', 'partly_refunded' ], true ) ) {
            return true; // already confirmed
        }
        if ( (string) $order['status'] !== 'pending' ) {
            return false;
        }
        $session_id = (string) ( $order['stripe_session_id'] ?? '' );
        if ( '' === $session_id ) {
            return false;
        }

        $session = PCIO_VIS_Products_Stripe::get_checkout_session( $session_id );
        if ( is_wp_error( $session ) ) {
            return false;
        }
        $paid = ( ( $session['payment_status'] ?? '' ) === 'paid' )
            || ( ( $session['status'] ?? '' ) === 'complete' );
        if ( ! $paid ) {
            return false;
        }

        if ( ! PCIO_VIS_Products_DB::claim_order( $order_id, 'pending', 'paid' ) ) {
            return true; // someone else won the race — already fulfilled
        }

        $payment_intent = is_string( $session['payment_intent'] ?? null )
            ? (string) $session['payment_intent']
            : '';
        PCIO_VIS_Products_DB::update_order(
            $order_id,
            [ 'paid_at' => current_time( 'mysql' ), 'stripe_payment_intent' => $payment_intent ],
            [ '%s', '%s' ]
        );

        do_action( 'pcio_mep_order_confirmed', $order_id );
        return true;
    }

    /**
     * Create subscriptions / link membership for a confirmed order.
     *
     * Data only — the single purchase email is sent by send_receipt() at
     * priority 20 (after core provisioning at 15). Idempotent.
     */
    public function fulfil( int $order_id ): void {
        $order = PCIO_VIS_Products_DB::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Renewal orders extend an existing subscription rather than minting new ones.
        if ( (string) ( $order['kind'] ?? 'shop' ) === 'renewal' ) {
            $this->fulfil_renewal( $order );
            return;
        }

        // Avoid double fulfilment if this fires more than once.
        if ( ! empty( PCIO_VIS_Products_DB::get_subscriptions_for_order( $order_id ) ) ) {
            return;
        }

        $customer = ! empty( $order['customer_id'] )
            ? PCIO_VIS_Products_DB::get_customer( (int) $order['customer_id'] )
            : null;
        $buyer_name  = $customer ? (string) $customer['name'] : '';
        $buyer_email = (string) $order['email'];

        $is_membership_order = false;
        $is_new_membership   = false;
        $membership_created  = false;
        $membership_member   = 0;
        $membership_seen     = false;

        foreach ( PCIO_VIS_Products_DB::get_order_items( $order_id ) as $item ) {
            $product = PCIO_VIS_Products_DB::get_product( (int) $item['product_id'] );
            if ( ! $product || empty( $product['is_subscription'] ) ) {
                continue;
            }
            $qty = max( 1, (int) $item['qty'] );
            for ( $i = 0; $i < $qty; $i++ ) {
                $member_id     = 0;
                $member_number = 0;
                if ( ! empty( $product['is_membership'] ) ) {
                    $ensure    = $this->ensure_member( $buyer_email, $buyer_name );
                    $member_id = (int) $ensure['id'];
                    if ( $member_id > 0 && class_exists( 'PCIO_VIS_DB' ) ) {
                        $member_number = (int) PCIO_VIS_DB::get_member_number_by_id( $member_id );
                        // Business rule: "new" membership = the buyer had no prior member
                        // row, or an existing row that never received a number.
                        if ( ! $membership_seen ) {
                            $is_new_membership = ! empty( $ensure['created'] ) || $member_number <= 0;
                            $membership_created = ! empty( $ensure['created'] );
                        }
                        if ( ! $member_number  ) {
                            $member_number = PCIO_VIS_DB::get_next_free_member_number();
                            PCIO_VIS_DB::set_member_number( $member_id, $member_number );
                        }
                    }
                    $is_membership_order = true;
                    $membership_member   = $member_id;
                    $membership_seen     = true;
                }

                // Determine expiry date from membership rule or product period.
                if ( ! empty( $product['is_membership'] ) ) {
                    $type_map  = [ 1 => 'floating', 2 => 'fixed', 3 => 'fixed_cut' ];
                    $period    = $type_map[ (int) get_option( 'pcio_mep_mem_rule_type', 1 ) ] ?? 'floating';
                } else {
                    $period = (string) ( $product['subscription_period'] ?? 'floating' );
                }
                $expire_date = PCIO_VIS_Products_DB::compute_expire_date( $period );

                $sub_id = PCIO_VIS_Products_DB::create_subscription( [
                    'product_name'   => (string) $product['product_name'],
                    'customer_name'  => $buyer_name,
                    'customer_email' => $buyer_email,
                    'member_id'      => $member_id,
                    'member_number'  => $member_number,
                    'order_id'       => $order_id,
                    'expire_date'    => $expire_date,
                ] );
                if ( $sub_id && $member_number > 0 ) {
                    self::refresh_number_status( $member_number );
                } elseif ( $member_id && $sub_id ) {
                    $this->stamp_member_status( $member_id );
                }
            }
        }

        $member_number = '';
        if ( $membership_member > 0 && class_exists( 'PCIO_VIS_DB' ) ) {
            $member        = PCIO_VIS_DB::get( $membership_member );
            $member_number = $member ? (string) ( $member['member_number'] ?? '' ) : '';
        }

        // Hand the categorisation to send_receipt() (priority 20, same request).
        $this->order_ctx[ $order_id ] = [
            'is_membership'     => $is_membership_order,
            'is_new_membership' => $is_new_membership,
            'member_id'         => $membership_member,
            'member_number'     => $member_number,
        ];

        // Tell the core signup handler (priority 15) that this order created the
        // member row, and claim the welcome mail when this purchase is a new
        // membership so core does not send a second copy.
        if ( $is_membership_order && class_exists( 'PCIO_VIS_Member_Signup_Fulfillment' ) ) {
            PCIO_VIS_Member_Signup_Fulfillment::stash_order_context( $order_id, [
                'member_created' => $membership_created,
                'member_id'      => $membership_member,
            ] );
            if ( $is_new_membership ) {
                PCIO_VIS_Member_Signup_Fulfillment::claim_order_welcome( $order_id );
            }
        }
    }

    /**
     * Notify every user with vis_shop_delivery that a new delivery order is confirmed.
     * Uses the editable shop_delivery_notification template when available.
     */
    private function notify_delivery_manager( array $order ): void {
        // user_can() applies the user_has_cap filter used by the Vis roles system;
        // get_users(['capability'=>...]) is a DB-only query and misses those caps.
        $all_users  = get_users( [ 'fields' => [ 'ID', 'user_email' ], 'number' => -1 ] );
        $recipients = array_filter( $all_users, fn( $u ) => user_can( (int) $u->ID, 'vis_shop_delivery' ) );
        if ( ! $recipients ) {
            return;
        }

        $site        = get_bloginfo( 'name' );
        $customer    = ! empty( $order['customer_id'] )
            ? PCIO_VIS_Products_DB::get_customer( (int) $order['customer_id'] )
            : null;
        $buyer_name  = $customer ? (string) $customer['name'] : '';
        $buyer_email = (string) $order['email'];

        $contact        = PCIO_VIS_Products_Delivery_DB::get_member_contact_by_email( $buyer_email );
        $member_address = $contact ? $contact['address'] : '';
        $member_phone   = $contact ? $contact['phone']   : '';

        $order_items = PCIO_VIS_Products_DB::get_order_items( (int) $order['id'] );

        // Build reusable HTML fragments.
        $items_html = '<ul>';
        foreach ( $order_items as $item ) {
            $items_html .= '<li>' . esc_html( (int) $item['qty'] . ' × ' . (string) $item['product_name'] ) . '</li>';
        }
        $items_html .= '</ul>';

        $customer_block = '';
        if ( '' !== $buyer_name ) {
            $customer_block .= '<p><strong>' . esc_html__( 'Name', 'pcio-vis-products' ) . ':</strong> '
                . esc_html( $buyer_name ) . '</p>';
        }
        $customer_block .= '<p><strong>' . esc_html__( 'Email', 'pcio-vis-products' ) . ':</strong> '
            . esc_html( $buyer_email ) . '</p>';
        if ( '' !== $member_phone ) {
            $customer_block .= '<p><strong>' . esc_html__( 'Phone', 'pcio-vis-products' ) . ':</strong> '
                . esc_html( $member_phone ) . '</p>';
        }
        if ( '' !== $member_address ) {
            $customer_block .= '<p><strong>' . esc_html__( 'Address', 'pcio-vis-products' ) . ':</strong><br>'
                . nl2br( esc_html( $member_address ) ) . '</p>';
        }

        $tpl = class_exists( 'PCIO_VIS_Mails_DB' )
            ? PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_DELIVERY_NOTIFICATION )
            : null;

        if ( $tpl ) {
            $tags = [
                '{order_no}'        => esc_html( (string) $order['order_no'] ),
                '{delivery_method}' => esc_html( (string) $order['delivery_method_name'] ),
                '{items}'           => $items_html,
                '{customer_block}'  => $customer_block,
                '{site_name}'       => esc_html( $site ),
            ];
            $subject = str_replace( array_keys( $tags ), array_values( $tags ), wp_strip_all_tags( (string) $tpl['subject'] ) );
            $body    = str_replace( array_keys( $tags ), array_values( $tags ), (string) $tpl['content'] );
        } else {
            $subject = sprintf(
                /* translators: %s: order number */
                __( 'New delivery order: %s', 'pcio-vis-products' ),
                (string) $order['order_no']
            );
            $lines = [
                '<p>' . sprintf(
                    /* translators: %s: site name */
                    esc_html__( 'A new delivery order has been placed on %s.', 'pcio-vis-products' ),
                    esc_html( $site )
                ) . '</p>',
                '<h3>' . esc_html__( 'Order', 'pcio-vis-products' ) . '</h3>',
                '<p><strong>' . esc_html__( 'Order number', 'pcio-vis-products' ) . ':</strong> '
                    . esc_html( (string) $order['order_no'] ) . '</p>',
                '<p><strong>' . esc_html__( 'Delivery method', 'pcio-vis-products' ) . ':</strong> '
                    . esc_html( (string) $order['delivery_method_name'] ) . '</p>',
                '<h3>' . esc_html__( 'Items', 'pcio-vis-products' ) . '</h3>',
                $items_html,
                '<h3>' . esc_html__( 'Customer', 'pcio-vis-products' ) . '</h3>',
                $customer_block,
                '<p>' . esc_html( $site ) . '</p>',
            ];
            $body = implode( "\n", $lines );
        }

        foreach ( $recipients as $user ) {
            if ( is_email( $user->user_email ) ) {
                self::mail( $user->user_email, $subject, $body );
            }
        }
    }

    /**
     * Extend the subscription referenced by a paid renewal order by one year.
     *
     * Data only — the customer notification is sent by send_receipt() (priority
     * 20) using the membership_prolonged or subscription_purchased template.
     */
    private function fulfil_renewal( array $order ): void {
        $sub_id = (int) ( $order['subscription_id'] ?? 0 );
        if ( $sub_id < 1 ) {
            return;
        }
        PCIO_VIS_Products_DB::renew_subscription( $sub_id, 1 );
        $sub = PCIO_VIS_Products_DB::get_subscription( $sub_id );
        if ( ! $sub ) {
            return;
        }
        $member_number = (int) ( $sub['member_number'] ?? 0 );
        if ( $member_number > 0 ) {
            self::refresh_number_status( $member_number  );
        } elseif ( (int) $sub['member_id'] > 0 ) {
            $this->stamp_member_status( (int) $sub['member_id'] );
        }
    }

    // ── Membership member handling ────────────────────────────────

    /**
     * Find a member by email; create a standard member when none exists.
     * The welcome email is sent centrally by send_receipt(). Returns the member
     * id (0 on failure).
     */
    /**
     * Find or create the member row for a buyer.
     *
     * @return array{id:int,created:bool} created is true only for a freshly inserted row.
     */
    private function ensure_member( string $email, string $name ): array {
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) {
            return [ 'id' => 0, 'created' => false ];
        }

        $existing = PCIO_VIS_DB::find_id_by_email( $email );
        if ( $existing ) {
            return [ 'id' => (int) $existing, 'created' => false ];
        }

        $member_id = PCIO_VIS_DB::create_with_next_number( [
            'name'  => $name !== '' ? $name : $email,
            'email' => $email,
        ] );
        if ( ! $member_id ) {
            return [ 'id' => 0, 'created' => false ];
        }
        $member_id = (int) $member_id;

        // Assign the default "member" Vis role when the roles API is available.
        if ( class_exists( 'PCIO_VIS_Roles_DB' ) && method_exists( 'PCIO_VIS_Roles_DB', 'set_member_role' ) ) {
            PCIO_VIS_Roles_DB::set_member_role( $member_id, 'member', 0 );
        }

        return [ 'id' => $member_id, 'created' => true ];
    }

    /**
     * Recompute and stamp the membership status on every member sharing a
     * membership number (the boat/household group). Public so the cron can call
     * it when a subscription expires.
     */
    public static function refresh_number_status( int $member_number ): void {
        if ( $member_number <= 0 || ! class_exists( 'PCIO_VIS_Meta_DB' ) || ! class_exists( 'PCIO_VIS_DB' ) ) {
            return;
        }
        $latest = PCIO_VIS_Products_DB::coverage_expiry_for_number( $member_number );
        $active = '' !== $latest && strtotime( $latest ) >= strtotime( gmdate( 'Y-m-d' ) );
        $status = $active
            /* translators: %s: expiry date */
            ? sprintf( __( 'Active until %s', 'pcio-vis-products' ), $latest )
            : __( 'Expired', 'pcio-vis-products' );
        foreach ( PCIO_VIS_DB::get_member_ids_by_member_number( $member_number ) as $mid ) {
            $member_id = (int) $mid;
            if ( $member_id <= 0 ) {
                continue;
            }
            PCIO_VIS_Meta_DB::set( $member_id, 'mep_subscription_status', $status );
            PCIO_VIS_Meta_DB::set( $member_id, 'mep_subscription_expires', $latest );
        }
    }

    /**
     * Record the membership status on the member (shown on the member list).
     */
    private function stamp_member_status( int $member_id ): void {
        if ( ! class_exists( 'PCIO_VIS_Meta_DB' ) ) {
            return;
        }
        $subs   = PCIO_VIS_Products_DB::get_subscriptions_for_member( $member_id );
        $latest = '';
        foreach ( $subs as $sub ) {
            if ( $sub['expire_date'] && ( $latest === '' || $sub['expire_date'] > $latest ) ) {
                $latest = (string) $sub['expire_date'];
            }
        }
        $active = $latest !== '' && strtotime( $latest ) >= strtotime( gmdate( 'Y-m-d' ) );
        $status = $active
            /* translators: %s: expiry date */
            ? sprintf( __( 'Active until %s', 'pcio-vis-products' ), $latest )
            : __( 'Expired', 'pcio-vis-products' );
        PCIO_VIS_Meta_DB::set( $member_id, 'mep_subscription_status', $status );
        PCIO_VIS_Meta_DB::set( $member_id, 'mep_subscription_expires', $latest );
    }

    // ── Emails ────────────────────────────────────────────────────

    /**
     * Send exactly one purchase email for a confirmed order (priority 20) and
     * cache the rendered body so the /shop/accepted confirmation matches it.
     *
     * Runs after fulfil() (priority 10, creates subscriptions) and core member
     * provisioning (priority 15). Guarded so a re-confirmation never re-sends.
     */
    public function send_receipt( int $order_id ): void {
        if ( get_transient( 'pcio_mep_emailed_' . $order_id ) ) {
            return;
        }
        $order = PCIO_VIS_Products_DB::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $info = $this->categorize_order( $order );
        $mail = $this->render_receipt( (string) $info['category'], $order, $info );

        $to = sanitize_email( (string) $order['email'] );
        if ( '' !== $mail['content'] && is_email( $to ) ) {
            self::mail( $to, $mail['subject'], $mail['content'] );
            set_transient( 'pcio_mep_emailed_' . $order_id, 1, DAY_IN_SECONDS );
            // Cache the rendered body for the /shop/accepted confirmation page.
            set_transient(
                'pcio_mep_receipt_' . $order_id,
                wp_json_encode( [
                    'subject'  => $mail['subject'],
                    'content'  => $mail['content'],
                    'category' => $info['category'],
                ] ),
                DAY_IN_SECONDS
            );
        }

        // Delivery managers are notified only for orders with a delivery method.
        if ( ! empty( $order['delivery_method_id'] ) ) {
            $this->notify_delivery_manager( $order );
        }

        unset( $this->order_ctx[ $order_id ] );
    }

    /**
     * Determine which purchase category a confirmed order falls into.
     *
     * @return array{category:string,subs:array,member_id:int,member_number:string}
     */
    private function categorize_order( array $order ): array {
        $order_id = (int) $order['id'];

        if ( (string) ( $order['kind'] ?? 'shop' ) === 'renewal' ) {
            $sub  = PCIO_VIS_Products_DB::get_subscription( (int) ( $order['subscription_id'] ?? 0 ) );
            $subs = $sub ? [ $sub ] : [];
            $is_membership = $sub
                && ( (int) ( $sub['member_number'] ?? 0 ) > 0 || (int) ( $sub['member_id'] ?? 0 ) > 0 );
            return [
                'category'      => $is_membership ? 'membership_prolonged' : 'subscription',
                'subs'          => $subs,
                'member_id'     => (int) ( $sub['member_id'] ?? 0 ),
                'member_number' => $this->member_number_for( (int) ( $sub['member_id'] ?? 0 ) ),
            ];
        }

        $subs = PCIO_VIS_Products_DB::get_subscriptions_for_order( $order_id );
        $ctx  = $this->order_ctx[ $order_id ] ?? null;

        if ( $ctx && ! empty( $ctx['is_membership'] ) ) {
            return [
                'category'      => ! empty( $ctx['is_new_membership'] ) ? 'membership_new' : 'membership_prolonged',
                'subs'          => $subs,
                'member_id'     => (int) ( $ctx['member_id'] ?? 0 ),
                'member_number' => (string) ( $ctx['member_number'] ?? '' ),
            ];
        }

        // Fallback when context is unavailable (e.g. re-fulfilment): infer from subs.
        $membership_member = 0;
        foreach ( $subs as $sub ) {
            if ( (int) ( $sub['member_number'] ?? 0 ) > 0 ) {
                $membership_member = (int) ( $sub['member_id'] ?? 0 );
                break;
            }
        }
        if ( $membership_member > 0 ) {
            return [
                'category'      => 'membership_prolonged',
                'subs'          => $subs,
                'member_id'     => $membership_member,
                'member_number' => $this->member_number_for( $membership_member ),
            ];
        }
        if ( ! empty( $subs ) ) {
            return [ 'category' => 'subscription', 'subs' => $subs, 'member_id' => 0, 'member_number' => '' ];
        }
        return [ 'category' => 'plain', 'subs' => [], 'member_id' => 0, 'member_number' => '' ];
    }

    /**
     * Render the subject + HTML body for a purchase category.
     *
     * @return array{subject:string,content:string}
     */
    private function render_receipt( string $category, array $order, array $info ): array {
        switch ( $category ) {
            case 'membership_new':
                return $this->render_membership_welcome( $order, $info );
            case 'membership_prolonged':
                return $this->render_membership_prolonged( $order, $info );
            case 'subscription':
                return $this->render_subscription_purchased( $order, $info );
            case 'plain':
            default:
                return $this->render_order_confirmation( $order, $info );
        }
    }

    /**
     * New-membership welcome — rendered from the core member_welcome template so
     * a single editable source drives both the signup and shop paths.
     *
     * @return array{subject:string,content:string}
     */
    private function render_membership_welcome( array $order, array $info ): array {
        $member_id = (int) $info['member_id'];
        if ( $member_id < 1 || ! class_exists( 'PCIO_VIS_Member_Signup_Fulfillment' ) ) {
            return $this->render_order_confirmation( $order, $info );
        }
        $stash     = PCIO_VIS_Member_Signup_Fulfillment::take_order_provision( (int) $order['id'] );
        $buyer     = $this->buyer_name( $order );
        if ( is_array( $stash ) && isset( $stash['provision'] ) ) {
            $provision = (array) $stash['provision'];
            $payload   = isset( $stash['payload'] ) ? (array) $stash['payload'] : [ 'name' => $buyer, 'email' => (string) $order['email'] ];
        } else {
            // Shop-direct membership purchase (no signup form): provision a WP
            // account now so the welcome email carries a working set-password link.
            $provision = class_exists( 'PCIO_VIS_Caps' )
                ? PCIO_VIS_Caps::provision_member_wp_account( $member_id, (string) $order['email'], $buyer )
                : [ 'wp_user_id' => 0, 'is_new' => false ];
            $payload = [ 'name' => $buyer, 'email' => (string) $order['email'] ];
        }

        $mail = PCIO_VIS_Member_Signup_Fulfillment::render_welcome( $member_id, (int) $order['id'], $payload, $provision );
        if ( '' === $mail['content'] ) {
            return $this->render_order_confirmation( $order, $info );
        }
        return $mail;
    }

    /**
     * Membership-prolonged notice (renewal or a repeat membership purchase).
     *
     * @return array{subject:string,content:string}
     */
    private function render_membership_prolonged( array $order, array $info ): array {
        $site         = get_bloginfo( 'name' );
        $subs         = (array) $info['subs'];
        $primary      = $subs[0] ?? null;
        $product_name = $primary ? (string) $primary['product_name'] : '';
        $expire_date  = $primary ? (string) $primary['expire_date'] : '';
        $name         = $this->buyer_name( $order );
        $member_no    = (string) ( $info['member_number'] ?? '' );

        $tpl = class_exists( 'PCIO_VIS_Mails_DB' )
            ? PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_MEMBERSHIP_PROLONGED )
            : null;

        if ( $tpl ) {
            $tags = [
                '{name}'          => esc_html( $name ),
                '{member_number}' => esc_html( $member_no ),
                '{product_name}'  => esc_html( $product_name ),
                '{expire_date}'   => esc_html( $expire_date ),
                '{site_name}'     => esc_html( $site ),
            ];
            return [
                'subject' => str_replace( array_keys( $tags ), array_values( $tags ), wp_strip_all_tags( (string) $tpl['subject'] ) ),
                'content' => str_replace( array_keys( $tags ), array_values( $tags ), (string) $tpl['content'] ),
            ];
        }

        $subject = sprintf(
            /* translators: %s: site name */
            __( 'Your membership at %s has been renewed', 'pcio-vis-products' ),
            $site
        );
        $content = '<p>' . sprintf( /* translators: %s: member name */ esc_html__( 'Hi %s,', 'pcio-vis-products' ), esc_html( $name ) ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: product name, 2: expiry date */
                esc_html__( 'Your membership %1$s has been renewed and is now valid until %2$s.', 'pcio-vis-products' ),
                '<strong>' . esc_html( $product_name ) . '</strong>',
                '<strong>' . esc_html( $expire_date ) . '</strong>'
            ) . '</p>'
            . '<p>' . esc_html( $site ) . '</p>';
        return [ 'subject' => $subject, 'content' => $content ];
    }

    /**
     * Non-membership subscription purchase (and its renewals) with licence keys.
     *
     * @return array{subject:string,content:string}
     */
    private function render_subscription_purchased( array $order, array $info ): array {
        $site         = get_bloginfo( 'name' );
        $subs         = (array) $info['subs'];
        $primary      = $subs[0] ?? null;
        $product_name = $primary ? (string) $primary['product_name'] : '';
        $expire_date  = $primary ? (string) $primary['expire_date'] : '';
        $name         = $this->buyer_name( $order );
        $licence_html = $this->build_licence_html( $subs );

        $tpl = class_exists( 'PCIO_VIS_Mails_DB' )
            ? PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_SUBSCRIPTION_PURCHASED )
            : null;

        if ( $tpl ) {
            $tags = [
                '{name}'         => esc_html( $name ),
                '{product_name}' => esc_html( $product_name ),
                '{expire_date}'  => esc_html( $expire_date ),
                '{licence_keys}' => $licence_html,
                '{site_name}'    => esc_html( $site ),
            ];
            return [
                'subject' => str_replace( array_keys( $tags ), array_values( $tags ), wp_strip_all_tags( (string) $tpl['subject'] ) ),
                'content' => str_replace( array_keys( $tags ), array_values( $tags ), (string) $tpl['content'] ),
            ];
        }

        $subject = sprintf(
            /* translators: %s: product name */
            __( 'Your %s subscription', 'pcio-vis-products' ),
            $product_name
        );
        $content = '<p>' . sprintf( /* translators: %s: customer name */ esc_html__( 'Hi %s,', 'pcio-vis-products' ), esc_html( $name ) ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: product name, 2: expiry date */
                esc_html__( 'Thank you for subscribing to %1$s. It is valid until %2$s.', 'pcio-vis-products' ),
                '<strong>' . esc_html( $product_name ) . '</strong>',
                '<strong>' . esc_html( $expire_date ) . '</strong>'
            ) . '</p>'
            . $licence_html
            . '<p>' . esc_html( $site ) . '</p>';
        return [ 'subject' => $subject, 'content' => $content ];
    }

    /**
     * Plain (non-subscription) order confirmation with any licence keys.
     * Uses the editable shop_order_confirmation template when available.
     *
     * @return array{subject:string,content:string}
     */
    private function render_order_confirmation( array $order, array $info ): array {
        $site         = get_bloginfo( 'name' );
        $subs         = isset( $info['subs'] )
            ? (array) $info['subs']
            : PCIO_VIS_Products_DB::get_subscriptions_for_order( (int) $order['id'] );
        $licence_html = $this->build_licence_html( $subs );

        $tpl = class_exists( 'PCIO_VIS_Mails_DB' )
            ? PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_ORDER_CONFIRMATION )
            : null;

        if ( $tpl ) {
            $tags = [
                '{order_no}'     => esc_html( (string) $order['order_no'] ),
                '{summary}'      => esc_html( (string) $order['summary'] ),
                '{licence_keys}' => $licence_html,
                '{site_name}'    => esc_html( $site ),
            ];
            return [
                'subject' => str_replace( array_keys( $tags ), array_values( $tags ), wp_strip_all_tags( (string) $tpl['subject'] ) ),
                'content' => str_replace( array_keys( $tags ), array_values( $tags ), (string) $tpl['content'] ),
            ];
        }

        $subject = sprintf(
            /* translators: %s: order number */
            __( 'Your order %s', 'pcio-vis-products' ),
            (string) $order['order_no']
        );
        $content = '<p>' . esc_html__( 'Thank you for your purchase!', 'pcio-vis-products' ) . '</p>'
            . '<p>' . sprintf(
                /* translators: %s: order number */
                esc_html__( 'Order number: %s', 'pcio-vis-products' ),
                '<strong>' . esc_html( (string) $order['order_no'] ) . '</strong>'
            ) . '</p>'
            . '<p>' . esc_html( (string) $order['summary'] ) . '</p>'
            . $licence_html
            . '<p>' . esc_html( $site ) . '</p>';
        return [ 'subject' => $subject, 'content' => $content ];
    }

    /** Build the licence-keys HTML block (empty string when the order has none). */
    private function build_licence_html( array $subs ): string {
        if ( ! $subs ) {
            return '';
        }
        $html = '<h3>' . esc_html__( 'Your licence keys', 'pcio-vis-products' ) . '</h3>'
            . '<p>' . esc_html__( 'Enter the licence key in your purchased plugin to activate it:', 'pcio-vis-products' ) . '</p>';
        foreach ( $subs as $sub ) {
            if ( empty( $sub['license_token'] ) ) {
                continue;
            }
            $html .= '<p><strong>' . esc_html( (string) $sub['product_name'] ) . '</strong><br>'
                . '<code>' . esc_html( (string) $sub['license_token'] ) . '</code><br>'
                . sprintf(
                    /* translators: %s: expiry date */
                    esc_html__( 'Valid until %s', 'pcio-vis-products' ),
                    esc_html( (string) $sub['expire_date'] )
                ) . '</p>';
        }
        return $html;
    }

    /** Resolve the buyer's display name from the order's customer record. */
    private function buyer_name( array $order ): string {
        if ( empty( $order['customer_id'] ) ) {
            return '';
        }
        $customer = PCIO_VIS_Products_DB::get_customer( (int) $order['customer_id'] );
        return $customer ? (string) $customer['name'] : '';
    }

    /** Look up a member's member number (empty when unavailable). */
    private function member_number_for( int $member_id ): string {
        if ( $member_id < 1 || ! class_exists( 'PCIO_VIS_DB' ) ) {
            return '';
        }
        $member = PCIO_VIS_DB::get( $member_id );
        return $member ? (string) ( $member['member_number'] ?? '' ) : '';
    }

    /**
     * Deliver an HTML email via the core mailer filter, falling back to
     * wp_mail. Exposed statically so other components (e.g. the cron) can reuse
     * the same delivery path.
     */
    public static function mail( string $to, string $subject, string $content ): void {
        $mail       = [ 'subject' => $subject, 'content' => $content, 'attachments' => [] ];
        $recipients = [ [ 'email' => $to ] ];

        $result = apply_filters( 'pcio_me_mailer_send', null, $mail, $recipients, [] );

        if ( null === $result || is_wp_error( $result ) ) {
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . PCIO_VIS_Products_Plugin::from_name() . ' <' . PCIO_VIS_Products_Plugin::from_email() . '>',
            ];
            wp_mail( $to, $subject, $content, $headers );
        }
    }
}
