<?php
/**
 * Front-end template: order result page (/shop/accepted, /shop/rejected).
 *
 * @var array $pcio_mep_view { view, order, subscriptions, home_url }
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_vis_view          = (string) $pcio_mep_view['view'];
$pcio_vis_order         = $pcio_mep_view['order'];
$pcio_vis_subscriptions = (array) $pcio_mep_view['subscriptions'];
$pcio_vis_home          = (string) $pcio_mep_view['home_url'];

$pcio_vis_accepted = ( 'accepted' === $pcio_vis_view );
$pcio_vis_paid     = $pcio_vis_order && in_array( (string) $pcio_vis_order['status'], [ 'paid', 'free_confirmed' ], true );

// Same body as the purchase email, cached by PCIO_VIS_Products_Fulfillment::send_receipt().
$pcio_vis_receipt = null;
if ( $pcio_vis_order ) {
    $pcio_vis_cached = get_transient( 'pcio_mep_receipt_' . (int) $pcio_vis_order['id'] );
    if ( $pcio_vis_cached ) {
        $pcio_vis_decoded = json_decode( (string) $pcio_vis_cached, true );
        if ( is_array( $pcio_vis_decoded ) && ! empty( $pcio_vis_decoded['content'] ) ) {
            $pcio_vis_receipt = $pcio_vis_decoded;
        }
    }
}

get_header();
?>
<div class="mep-result <?php echo $pcio_vis_accepted ? 'is-accepted' : 'is-rejected'; ?>">

    <?php if ( $pcio_vis_accepted && $pcio_vis_paid ) : ?>
        <h1><?php esc_html_e( 'Thank you for your purchase!', 'pcio-vis-products' ); ?></h1>

        <?php if ( $pcio_vis_receipt ) : ?>
            <div class="mep-receipt"><?php echo wp_kses_post( (string) $pcio_vis_receipt['content'] ); ?></div>
        <?php else : ?>
            <p>
                <?php
                printf(
                    /* translators: %s: order number */
                    esc_html__( 'Your order %s has been confirmed. A confirmation email is on its way.', 'pcio-vis-products' ),
                    '<strong>' . esc_html( (string) $pcio_vis_order['order_no'] ) . '</strong>'
                );
                ?>
            </p>

            <?php if ( $pcio_vis_subscriptions ) : ?>
                <h2><?php esc_html_e( 'Your licence keys', 'pcio-vis-products' ); ?></h2>
                <ul class="mep-keys">
                    <?php foreach ( $pcio_vis_subscriptions as $pcio_vis_sub ) : ?>
                        <li>
                            <strong><?php echo esc_html( (string) $pcio_vis_sub['product_name'] ); ?></strong><br>
                            <code><?php echo esc_html( (string) $pcio_vis_sub['license_token'] ); ?></code><br>
                            <span class="mep-muted">
                                <?php
                                printf(
                                    /* translators: %s: expiry date */
                                    esc_html__( 'Valid until %s', 'pcio-vis-products' ),
                                    esc_html( (string) $pcio_vis_sub['expire_date'] )
                                );
                                ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="mep-muted"><?php esc_html_e( 'Enter your licence key in the product to activate it.', 'pcio-vis-products' ); ?></p>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ( $pcio_vis_accepted && ! $pcio_vis_paid ) : ?>
        <h1><?php esc_html_e( 'Almost there…', 'pcio-vis-products' ); ?></h1>
        <p><?php esc_html_e( 'We are still confirming your payment. Please refresh this page in a moment, or check your email.', 'pcio-vis-products' ); ?></p>

    <?php else : ?>
        <h1><?php esc_html_e( 'Payment cancelled', 'pcio-vis-products' ); ?></h1>
        <p><?php esc_html_e( 'Your order was not completed and you have not been charged.', 'pcio-vis-products' ); ?></p>
    <?php endif; ?>

    <p><a class="mep-btn mep-btn-primary" href="<?php echo esc_url( $pcio_vis_home ); ?>"><?php esc_html_e( 'Back to the website', 'pcio-vis-products' ); ?></a></p>
</div>
<?php
get_footer();
