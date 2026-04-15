<?php
/**
 * Code injection: GA4, GTM, AdSense, custom head/body/footer code.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Code_Injection {

    public function __construct() {
        if ( is_admin() ) return;

        add_action( 'wp_head', [ $this, 'head_code' ], 0 );
        add_action( 'wp_body_open', [ $this, 'body_code' ], 0 );
        add_action( 'wp_footer', [ $this, 'footer_code' ], 999 );
    }

    public function head_code() {
        // Google Analytics 4
        $ga = GML_SEO::opt( 'ga_id' );
        if ( $ga ) {
            echo "<!-- GML SEO: GA4 -->\n";
            echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $ga ) . '"></script>' . "\n";
            echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $ga ) . "');</script>\n";
        }

        // Google Tag Manager (head part)
        $gtm = GML_SEO::opt( 'gtm_id' );
        if ( $gtm ) {
            echo "<!-- GML SEO: GTM -->\n";
            echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $gtm ) . "');</script>\n";
        }

        // Google AdSense
        $adsense = GML_SEO::opt( 'adsense_id' );
        if ( $adsense ) {
            echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( $adsense ) . '" crossorigin="anonymous"></script>' . "\n";
        }

        // Custom head code
        $custom = GML_SEO::opt( 'head_code' );
        if ( $custom ) {
            echo "<!-- GML SEO: Custom Head -->\n" . $custom . "\n";
        }
    }

    public function body_code() {
        // GTM noscript
        $gtm = GML_SEO::opt( 'gtm_id' );
        if ( $gtm ) {
            echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        }

        $custom = GML_SEO::opt( 'body_code' );
        if ( $custom ) {
            echo "<!-- GML SEO: Custom Body -->\n" . $custom . "\n";
        }
    }

    public function footer_code() {
        $custom = GML_SEO::opt( 'footer_code' );
        if ( $custom ) {
            echo "<!-- GML SEO: Custom Footer -->\n" . $custom . "\n";
        }
    }
}
