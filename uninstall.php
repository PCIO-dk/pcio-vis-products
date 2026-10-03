<?php
/**
 * Uninstall handler for PCIO VIS Products.
 *
 * Runs only when the plugin is deleted from the WordPress admin. This file runs
 * in a minimal WP context, so plugin classes are not loaded and every option
 * name is listed explicitly.
 *
 * Removes every option and transient the plugin owns, so a later reinstall
 * starts from a clean state instead of inheriting stale schema versions,
 * membership rules, cron test mode or mail-seed markers.
 *
 * NOTE: the custom shop tables are intentionally NOT dropped:
 *
 *     {prefix}me_p_orders
 *     {prefix}me_p_order_items
 *     {prefix}me_p_subscriptions
 *     {prefix}me_p_customers
 *     {prefix}me_p_products
 *     {prefix}me_p_delivery_methods
 *
 * They hold financial records — paid orders, refunds and issued license keys —
 * that a site owner may be legally required to retain, so we do not destroy
 * them automatically on delete. Mail templates seeded into the core
 * {prefix}me_mails table (shop_order_confirmation, shop_delivery_notification,
 * subscription_reminder, subscription_ended, membership_prolonged,
 * subscription_purchased) are likewise kept: the site owner may have edited
 * them, and keeping them lets a reinstall reuse them instead of seeding
 * duplicates. Drop the tables manually from phpMyAdmin or a SQL client once
 * you are certain the sales history is no longer needed.
 *
 * @package PCIO_VIS_Products
 */

// Bail if WordPress did not invoke this file via the uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pcio_me_products_options = [
	// Schema and registration versions.
	'pcio_mep_db_version',
	'pcio_mep_caps_version',
	'pcio_mep_rewrites_version',
	'pcio_mep_flush_rewrites',

	// Membership Rules settings (Vis -> Membership Rules).
	'pcio_mep_mem_rule_type',
	'pcio_mep_mem_start_day',
	'pcio_mep_mem_start_month',
	'pcio_mep_mem_cutting_day',
	'pcio_mep_mem_cutting_month',

	// Cron test mode (Vis -> Sync).
	'pcio_mep_cron_test_mode',

	// Mail template seed markers, including the pre-1.3 name which is no
	// longer read but may still be present on upgraded sites.
	'pcio_mep_mails_seeded',
	'pcio_mep_fulfillment_mails_seeded_v2',
	'pcio_mep_fulfillment_mails_seeded',
];

foreach ( $pcio_me_products_options as $pcio_me_products_option ) {
	delete_option( $pcio_me_products_option );
}

// Per-order receipt markers (pcio_mep_emailed_*, pcio_mep_receipt_*) are left
// alone: they expire after 24 hours and are keyed to orders in a table that
// deliberately survives uninstall.
delete_transient( 'pcio_mep_mem_rules_saved' );