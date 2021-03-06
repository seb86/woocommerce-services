<?php
/*
Plugin Name: wc-services-testing-helper
Plugin URI: http://
Description: Helps testing wc-services
Author: Extendables
Version: 1.0
Author URI: http://
*/

define( 'JETPACK_DEV_DEBUG', true );
define( 'WOOCOMMERCE_SERVICES_LOCAL_TEST_MODE', true );
define( 'WOOCOMMERCE_CONNECT_FREQUENT_FETCH', true );
define( 'WOOCOMMERCE_CONNECT_SERVER_URL', 'http://localhost:5000/' );
define( 'WOOCOMMERCE_SERVICES_CI_TEST_MODE', true );

if ( get_option( 'woocommerce_default_country' ) !== 'US:NY' ) {
  update_option( 'woocommerce_store_address', '1480 York Ave' );
  update_option( 'woocommerce_store_city', 'New York' );
  update_option( 'woocommerce_store_postcode', '10075' );
  update_option( 'woocommerce_default_country', 'US:NY' );
  update_option( 'woocommerce_currency', 'USD' );
  update_option( 'woocommerce_weight_unit', 'oz' );
  update_option( 'woocommerce_dimension_unit', 'in' );
}

function wc_test_connect_jetpack_access_fake_token( $token ) {
        $token = new stdClass();
        $token->secret = "askjdflasjdlfkajsldfkjaslkdf.asdkfjlaskjdflajskd";
        $token->external_user_id = 0;
        return $token;
}

add_filter( 'wc_connect_jetpack_access_token', 'wc_test_connect_jetpack_access_fake_token' );
