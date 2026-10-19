<?php
/**
 * Front-end template: Delivery orders page.
 * Served at /vis/delivery by PCIO_VIS_Products_Delivery_Admin::serve().
 *
 * Access control (login + vis_shop_delivery) is enforced before this loads.
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
    <title>Vis &mdash; <?php esc_html_e( 'Finance', 'pcio-vis-products' ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php
    $pcio_me_active_page = 'delivery';
    $pcio_vis_nav = PCIO_VIS_PLUGIN_DIR . 'templates/_nav.php';
    if ( file_exists( $pcio_vis_nav ) ) {
        require $pcio_vis_nav;
    }
    ?>

    <main class="me-main">
        <div class="me-page-header">
            <h1 class="me-page-title"><?php esc_html_e( 'Finance', 'pcio-vis-products' ); ?></h1>
        </div>

        <?php $pcio_me_section = 'finance'; require PCIO_VIS_PLUGIN_DIR . 'templates/_section-tabs.php'; ?>

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
