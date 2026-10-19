<?php
/*
Plugin Name:  PCIO VIS Products
Plugin URI:   https://www.pcio.dk/
Description:  Adds a digital shop to PCIO VIS Member Event: products and license-key subscriptions, a public [pcio_me_shop] storefront with Stripe checkout, a Shop Orders list in Finance, automatic membership subscriptions on the member list, and a renewal/expiry reminder cron. Reuses the Tickets plugin's Stripe configuration.
Version:      1.4.0
Author:       PCIO
Author URI:   https://www.pcio.dk
Requires at least: 6.2
Requires PHP: 8.1
Requires Plugins: pcio-vis-member-event, pcio-vis-tickets
License:      GPL-2.0-or-later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  pcio-vis-products
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PCIO_VIS_PRODUCTS_FILE', __FILE__ );
define( 'PCIO_VIS_PRODUCTS_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PCIO_VIS_PRODUCTS_URL',  plugin_dir_url( __FILE__ ) );
define( 'PCIO_VIS_PRODUCTS_VERSION', '1.4.0' );

require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-db.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-delivery-db.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-stripe.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-fulfillment.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-rest.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-delivery-rest.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-admin.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-delivery-admin.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-frontend.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-integration.php';
require_once PCIO_VIS_PRODUCTS_DIR . 'includes/class-pcio-vis-products-cron.php';

class PCIO_VIS_Products_Plugin {

    /**
     * Custom capabilities and the WP roles they are granted to on activation.
     */
    const CAP_ROLE_MAP = [
        'vis_shop_manage'  => [ 'editor', 'administrator' ],
        'vis_shop_refund'  => [ 'editor', 'administrator' ],
        'vis_shop_delivery'=> [ 'editor', 'administrator' ],
    ];

    /** Bump whenever CAP_ROLE_MAP gains a new entry so existing installs self-heal. */
    const CAPS_VERSION = '1.0.0';

    /** Bump whenever a new rewrite rule is added so existing installs flush once. */
    const REWRITES_VERSION = '1.0.0';

    public static function register(): void {
        add_action( 'admin_init',    [ 'PCIO_VIS_Products_DB', 'install' ] );
        add_action( 'admin_init',    [ __CLASS__, 'maybe_grant_caps' ] );
        add_action( 'init',          [ __CLASS__, 'maybe_flush_rewrites' ], 99 );
        add_action( 'init',          [ __CLASS__, 'maybe_sync_rewrites' ], 99 );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest'   ] );
        add_filter( 'pcio_me_history_tables', [ 'PCIO_VIS_Products_DB', 'register_history_tables' ] );

        ( new PCIO_VIS_Products_Admin() )->register();
        ( new PCIO_VIS_Products_Delivery_Admin() )->register();
        ( new PCIO_VIS_Products_Frontend() )->register();
        ( new PCIO_VIS_Products_Integration() )->register();
        ( new PCIO_VIS_Products_Fulfillment() )->register();
        ( new PCIO_VIS_Products_Cron() )->register();
    }

    // ── Stripe configuration (reused from the Tickets extension) ──

    /**
     * The shop reuses the Tickets extension's Stripe keys, currency and sender
     * so the organisation configures Stripe in a single place. When the Tickets
     * extension is not installed these fall back to safe defaults (and paid
     * checkout is simply unavailable until it is configured).
     */
    public static function stripe_secret_key(): string {
        return class_exists( 'PCIO_VIS_Tickets_Admin' ) ? PCIO_VIS_Tickets_Admin::stripe_secret_key() : '';
    }

    public static function stripe_publishable_key(): string {
        return class_exists( 'PCIO_VIS_Tickets_Admin' ) ? PCIO_VIS_Tickets_Admin::stripe_publishable_key() : '';
    }

    public static function stripe_mode(): string {
        return class_exists( 'PCIO_VIS_Tickets_Admin' ) ? PCIO_VIS_Tickets_Admin::stripe_mode() : 'test';
    }

    public static function is_configured(): bool {
        return self::stripe_publishable_key() !== '' && self::stripe_secret_key() !== '';
    }

    public static function currency(): string {
        if ( class_exists( 'PCIO_VIS_Tickets_Admin' ) ) {
            return PCIO_VIS_Tickets_Admin::currency();
        }
        return (string) apply_filters( 'pcio_me_finance_currency', 'DKK' );
    }

    public static function from_email(): string {
        if ( class_exists( 'PCIO_VIS_Tickets_Admin' ) ) {
            return PCIO_VIS_Tickets_Admin::from_email();
        }
        return (string) get_bloginfo( 'admin_email' );
    }

    public static function from_name(): string {
        if ( class_exists( 'PCIO_VIS_Tickets_Admin' ) ) {
            return PCIO_VIS_Tickets_Admin::from_name();
        }
        return (string) get_bloginfo( 'name' );
    }

    /**
     * Format an amount in minor units (øre/cents) as a display string.
     */
    public static function format_money( int $cents, string $currency = '' ): string {
        $currency = $currency !== '' ? strtoupper( $currency ) : self::currency();
        return number_format_i18n( $cents / 100, 2 ) . ' ' . $currency;
    }

    // ── Activation / deactivation ─────────────────────────────────

    public static function activate(): void {
        PCIO_VIS_Products_DB::install();
        foreach ( self::CAP_ROLE_MAP as $cap => $roles ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $cap );
                }
            }
        }
        // Public storefront pages are served from rewrite rules registered on
        // 'init'; flush so /shop/accepted etc. and /vis/shop resolve.
        ( new PCIO_VIS_Products_Admin() )->register_rewrites();
        ( new PCIO_VIS_Products_Delivery_Admin() )->register_rewrites();
        ( new PCIO_VIS_Products_Frontend() )->register_rewrites();
        flush_rewrite_rules();

        PCIO_VIS_Products_Cron::activate();
        PCIO_VIS_Products_Cron::seed_mails();
    }

    public static function deactivate(): void {
        foreach ( array_keys( self::CAP_ROLE_MAP ) as $cap ) {
            foreach ( wp_roles()->role_objects as $role ) {
                $role->remove_cap( $cap );
            }
        }
        PCIO_VIS_Products_Cron::deactivate();
        flush_rewrite_rules();
    }

    // ── REST ──────────────────────────────────────────────────────

    public static function register_rest(): void {
        ( new PCIO_VIS_Products_Rest() )->register();
        ( new PCIO_VIS_Products_Delivery_Rest() )->register();
    }

    /** Re-grant all caps to their WP roles when CAPS_VERSION advances. */
    public static function maybe_grant_caps(): void {
        if ( get_option( 'pcio_mep_caps_version' ) === self::CAPS_VERSION ) {
            return;
        }
        foreach ( self::CAP_ROLE_MAP as $cap => $roles ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $cap );
                }
            }
        }
        update_option( 'pcio_mep_caps_version', self::CAPS_VERSION );
    }

    /** Flush rewrite rules once after a schema/rewrite migration. */
    public static function maybe_flush_rewrites(): void {
        if ( get_option( 'pcio_mep_flush_rewrites' ) ) {
            delete_option( 'pcio_mep_flush_rewrites' );
            flush_rewrite_rules( false );
        }
    }

    /** Flush rewrite rules once whenever REWRITES_VERSION advances. */
    public static function maybe_sync_rewrites(): void {
        if ( get_option( 'pcio_mep_rewrites_version' ) === self::REWRITES_VERSION ) {
            return;
        }
        update_option( 'pcio_mep_rewrites_version', self::REWRITES_VERSION );
        flush_rewrite_rules( false );
    }
}

register_activation_hook( __FILE__, [ 'PCIO_VIS_Products_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PCIO_VIS_Products_Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    // Hard dependency: this extension does nothing without the base PCIO VIS
    // Member Event plugin. The "Requires Plugins: pcio-vis-member-event" header
    // blocks activation on WP 6.5+; this guard + notice covers older cores and the
    // case where the base is deactivated while Products stays active.
    if ( ! class_exists( 'PCIO_VIS_Plugin' ) ) {
        add_action( 'admin_notices', static function (): void {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }
            echo '<div class="notice notice-error"><p>'
                . esc_html__(
                    'Member Event – Products requires the Member Event plugin to be installed and active. Shop functionality is disabled until then.',
                    'pcio-vis-products'
                )
                . '</p></div>';
        } );
        return;
    }
    PCIO_VIS_Products_Plugin::register();
} );
