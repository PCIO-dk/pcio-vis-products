=== PCIO VIS Products ===
Contributors:      pcio
Author:            PCIO
Author URI:        https://www.pcio.dk
Plugin URI:        https://www.pcio.dk/vis-plugins
Tags:              shop, products, subscriptions, stripe, license keys
Requires at least: 6.2
Tested up to:      7.1
Requires PHP:      8.1
Requires Plugins:  pcio-vis-member-event, pcio-vis-tickets
Stable tag:        1.4.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Adds a digital shop to PCIO VIS Member Event: products, license-key subscriptions, a storefront with Stripe checkout and member subscriptions.

== Description ==

PCIO VIS Products is an extension for the PCIO VIS Member Event plugin. It adds a digital shop with products and license-key subscriptions, a public `[pcio_me_shop]` storefront with Stripe checkout, a Shop Orders list in Finance, automatic membership subscriptions on the member list, and a renewal/expiry reminder cron.

Features:

* Digital products and license-key subscriptions.
* Public storefront via the `[pcio_me_shop]` shortcode with Stripe checkout.
* Shop Orders list integrated into the core Finance area.
* Automatic membership subscriptions on the member list.
* Renewal and expiry reminder cron.

This plugin requires the PCIO VIS Member Event core plugin to be installed and active. It reuses the PCIO VIS Tickets plugin's Stripe configuration.

== Installation ==

1. Install and activate the **PCIO VIS Member Event** core plugin first, version 1.5 or later.
2. Upload the `pcio-vis-products` directory to `/wp-content/plugins/`.
3. Activate the plugin in **Plugins** in the WordPress admin.
4. Add products and configure the shop under the **Vis** admin menu.

== Frequently Asked Questions ==

= Where does the storefront appear? =
Add the `[pcio_me_shop]` shortcode to any page, or browse the shop under `/vis/shop`.

= Do I need to configure Stripe here? =
Stripe configuration is shared with the PCIO VIS Tickets plugin. No separate Stripe setup is needed in this plugin.

== External services ==

This plugin connects to the Stripe payment API (operated by Stripe, Inc.) to process card payments for paid products and subscription renewals. Free products (price 0) never contact Stripe. Stripe is only used when Stripe API keys have been entered in the PCIO VIS Tickets settings.

What is sent, and when:

* **When a buyer checks out a paid product or renewal:** the plugin creates a Stripe Checkout Session by sending the order number, line items (product names and amounts), the currency, the buyer's email address, and success/cancel return URLs to `https://api.stripe.com`. The buyer is then redirected to Stripe's hosted checkout page, where card details are entered directly with Stripe (card data never passes through this site).
* **When an administrator refunds a paid order:** the payment reference is sent to `https://api.stripe.com` to create the refund.
* **On the order confirmation page:** the plugin queries `https://api.stripe.com` to confirm payment status (this site does not use a separate webhook for the shop).

Buyer name and email entered at checkout are stored on this site to issue and email licence keys. Because personal data and payment data are transmitted to Stripe, please ensure your own privacy policy covers this. By using this integration you and your buyers are subject to Stripe's terms and policies:

* Stripe Services Agreement (terms): https://stripe.com/legal/ssa
* Stripe Privacy Policy: https://stripe.com/privacy


== Screenshots ==

1. The product page where products can be maintained. Single purchase or subscription products.

== Upgrade Notice ==

= 1.4.0 =
Requires PCIO VIS Member Event 1.6 or later.

= 1.3.0 =
Requires PCIO VIS Member Event 1.5 or later. On upgrade, the subscription table's `member_subscription_number` column is renamed to `member_number` automatically — existing membership numbers are preserved and no manual SQL is needed. Open any wp-admin page so the schema upgrade runs.

= 1.2.0 =
Requires PCIO VIS Member Event 1.4 or later, which adds the member "Membership #" field that subscriptions are linked to. Update the core plugin first, then open any wp-admin page so its schema upgrade runs.

== Changelog ==

= 1.4.0 =
* Added: Change history with undo — a new Vis → History page lists changes with filters and an Undo button, guarded by the new `vis_undo_changes` capability.

