<?php

use Tutor\PaymentGateways\GatewayBase;

defined('ABSPATH') || exit;

/**
 * The method as Tutor LMS knows a payment gateway: Tutor makes it for its
 * checkout (`setup_payment_and_redirect`) and its webhook address
 * (`verify_webhook_signature`), each time through the payment library it
 * ships, which builds {@see Orboni_Tutor_Payment} with {@see Orboni_Tutor_Config}.
 */
class Orboni_Tutor_Gateway extends GatewayBase
{
    public function get_root_dir_name(): string
    {
        return Orboni_Tutor_Settings::ID;
    }

    public function get_payment_class(): string
    {
        return Orboni_Tutor_Payment::class;
    }

    public function get_config_class(): string
    {
        return Orboni_Tutor_Config::class;
    }

    /** The payment library Tutor ships with its PayPal gateway, which every gateway's classes build on. */
    public static function get_autoload_file()
    {
        return self::library_file();
    }

    /**
     * Loads the payment library before a class built on it: Tutor asks for
     * the config alone to know whether the method is configured, before any
     * gateway has loaded the library.
     */
    public static function load_payment_library()
    {
        if (! class_exists('\Ollyo\PaymentHub\Core\Payment\BasePayment')) {
            $file = self::library_file();
            if (is_readable($file)) {
                require_once $file;
            }
        }
    }

    private static function library_file()
    {
        return tutor()->path.'ecommerce/PaymentGateways/Paypal/vendor/autoload.php';
    }
}
