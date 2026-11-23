<?php

/**
 * Plugin Name:       Orboni for Tutor LMS
 * Plugin URI:        https://orboni.com/docs/tutor-lms
 * Description:       Sell courses for crypto — USDT, USDC, BTC, ETH and more — paid straight to your own wallets. A payment method for Tutor LMS's own checkout; Orboni never holds your money.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  tutor
 * Author:            Orboni
 * Author URI:        https://orboni.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orboni-for-tutor-lms
 */
defined('ABSPATH') || exit;

define('ORBONI_TUTOR_VERSION', '1.0.0');
define('ORBONI_TUTOR_FILE', __FILE__);
define('ORBONI_TUTOR_DIR', plugin_dir_path(__FILE__));
define('ORBONI_TUTOR_URL', plugin_dir_url(__FILE__));

/** The Tutor LMS whose own checkout and payment gateways this is written against. */
define('ORBONI_TUTOR_MIN_TUTOR', '3.0.0');

/**
 * The plugin's classes, loaded when first named. Three of them extend Tutor's
 * classes (the gateway) or those of the payment library Tutor ships (the
 * payment and its config), so they are loaded only once Tutor asks for them.
 */
spl_autoload_register(static function ($class) {
    if (strpos($class, 'Orboni_Tutor_') !== 0) {
        return;
    }
    $file = ORBONI_TUTOR_DIR.'includes/class-'.str_replace('_', '-', strtolower($class)).'.php';
    if (! is_readable($file)) {
        return;
    }
    if (in_array($class, ['Orboni_Tutor_Payment', 'Orboni_Tutor_Config'], true)) {
        Orboni_Tutor_Gateway::load_payment_library();
    }
    require_once $file;
});

add_action('plugins_loaded', 'orboni_tutor_init', 20);

/** Hooks in once Tutor LMS 3.0+, with its own checkout, is there. */
function orboni_tutor_init()
{
    if (! defined('TUTOR_VERSION') || version_compare(TUTOR_VERSION, ORBONI_TUTOR_MIN_TUTOR, '<') || ! class_exists('\Tutor\PaymentGateways\GatewayBase')) {
        add_action('admin_notices', 'orboni_tutor_missing_notice');

        return;
    }

    Orboni_Tutor_Plugin::register();
}

/** Shown to whoever can activate plugins, while Tutor LMS is missing or older than 3.0. */
function orboni_tutor_missing_notice()
{
    if (! current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
        /* translators: %s: the least Tutor LMS version, e.g. 3.0.0 */
        __('Orboni for Tutor LMS needs Tutor LMS %s or later, active.', 'orboni-for-tutor-lms'),
        ORBONI_TUTOR_MIN_TUTOR
    )).'</p></div>';
}

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function ($links) {
    $url = admin_url('admin.php?page=tutor_settings&tab_page=ecommerce_payment');
    array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'orboni-for-tutor-lms').'</a>');

    return $links;
});