= 1.3.0 =
* Added: **Single purchase email per order** — a confirmed order now sends exactly one email. Fulfilment (creating subscriptions and linking the membership) runs first, the receipt is sent afterwards, and the result page on `/shop/accepted` shows the same body as the email, so what the customer reads on screen matches what arrives in their inbox.
* Added: **Purchase-category emails** — two new editable templates in the core Mails editor (`/vis/mails/`): *membership_prolonged* ("Your membership at {site_name} has been renewed") and *subscription_purchased* ("Your {product_name} subscription"). Both are seeded automatically, also on existing installs, and are listed in the Mails editor with their own insertable tags.
* Added: New memberships bought through the shop are welcomed by the core *member welcome* email, so a single editable source drives both the signup form and the shop. The welcome mail is sent once, and a WordPress account is provisioned for shop-direct membership purchases so the email carries a working set-password link.
* Added: Renewal and expiry reminders are now sent to **every member sharing the membership number**, not only the buyer, so crew on a shared membership are notified too. Recipients are de-duplicated by e-mail address and each mail is personalised with that member's own name and membership number.
* Added: The Subscriptions list no longer shows the duplicate Membership # column; the member's Member # column is the single link to the membership.
* Changed: The subscription table column `member_subscription_number` is now `member_number`, matching the core naming. The rename runs automatically and preserves all existing subscription data, and the REST API field is renamed to match (`member_subscription_number` → `member_number`).
* Changed: Mails are now built from templates with a single code path, so the fallback text and the editable templates can no longer drift apart.
* Improved: Licence keys are only listed in the purchase email for products that actually have one.
= 1.2.0 =
* Added: **Manage subscriptions** — the Subscriptions tab on `/vis/shop` now lets authorized users create, edit and delete member subscriptions directly, alongside the ones generated automatically from Stripe orders. The dialog picks a product, the member's membership number, an expiry date and a status (active, expired or cancelled).
* Added: **Membership number linking** — subscriptions are tied to a member by the core "Membership #" field. The Subscriptions tab gains Member #, Membership and Counted columns (with a Counted filter) so you can see and reconcile exactly which subscriptions count toward the active-membership total.
* Changed: subscription management is available to users with either **Manage finance** or **Manage shop**, and the `/vis/shop` page now opens for finance managers too.

= 1.1.0 =
* Added: Membership Rules admin page (**Vis → Membership Rules**) to control how subscription expiry dates are calculated — Floating (1 year from purchase), Fixed annual start, or Fixed with a cutting date.
* Added: Subscription period selector in the product dialog so a product can follow the Floating, Fixed, or Fixed-with-cutting-date rule.
* Added: Editable email templates in the core Mails editor (`/vis/mails/`) for the subscription reminder, subscription-ended, order-confirmation, and delivery-notification emails, with insertable tags such as `{name}`, `{product_name}`, `{expire_date}`, `{renew_url}`, `{licence_keys}`, `{order_no}`, and `{site_name}`. Templates are seeded automatically, including on existing installs.
* Added: New `[pcio_me_member_subscription_count]` shortcode that shows an animated count of members with an active membership subscription.
* Added: Active-membership count is now contributed to the core Statistics dashboard.
* Added: Subscription details (`{subscription_name}`, `{expire_date}`) are now available as tags in the member welcome email.
* Added: Quantity picker with a configurable `max_qty` attribute on the `[pcio_me_product_order]` shortcode.
* Added: Cron test mode toggle on the Vis sync page — routes all subscription emails to the site administrator and keeps expired subscriptions in place, for safe testing and migration.
* Added: Shortcode reference page now documents `[pcio_me_shop]`, `[pcio_me_product_order]`, and `[pcio_me_member_subscription_count]`, including navigation entries.
* Added: Dark page-header banner (matching the Members and Finance pages) on the `/vis/shop` and `/vis/delivery` admin pages.
* Improved: The delivery orders list now shows the member's address and phone (from the core member record), and the delivery-notification email includes them.
* Fixed: `/vis/delivery` showed a duplicate section heading — the JS-rendered "Delivery Orders" inner header has been removed.
* Fixed: Resolved WordPress Plugin Check warnings (nonce verification on the Membership Rules page and a discouraged schema-change query in the products database layer).

= 1.0.0 =
* Initial release.

== Uninstall ==

Deleting the plugin from **Plugins** removes every option and transient it owns — schema and registration versions, your Membership Rules settings, the cron test mode flag and the mail-template seed markers — so that reinstalling later starts from a clean state.

The database tables are deliberately **kept**, because they hold financial records (paid orders, refunds and issued license keys) that you may be legally required to retain. These tables survive:

* `{prefix}me_p_orders`
* `{prefix}me_p_order_items`
* `{prefix}me_p_subscriptions`
* `{prefix}me_p_customers`
* `{prefix}me_p_products`
* `{prefix}me_p_delivery_methods`

The six mail templates this plugin seeds into the core **Mails** editor (`shop_order_confirmation`, `shop_delivery_notification`, `subscription_reminder`, `subscription_ended`, `membership_prolonged` and `subscription_purchased`) are also kept, so that any edits you made survive and a reinstall reuses them rather than seeding duplicates.

If you want a full wipe, drop the tables above manually from phpMyAdmin or a SQL client. If you delete the PCIO VIS Member Event core plugin as well, note that its own uninstall drops the core `me_mails` table, which removes the templates along with it.
