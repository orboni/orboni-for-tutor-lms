<?php

use Ollyo\PaymentHub\Contracts\Payment\ConfigContract;
use Ollyo\PaymentHub\Core\Payment\BaseConfig;

defined('ABSPATH') || exit;

/**
 * The method's settings as Tutor's payment library asks for them. Tutor
 * offers a method at checkout only while its config says it is configured;
 * the plugin itself reads {@see Orboni_Tutor_Settings}.
 */
class Orboni_Tutor_Config extends BaseConfig implements ConfigContract
{
    protected $name = Orboni_Tutor_Settings::ID;

    public function getMode(): string
    {
        return strpos(Orboni_Tutor_Settings::secret_key(), 'sk_test_') === 0 ? 'test' : 'live';
    }

    public function getTitle(): string
    {
        return __('Crypto', 'orboni-for-tutor-lms');
    }

    public function getWebhookUrl(): string
    {
        return Orboni_Tutor_Settings::webhook_url();
    }

    /** A secret key, and the store's currency one Orboni takes. */
    public function is_configured()
    {
        return Orboni_Tutor_Settings::is_configured();
    }
}
