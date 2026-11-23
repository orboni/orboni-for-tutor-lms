<?php

use Tutor\Ecommerce\OptionKeys;
use Tutor\Ecommerce\Settings;

defined('ABSPATH') || exit;

/**
 * The method's settings, as Tutor LMS keeps them: its payment settings
 * (Tutor LMS → Settings → Payment Methods → Crypto), saved by Tutor's own
 * form with the other methods'. Read here, never written.
 */
class Orboni_Tutor_Settings
{
    /** The method's name in Tutor: its settings, its orders' `payment_method`, its webhook address. */
    public const ID = 'orboni';

    /**
     * The currencies Orboni takes a price in, and the decimals of each one's
     * smallest unit (ISO 4217): Orboni turns a price into dollars at the
     * rate of the day, the ECB's or the market's.
     */
    public const CURRENCIES = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** Orboni's least payment is worth $1: checked here in a dollar store, by Orboni in any other. */
    public const MIN_CENTS = 100;

    public const SECRET_KEY = '/^sk_(live|test)_[A-Za-z0-9]+$/';

    public const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    /** @var array<string, string>|null this request's copy */
    private static $fields;

    /**
     * The fields Tutor's settings form draws for the method, in its own
     * format. Tutor's form requires each but the webhook address and turns
     * the method off while one is empty.
     *
     * @return list<array<string, mixed>>
     */
    public static function form_fields()
    {
        return [
            [
                'name' => 'secret_key',
                'type' => 'secret_key',
                'label' => __('Secret key (sk_live_… or sk_test_…)', 'orboni-for-tutor-lms'),
                'value' => '',
            ],
            [
                'name' => 'webhook_secret',
                'type' => 'secret_key',
                'label' => __('Webhook signing secret (whsec_…)', 'orboni-for-tutor-lms'),
                'value' => '',
            ],
            [
                'name' => 'webhook_url',
                'type' => 'webhook_url',
                'label' => __('Webhook URL', 'orboni-for-tutor-lms'),
                'value' => '',
            ],
            [
                'name' => 'debug_log',
                'type' => 'select',
                'label' => __('Debug log', 'orboni-for-tutor-lms'),
                'options' => [
                    'off' => __('Errors only', 'orboni-for-tutor-lms'),
                    'on' => __('Requests and webhooks too', 'orboni-for-tutor-lms'),
                ],
                'value' => 'off',
            ],
        ];
    }

    /** @return array<string, string> the saved values by field name */
    private static function fields()
    {
        if (self::$fields !== null) {
            return self::$fields;
        }

        $saved = Settings::get_payment_gateway_settings(self::ID);
        $fields = [];
        foreach ((array) ($saved['fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['name'])) {
                $fields[(string) $field['name']] = is_scalar($field['value'] ?? null) ? trim((string) $field['value'], " \t\n\r\0\x0B") : '';
            }
        }

        return self::$fields = $fields;
    }

    /** Forgets this request's copy (after Tutor saves its settings). */
    public static function flush()
    {
        self::$fields = null;
    }

    /** The saved secret key, or '' while it is missing or not one. */
    public static function secret_key()
    {
        $key = self::fields()['secret_key'] ?? '';

        return preg_match(self::SECRET_KEY, $key) === 1 ? $key : '';
    }

    /** What was saved as the secret key, valid or not: the settings' notices name a wrong one. */
    public static function saved_secret_key()
    {
        return self::fields()['secret_key'] ?? '';
    }

    public static function webhook_secret()
    {
        $secret = self::fields()['webhook_secret'] ?? '';

        return preg_match(self::WEBHOOK_SECRET, $secret) === 1 ? $secret : '';
    }

    public static function saved_webhook_secret()
    {
        return self::fields()['webhook_secret'] ?? '';
    }

    public static function debug()
    {
        return (self::fields()['debug_log'] ?? 'off') === 'on';
    }

    /** Switched on in Tutor's list of payment methods. */
    public static function is_active()
    {
        $saved = Settings::get_payment_gateway_settings(self::ID);

        return ! empty($saved['is_active']);
    }

    /** The store's currency, as Tutor prices its courses. */
    public static function currency()
    {
        return strtoupper((string) tutor_utils()->get_option(OptionKeys::CURRENCY_CODE, 'USD'));
    }

    /** Ready to take a payment: a secret key, and a currency Orboni takes. Tutor offers the method only then. */
    public static function is_configured()
    {
        return self::secret_key() !== '' && self::supports_currency(self::currency());
    }

    public static function supports_currency($currency)
    {
        return isset(self::CURRENCIES[strtoupper((string) $currency)]);
    }

    public static function decimals($currency)
    {
        return self::CURRENCIES[strtoupper((string) $currency)] ?? 2;
    }

    /** A price in the currency's smallest unit, as Orboni takes it: 1250 for 12.50 EUR, 1500 for 1500 JPY. */
    public static function minor($amount, $currency)
    {
        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen. */
    public static function format_minor($amount, $currency)
    {
        $decimals = self::decimals($currency);

        return number_format(((int) $amount) / (10 ** $decimals), $decimals, '.', '').' '.strtoupper((string) $currency);
    }

    /**
     * This site among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two sites' order 42 never meet.
     */
    public static function site_key()
    {
        return substr(hash('sha256', (string) home_url()), 0, 12);
    }

    /** The address Orboni's webhook goes to: Tutor's own, for this method. */
    public static function webhook_url()
    {
        return rest_url('tutor/v1/ecommerce-webhook/'.self::ID);
    }
}
