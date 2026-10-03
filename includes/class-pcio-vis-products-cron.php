<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Scheduled subscription maintenance for the Products extension.
 *
 * Runs once a day (via the core Vis sync scheduler) and:
 *   • emails a renewal reminder ~1 month before a subscription expires
 *     (a renewal link extends the expiry by one year — handled in checkout);
 *   • once a subscription has expired, emails an "ended" notice, removes the
 *     subscription row and marks any linked member as expired.
 *
 * The job is registered as a row in the core sync table (cron_name
 * `shop_subscriptions`) so it appears in the Vis sync admin and can be run on
 * demand. The actual work runs in run().
 */
class PCIO_VIS_Products_Cron {

    const CRON_NAME        = 'shop_subscriptions';
    const MAIL_TYPE_REMINDER = 'subscription_reminder';
    const MAIL_TYPE_ENDED    = 'subscription_ended';

    /** Days before expiry that a reminder is sent. */
    const REMINDER_DAYS = 30;

    public function register(): void {
        add_action( 'pcio_me_sync_register_jobs', [ $this, 'seed_job' ] );
        add_filter( 'pcio_me_sync_callables',     [ $this, 'register_callable' ] );
        add_filter( 'pcio_me_mail_tags',           [ $this, 'register_mail_tags' ],   10, 2 );
        add_filter( 'pcio_me_signup_welcome_vars', [ $this, 'welcome_vars' ],          10, 4 );
        // Seed mail templates lazily on admin requests (covers existing installs).
        add_action( 'admin_init',                  [ __CLASS__, 'maybe_seed_mails' ] );
    }

    /**
     * Register this extension's callable on the sync allow-list so the core
     * sync executor is permitted to run it.
     * Filter: pcio_me_sync_callables
     *
     * @param array<string,string> $callables Map of callable => label.
     * @return array<string,string>
     */
    public function register_callable( array $callables ): array {
        $callables['PCIO_VIS_Products_Cron::run'] = __( 'Shop subscriptions', 'pcio-vis-products' );
        return $callables;
    }

    /**
     * Seed the sync-table row if it does not exist yet (idempotent).
     */
    public function seed_job(): void {
        if ( ! class_exists( 'PCIO_VIS_Sync_DB' ) ) {
            return;
        }
        // Always seed mail templates so they appear even on pre-existing installs.
        self::seed_mails();
        if ( PCIO_VIS_Sync_DB::get_by_cron_name( self::CRON_NAME ) ) {
            return;
        }
        PCIO_VIS_Sync_DB::create( [
            'name'         => __( 'Shop subscriptions', 'pcio-vis-products' ),
            'description'  => __( 'Sends renewal reminders and removes expired subscriptions.', 'pcio-vis-products' ),
            'cron_name'    => self::CRON_NAME,
            'php_function' => 'PCIO_VIS_Products_Cron::run',
            'sync_url'     => '',
            'frequence'    => 24,
        ] );
    }

    public static function activate(): void {
        // Scheduling is handled by the core sync scheduler once the row exists;
        // nothing to do here beyond letting seed_job() run on the next init.
    }

    public static function deactivate(): void {
        if ( class_exists( 'PCIO_VIS_Sync' ) ) {
            PCIO_VIS_Sync::unschedule( self::CRON_NAME );
        }
    }

    /**
     * One-time admin_init hook: seed mails if the flag option is missing.
     * Runs only until both templates are confirmed present, then sets the flag.
     */
    public static function maybe_seed_mails(): void {
        if ( get_option( 'pcio_mep_mails_seeded' ) ) {
            return;
        }
        self::seed_mails();
        // Flag is set inside seed_mails() after both rows exist.
    }

