<?php

// Deleted from the Plugins screen: the method leaves Tutor's payment settings
// (the secret key with it). Orders keep their history lines and Orboni's
// session ids, which are the site's records.
defined('WP_UNINSTALL_PLUGIN') || exit;

$orboni_tutor_options = get_option('tutor_option');
if (is_array($orboni_tutor_options) && isset($orboni_tutor_options['payment_settings']) && is_string($orboni_tutor_options['payment_settings'])) {
    $orboni_tutor_payments = json_decode($orboni_tutor_options['payment_settings'], true);
    if (is_array($orboni_tutor_payments) && isset($orboni_tutor_payments['payment_methods']) && is_array($orboni_tutor_payments['payment_methods'])) {
        $orboni_tutor_payments['payment_methods'] = array_values(array_filter($orboni_tutor_payments['payment_methods'], static function ($method) {
            return ! is_array($method) || ($method['name'] ?? '') !== 'orboni';
        }));
        $orboni_tutor_options['payment_settings'] = wp_json_encode($orboni_tutor_payments);
        update_option('tutor_option', $orboni_tutor_options);
    }
}

wp_unschedule_hook('orboni_tutor_check_order');
