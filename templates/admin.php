<?php
/**
 * Front-end template: Shop management page.
 * Served at /vis/shop by PCIO_VIS_Products_Admin::serve().
 *
 * Access control (login + vis_shop_manage) is enforced before this loads.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

show_admin_bar( false );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Vis &mdash; <?php esc_html_e( 'Shop', 'pcio-vis-products' ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php
    $pcio_me_active_page = 'shop';
    $pcio_vis_nav = PCIO_VIS_PLUGIN_DIR . 'templates/_nav.php';
    if ( file_exists( $pcio_vis_nav ) ) {
        require $pcio_vis_nav;
    }
    ?>

    <main class="me-main">
        <div class="me-page-header">
            <h1 class="me-page-title"><?php esc_html_e( 'Shop', 'pcio-vis-products' ); ?></h1>
        </div>

        <?php
        // Only surface tabs the current user may actually use. A finance-only
        // manager (vis_manage_finance without vis_shop_manage) sees just the
        // Subscriptions tab, so the page no longer defaults to a 403 on Products.
        $pcio_mep_can_products = current_user_can( 'vis_shop_manage' );
        $pcio_mep_can_subs     = $pcio_mep_can_products || current_user_can( 'vis_manage_finance' );
        $pcio_mep_first        = true;
        ?>
        <div class="mep-tabs" role="tablist">
            <?php if ( $pcio_mep_can_products ) : ?>
            <button class="mep-tab is-active" data-tab="products" role="tab">
                <?php esc_html_e( 'Products', 'pcio-vis-products' ); ?>
            </button>
            <?php $pcio_mep_first = false; endif; ?>

            <?php if ( $pcio_mep_can_subs ) : ?>
            <button class="mep-tab<?php echo $pcio_mep_first ? ' is-active' : ''; ?>" data-tab="subscriptions" role="tab">
                <?php esc_html_e( 'Subscriptions', 'pcio-vis-products' ); ?>
            </button>
            <?php $pcio_mep_first = false; endif; ?>

            <?php if ( $pcio_mep_can_products ) : ?>
            <button class="mep-tab" data-tab="delivery-methods" role="tab">
                <?php esc_html_e( 'Delivery Methods', 'pcio-vis-products' ); ?>
            </button>
            <?php endif; ?>
        </div>

        <div id="pcio-me-app">
            <div class="me-loading">
                <div class="me-spinner"></div>
                <span><?php esc_html_e( 'Loading&hellip;', 'pcio-vis-products' ); ?></span>
            </div>
        </div>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