    /**
     * Create the two system mail template rows if they do not exist yet.
     * Called from seed_job() and safe to run repeatedly.
     */
    public static function seed_mails(): void {
        if ( ! class_exists( 'PCIO_VIS_Mails_DB' ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        PCIO_VIS_Mails_DB::install();

        global $wpdb;
        $table = PCIO_VIS_Mails_DB::table();

        // dbDelta sometimes fails silently on ADD COLUMN — apply the migration directly.
        $col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'mailtype' ) );
        if ( ! $col ) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `mailtype` varchar(50) NULL DEFAULT NULL', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY `mailtype` (`mailtype`)', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_REMINDER ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'Your {product_name} subscription is about to expire', 'pcio-vis-products' ),
                'content'  => '<p>' . __( 'Hi {name},', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Your subscription to <strong>{product_name}</strong> expires on <strong>{expire_date}</strong>.', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Renew now to keep it active for another year: {renew_url}', 'pcio-vis-products' ) . '</p>'
                    . '<p>{site_name}</p>',
                'mailtype' => self::MAIL_TYPE_REMINDER,
            ] );
        }

        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_ENDED ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'Your {product_name} subscription has ended', 'pcio-vis-products' ),
                'content'  => '<p>' . __( 'Hi {name},', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'Thank you for the time with <strong>{product_name}</strong>. Your subscription has now ended.', 'pcio-vis-products' ) . '</p>'
                    . '<p>' . __( 'You are welcome to subscribe again any time at {site_name}.', 'pcio-vis-products' ) . '</p>',
                'mailtype' => self::MAIL_TYPE_ENDED,
            ] );
        }

        // Both templates confirmed present — skip this check on future admin loads.
        if ( PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_REMINDER )
            && PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_ENDED ) ) {
            update_option( 'pcio_mep_mails_seeded', '1' );
        }
    }

    /**
     * Expose available tag tokens per mailtype for the /vis/mails/ edit dialog.
     * Filter: pcio_me_mail_tags
     *
     * @param array  $tags     Existing tag descriptors from earlier filter hooks.
     * @param string $mailtype The mailtype being fetched.
     * @return array
     */
    public function register_mail_tags( array $tags, string $mailtype ): array {
        $common = [
            [ 'tag' => '{name}',         'label' => __( 'Customer name',   'pcio-vis-products' ) ],
            [ 'tag' => '{product_name}', 'label' => __( 'Product name',    'pcio-vis-products' ) ],
            [ 'tag' => '{site_name}',    'label' => __( 'Site name',       'pcio-vis-products' ) ],
            [ 'tag' => '{member_no}',    'label' => __( 'Member number (empty if not linked)', 'pcio-vis-products' ) ],
        ];

        if ( self::MAIL_TYPE_REMINDER === $mailtype ) {
            return array_merge( $tags, $common, [
                [ 'tag' => '{expire_date}', 'label' => __( 'Expiry date',     'pcio-vis-products' ) ],
                [ 'tag' => '{renew_url}',   'label' => __( 'Renewal link',    'pcio-vis-products' ) ],
            ] );
        }

        if ( self::MAIL_TYPE_ENDED === $mailtype ) {
            return array_merge( $tags, $common );
        }

        if ( class_exists( 'PCIO_VIS_Member_Signup_Fulfillment' )
            && PCIO_VIS_Member_Signup_Fulfillment::MAIL_TYPE_WELCOME === $mailtype ) {
            return array_merge( $tags, [
                [ 'tag' => '{subscription_name}', 'label' => __( 'Subscription name', 'pcio-vis-products' ) ],
                [ 'tag' => '{expire_date}',        'label' => __( 'Subscription expiry date', 'pcio-vis-products' ) ],
            ] );
        }

        return $tags;
    }

    /**
     * Inject subscription-specific tag values into the member welcome email.
     * Filter: pcio_me_signup_welcome_vars
     *
     * @param array    $vars      Existing tag→value map.
     * @param int      $member_id Member row ID.
     * @param int|null $order_id  Products order ID; null on direct signup.
     * @param array    $payload   Sanitized form data.
     * @return array
     */
    public function welcome_vars( array $vars, int $member_id, ?int $order_id, array $payload ): array {
        if ( ! $order_id ) {
            return $vars;
        }
        $subs = PCIO_VIS_Products_DB::get_subscriptions_for_order( $order_id );
        foreach ( $subs as $sub ) {
            if ( ! empty( $sub['status'] ) && 'active' === $sub['status'] ) {
                $vars['{subscription_name}'] = (string) ( $sub['product_name'] ?? '' );
                $vars['{expire_date}']       = (string) ( $sub['expire_date']  ?? '' );
                break;
            }
        }
        return $vars;
    }

    /**
     * Daily maintenance entry point (called by the sync executor).
     */
    public static function run(): void {
        self::send_reminders();
        self::purge_expired();
    }

    // ── Test-mode helpers ─────────────────────────────────────────

    /** True when the admin has enabled test mode via the sync admin page. */
    public static function is_test_mode(): bool {
        return (bool) get_option( 'pcio_mep_cron_test_mode', false );
    }

    /** In test mode, redirect all outgoing cron email to the site admin. */
    private static function resolve_email( string $email ): string {
        return self::is_test_mode() ? (string) get_option( 'admin_email' ) : $email;
    }

    /** Prefix subject and append original recipient so the admin can identify the message. */
    private static function test_subject( string $subject, string $original_email ): string {
        return self::is_test_mode()
            ? '[TEST → ' . $original_email . '] ' . $subject
            : $subject;
    }

    // ── Reminders ─────────────────────────────────────────────────

    private static function send_reminders(): void {
        $subs = PCIO_VIS_Products_DB::subscriptions_expiring_within( self::REMINDER_DAYS );
        foreach ( $subs as $sub ) {
            // Every member sharing the membership number is notified, not just the buyer.
            $sent = [];
            foreach ( self::subscription_recipients( $sub ) as $rcpt ) {
                $email = self::resolve_email( $rcpt['email'] );
                $key   = strtolower( $email );
                if ( ! is_email( $email ) || isset( $sent[ $key ] ) ) {
                    continue;
                }
                $sent[ $key ] = true;
                self::email_reminder( $sub, $email, $rcpt['email'], $rcpt );
            }
            // Mark as reminded regardless, so we don't email again on the next run.
            PCIO_VIS_Products_DB::update_subscription(
                (int) $sub['id'],
                [ 'reminder_sent_at' => current_time( 'mysql' ) ],
                [ '%s' ]
            );
        }
    }

    /**
     * @param array<string,string> $rcpt Recipient ['email','name','member_no'] (empty = use subscription buyer).
     */
    private static function email_reminder( array $sub, string $email, string $original_email = '', array $rcpt = [] ): void {
        $site      = get_bloginfo( 'name' );
        $renew_url = add_query_arg( 'token', rawurlencode( (string) $sub['verify_token'] ), home_url( 'shop/renew/' ) );
        $greet     = $rcpt['name'] ?? (string) $sub['customer_name'];

        $raw_subject = sprintf(
            /* translators: %s: product name */
            __( 'Your %s subscription is about to expire', 'pcio-vis-products' ),
            (string) $sub['product_name']
        );
        $raw_content = implode( "\n", [
            '<p>' . sprintf(
                /* translators: %s: customer name */
                esc_html__( 'Hi %s,', 'pcio-vis-products' ),
                esc_html( $greet )
            ) . '</p>',
            '<p>' . sprintf(
                /* translators: 1: product name, 2: expiry date */
                esc_html__( 'Your subscription to %1$s expires on %2$s.', 'pcio-vis-products' ),
                '<strong>' . esc_html( (string) $sub['product_name'] ) . '</strong>',
                '<strong>' . esc_html( (string) $sub['expire_date'] ) . '</strong>'
            ) . '</p>',
            '<p>' . sprintf(
                /* translators: %s: renewal link */
                esc_html__( 'Renew now to keep it active for another year: %s', 'pcio-vis-products' ),
                '<a href="' . esc_url( $renew_url ) . '">' . esc_html__( 'Renew subscription', 'pcio-vis-products' ) . '</a>'
            ) . '</p>',
            '<p>' . esc_html( $site ) . '</p>',
        ] );

        // Use the editable template from me_mails when available.
        $tpl = class_exists( 'PCIO_VIS_Mails_DB' )
            ? PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_REMINDER )
            : null;

        if ( $tpl ) {
            $tags    = self::build_tag_map( $sub, $renew_url, $rcpt );
            $subject = self::test_subject(
                self::apply_tags( (string) $tpl['subject'], $tags, true ),
                $original_email ?: $email
            );
            $content = self::apply_tags( (string) $tpl['content'], $tags );
        } else {
            $subject = self::test_subject( $raw_subject, $original_email ?: $email );
            $content = $raw_content;
        }

        PCIO_VIS_Products_Fulfillment::mail( $email, $subject, $content );
    }

    // ── Expiry ────────────────────────────────────────────────────

    private static function purge_expired(): void {
        $subs = PCIO_VIS_Products_DB::subscriptions_expired();
        foreach ( $subs as $sub ) {
            // Notify every member sharing the membership number, not just the buyer.
            $sent = [];
            foreach ( self::subscription_recipients( $sub ) as $rcpt ) {
                $email = self::resolve_email( $rcpt['email'] );
                $key   = strtolower( $email );
                if ( ! is_email( $email ) || isset( $sent[ $key ] ) ) {
                    continue;
                }
                $sent[ $key ] = true;
                self::email_ended( $sub, $email, $rcpt['email'], $rcpt );
            }

            // Refresh coverage for the whole membership-number group (or the single
            // linked member) so lapsed members show as expired.
            $number = (int) ( $sub['member_number'] ?? 0 );
            if ( $number > 0 && class_exists( 'PCIO_VIS_Products_Fulfillment' ) ) {
                PCIO_VIS_Products_Fulfillment::refresh_number_status( $number );
            } elseif ( (int) $sub['member_id'] > 0 && class_exists( 'PCIO_VIS_Meta_DB' ) ) {
                PCIO_VIS_Meta_DB::set( (int) $sub['member_id'], 'mep_subscription_status', __( 'Expired', 'pcio-vis-products' ) );
            }

            // Keep the row (needed to see lapsed members); just mark it expired so
            // it is processed only once and never deleted.
            PCIO_VIS_Products_DB::update_subscription( (int) $sub['id'], [ 'status' => 'expired' ], [ '%s' ] );
        }
    }

    /**
     * @param array<string,string> $rcpt Recipient ['email','name','member_no'] (empty = use subscription buyer).
     */
    private static function email_ended( array $sub, string $email, string $original_email = '', array $rcpt = [] ): void {
        $site = get_bloginfo( 'name' );
        $home = home_url( '/' );
        $greet = $rcpt['name'] ?? (string) $sub['customer_name'];

        $raw_subject = sprintf(
            /* translators: %s: product name */
            __( 'Your %s subscription has ended', 'pcio-vis-products' ),
            (string) $sub['product_name']
        );
        $raw_content = implode( "\n", [
            '<p>' . sprintf(
                /* translators: %s: customer name */
                esc_html__( 'Hi %s,', 'pcio-vis-products' ),
                esc_html( $greet )
            ) . '</p>',
            '<p>' . sprintf(
                /* translators: %s: product name */
                esc_html__( 'Thank you for the time with %s. Your subscription has now ended.', 'pcio-vis-products' ),
                '<strong>' . esc_html( (string) $sub['product_name'] ) . '</strong>'
            ) . '</p>',
            '<p>' . sprintf(
                /* translators: %s: website link */
                esc_html__( 'You are welcome to subscribe again any time here: %s', 'pcio-vis-products' ),
                '<a href="' . esc_url( $home ) . '">' . esc_html( $site ) . '</a>'
            ) . '</p>',
            '<p>' . esc_html( $site ) . '</p>',
        ] );

        // Use the editable template from me_mails when available.
        $tpl = class_exists( 'PCIO_VIS_Mails_DB' )
            ? PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_ENDED )
            : null;

        if ( $tpl ) {
            $tags    = self::build_tag_map( $sub, '', $rcpt );
            $subject = self::test_subject(
                self::apply_tags( (string) $tpl['subject'], $tags, true ),
                $original_email ?: $email
            );
            $content = self::apply_tags( (string) $tpl['content'], $tags );
        } else {
            $subject = self::test_subject( $raw_subject, $original_email ?: $email );
            $content = $raw_content;
        }

        PCIO_VIS_Products_Fulfillment::mail( $email, $subject, $content );
    }

    // ── Recipient resolution ───────────────────────────────────────

    /**
     * Everyone who should receive a subscription notice: all members sharing the
     * subscription's membership number (so crew are notified too), falling back
     * to the subscription's own buyer when there is no shared membership number.
     *
     * @return array<int,array{email:string,name:string,member_no:string}>
     */
    private static function subscription_recipients( array $sub ): array {
        $out    = [];
        $seen   = [];
        $number = (int) ( $sub['member_number'] ?? 0 );

        if ( $number > 0 && class_exists( 'PCIO_VIS_DB' ) ) {
            foreach ( PCIO_VIS_DB::get_member_ids_by_member_number( $number ) as $mid ) {
                $m = PCIO_VIS_DB::get( (int) $mid );
                if ( ! $m ) {
                    continue;
                }
                $email = sanitize_email( (string) ( $m['email'] ?? '' ) );
                $key   = strtolower( $email );
                if ( ! is_email( $email ) || isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $out[] = [
                    'email'     => $email,
                    'name'      => (string) ( $m['name'] ?? '' ),
                    'member_no' => (string) ( $m['member_number'] ?? '' ),
                ];
            }
        }

        if ( ! $out ) {
            $email = sanitize_email( (string) ( $sub['customer_email'] ?? '' ) );
            if ( is_email( $email ) ) {
                $out[] = [
                    'email'     => $email,
                    'name'      => (string) ( $sub['customer_name'] ?? '' ),
                    'member_no' => '',
                ];
            }
        }

        return $out;
    }

    // ── Tag helpers ───────────────────────────────────────────────────

    /**
     * Build the {tag} => value map for a subscription row.
     *
     * @param array  $sub       Subscription row from the DB.
     * @param string $renew_url HTML anchor, or '' when not applicable.
     * @param array<string,string> $rcpt Recipient override for {name}/{member_no} (crew personalisation).
     * @return array<string,string>
     */
    private static function build_tag_map( array $sub, string $renew_url, array $rcpt = [] ): array {
        $member_no = '';
        $member_id = (int) ( $sub['member_id'] ?? 0 );
        if ( $member_id > 0 && class_exists( 'PCIO_VIS_DB' ) ) {
            $m         = PCIO_VIS_DB::get( $member_id );
            $member_no = $m ? (string) ( $m['member_number'] ?? '' ) : '';
        }

        // Personalise for the actual recipient (each crew member) when provided.
        $name = $rcpt['name'] ?? (string) ( $sub['customer_name'] ?? '' );
        if ( ! empty( $rcpt['member_no'] ) ) {
            $member_no = (string) $rcpt['member_no'];
        }

        $renew_link = '';
        if ( '' !== $renew_url ) {
            $renew_link = '<a href="' . esc_url( $renew_url ) . '">' . esc_html__( 'Renew subscription', 'pcio-vis-products' ) . '</a>';
        }

        return [
            '{name}'         => $name,
            '{product_name}' => (string) ( $sub['product_name']  ?? '' ),
            '{expire_date}'  => (string) ( $sub['expire_date']   ?? '' ),
            '{renew_url}'    => $renew_link,
            '{site_name}'    => get_bloginfo( 'name' ),
            '{member_no}'    => $member_no,
        ];
    }

    /**
     * Replace {tags} in $text with their values.
     * When $plain is true the tag values are stripped of HTML (for subject lines).
     */
    private static function apply_tags( string $text, array $tags, bool $plain = false ): string {
        if ( $plain ) {
            $tags = array_map( 'wp_strip_all_tags', $tags );
        }
        return str_replace( array_keys( $tags ), array_values( $tags ), $text );
    }
}
