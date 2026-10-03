<?php
/**
 * Front-end template: subscription renewal page (/shop/renew?token=...).
 *
 * @var array $pcio_mep_view { subscription, product, currency, home_url }
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_vis_sub      = $pcio_mep_view['subscription'];
$pcio_vis_product  = $pcio_mep_view['product'];
$pcio_vis_home     = (string) $pcio_mep_view['home_url'];

$pcio_vis_price = $pcio_vis_product
    ? PCIO_VIS_Products_Plugin::format_money( (int) $pcio_vis_product['price_cents'], (string) ( $pcio_vis_product['currency'] ?? '' ) )
    : '';

// Compute what the new expire date will be after a 1-year renewal.
$pcio_vis_current_expire = (string) ( $pcio_vis_sub['expire_date'] ?? '' );
$pcio_vis_base           = $pcio_vis_current_expire && strtotime( $pcio_vis_current_expire ) > time()
    ? $pcio_vis_current_expire
    : gmdate( 'Y-m-d' );
$pcio_vis_new_expire = gmdate( 'Y-m-d', strtotime( $pcio_vis_base . ' +1 year' ) );

get_header();
?>
<div class="mep-renew">
    <h1><?php esc_html_e( 'Renew your subscription', 'pcio-vis-products' ); ?></h1>

    <p>
        <?php
        printf(
            /* translators: 1: product name, 2: expiry date */
            esc_html__( 'Your subscription to %1$s expires on %2$s.', 'pcio-vis-products' ),
            '<strong>' . esc_html( (string) $pcio_vis_sub['product_name'] ) . '</strong>',
            '<strong>' . esc_html( (string) $pcio_vis_sub['expire_date'] ) . '</strong>'
        );
        ?>
    </p>

    <?php if ( $pcio_vis_product ) : ?>
        <p>
            <?php
            printf(
                /* translators: %s: price */
                esc_html__( 'Renew now for %s to extend it by one year.', 'pcio-vis-products' ),
                '<strong>' . esc_html( $pcio_vis_price ) . '</strong>'
            );
            ?>
        </p>
        <p style="color:#64748b;font-size:.9rem">
            <?php
            printf(
                /* translators: %s: new expiry date */
                esc_html__( 'New expiry date: %s', 'pcio-vis-products' ),
                '<strong>' . esc_html( $pcio_vis_new_expire ) . '</strong>'
            );
            ?>
        </p>
        <p>
            <button id="pcio-mep-renew-btn" class="mep-btn mep-btn-primary">
                <?php esc_html_e( 'Renew now', 'pcio-vis-products' ); ?>
            </button>
        </p>
    <?php else : ?>
        <p class="mep-muted"><?php esc_html_e( 'This subscription can no longer be renewed online. Please contact us.', 'pcio-vis-products' ); ?></p>
    <?php endif; ?>

    <p><a class="mep-muted" href="<?php echo esc_url( $pcio_vis_home ); ?>"><?php esc_html_e( 'Back to the website', 'pcio-vis-products' ); ?></a></p>
</div>
<?php
get_footer();
