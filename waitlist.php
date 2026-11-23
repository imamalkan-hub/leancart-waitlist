<?php
/**
 * Plugin Name: LeanCart Out-of-Stock Waitlist
 * Plugin URI:  https://frenectomyguide.com/
 * Description: Adds a "Notify me when back in stock" form to out-of-stock product pages. Collected emails are listed in the admin panel; when the product is back in stock, a one-time email is sent to registered customers.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: waitlist
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_WAITLIST_DEMO_SITE' ) ) {
	define( 'LEANCART_WAITLIST_DEMO_SITE', 'https://frenectomyguide.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Waitlist {

	const SLUG = 'waitlist';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Out-of-Stock Waitlist',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_WAITLIST_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'waitlist', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Out-of-Stock Waitlist' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'waitlist' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_checkbox( 'enabled', __('Show form', 'waitlist'), __('Show the form on out-of-stock products.', 'waitlist') );
		LeanCart_Framework::field_text( 'button_text', __('Button text', 'waitlist'), __('Text of the notification button.', 'waitlist'), 'text' );
	}


	public static function hooks() {
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'form' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'submit' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'notify' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'notify' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'notify' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_page' ) );
	}

	public static function form( $html, $product ) {
		if ( 'yes' !== LeanCart_Framework::setting( 'enabled', 'yes' ) || $product->is_in_stock() ) { return $html; }
		$text = LeanCart_Framework::setting( 'button_text', 'Notify me when back in stock' );
		$html .= '<form class="lc-waitlist-form" method="post" style="margin-top:12px">';
		$html .= '<input type="email" name="lc_waitlist_email" required placeholder="' . esc_attr__( 'Your email address', 'waitlist' ) . '" style="padding:8px;width:220px" />';
		$html .= '<input type="hidden" name="lc_waitlist_product" value="' . esc_attr( $product->get_id() ) . '" />';
		$html .= wp_nonce_field( 'lc_waitlist_submit', '_lc_nonce', true, false );
		$html .= '<button type="submit" class="button" style="margin-left:6px">' . esc_html( $text ) . '</button></form>';
		return $html;
	}

	public static function submit() {
		if ( ! isset( $_POST['lc_waitlist_email'], $_POST['lc_waitlist_product'] ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_lc_nonce'] ?? '' ) ), 'lc_waitlist_submit' ) ) { return; }
		$raw   = is_string( $_POST['lc_waitlist_email'] ) ? wp_unslash( $_POST['lc_waitlist_email'] ) : '';
		$email = sanitize_email( $raw );
		$pid   = is_scalar( $_POST['lc_waitlist_product'] ) ? absint( wp_unslash( $_POST['lc_waitlist_product'] ) ) : 0;
		if ( ! is_email( $email ) || ! $pid || ! function_exists( 'wc_get_product' ) ) { return; }
		$product = wc_get_product( $pid );
		if ( ! $product || $product->is_in_stock() ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'lc_waitlist';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d AND email = %s AND notified = 0", $pid, $email ) );
		if ( ! $exists ) {
			$wpdb->insert( $table, array( 'product_id' => $pid, 'email' => $email, 'created_at' => current_time( 'mysql' ) ), array( '%d', '%s', '%s' ) );
		}
		wc_add_notice( __( 'You are saved. We will email you when the product is back in stock.', 'waitlist' ) );
		wp_safe_redirect( $product->get_permalink() );
		exit;
	}

	public static function notify( $product, $stock_status = null, $product_obj = null ) {
		if ( is_object( $product_obj ) ) { $product = $product_obj; }
		if ( ! is_object( $product ) && function_exists( 'wc_get_product' ) ) { $product = wc_get_product( absint( $product ) ); }
		if ( ! is_object( $product ) || ! $product->is_in_stock() ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'lc_waitlist';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT email FROM {$table} WHERE product_id = %d AND notified = 0", $product->get_id() ) );
		foreach ( $rows as $row ) {
			wp_mail( $row->email, sprintf( __( '%s is back in stock!', 'waitlist' ), $product->get_name() ), sprintf( __( 'The product you waited for is available again: %s', 'waitlist' ), get_permalink( $product->get_id() ) ) );
		}
		if ( $rows ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET notified = 1 WHERE product_id = %d", $product->get_id() ) );
		}
	}

	public static function admin_page() {
		add_submenu_page( 'woocommerce', __( 'Waitlist', 'waitlist' ), __( 'Waitlist', 'waitlist' ), 'manage_woocommerce', 'lc-waitlist', array( __CLASS__, 'render_admin' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die(); }
		global $wpdb;
		$table = $wpdb->prefix . 'lc_waitlist';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );
		echo '<div class="wrap"><h1>' . esc_html__( 'Waitlist', 'waitlist' ) . '</h1><table class="widefat"><thead><tr><th>' . esc_html__( 'Product', 'waitlist' ) . '</th><th>' . esc_html__( 'Email', 'waitlist' ) . '</th><th>' . esc_html__( 'Notified', 'waitlist' ) . '</th><th>' . esc_html__( 'Date', 'waitlist' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( get_the_title( $row->product_id ) ) . '</td><td>' . esc_html( $row->email ) . '</td><td>' . ( $row->notified ? esc_html__( 'Yes', 'waitlist' ) : esc_html__( 'No', 'waitlist' ) ) . '</td><td>' . esc_html( $row->created_at ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}


	public static function install() {
		global $wpdb; $table = $wpdb->prefix . 'lc_waitlist'; $charset = $wpdb->get_charset_collate(); require_once ABSPATH . 'wp-admin/includes/upgrade.php'; dbDelta( "CREATE TABLE {$table} ( id bigint(20) NOT NULL AUTO_INCREMENT, product_id bigint(20) NOT NULL, email varchar(190) NOT NULL, notified tinyint(1) NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY (id), KEY product_id (product_id) ) {$charset};" );
	}

}

LeanCart_Waitlist::init();
register_activation_hook( __FILE__, array( 'LeanCart_Waitlist', 'install' ) );
