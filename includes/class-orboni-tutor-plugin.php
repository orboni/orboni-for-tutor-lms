<?php

use Tutor\Ecommerce\CheckoutController;
use Tutor\Models\OrderModel;

defined('ABSPATH') || exit;

/**
 * The method in Tutor LMS: listed among its payment methods and settings,
 * offered at its checkout once configured, and the two addresses Orboni's
 * payment page sends the student back to.
 */
class Orboni_Tutor_Plugin
{
    /** The query argument of the return and back addresses. */
    public const ACTION_ARG = 'orboni-tutor';

    public static function register()
    {
        add_filter('tutor_payment_gateways_with_class', [self::class, 'add_gateway_class']);
        add_filter('tutor_payment_gateways', [self::class, 'add_gateway_settings']);
        add_filter('tutor_default_automate_payment_gateways', [self::class, 'add_automate_gateway']);
        add_filter('tutor_payment_method_labels', [self::class, 'add_label']);
        add_filter('tutor_order_placement_success_message', [self::class, 'success_message'], 10, 3);

        add_action('template_redirect', [self::class, 'on_return'], 1);
        add_action('admin_notices', [self::class, 'settings_notices']);
        // Before Tutor LMS Pro's own handler, which removes its gateways' plugins.
        add_action('wp_ajax_tutor_remove_payment_gateway', [self::class, 'remove_from_settings'], 1);

        Orboni_Tutor_Orders::register();
    }

    /**
     * @param  array<string, array<string, string>>  $gateways
     * @return array<string, array<string, string>>
     */
    public static function add_gateway_class($gateways)
    {
        $gateways[Orboni_Tutor_Settings::ID] = [
            'gateway_class' => Orboni_Tutor_Gateway::class,
            'config_class' => Orboni_Tutor_Config::class,
        ];

        return $gateways;
    }

    /**
     * The method in Tutor LMS → Settings → Payment Methods, with its fields.
     *
     * @param  list<array<string, mixed>>  $gateways
     * @return list<array<string, mixed>>
     */
    public static function add_gateway_settings($gateways)
    {
        $gateways[] = [
            'name' => Orboni_Tutor_Settings::ID,
            'label' => self::label(),
            'is_installed' => true,
            'is_plugin_active' => true,
            'is_active' => Orboni_Tutor_Settings::is_active(),
            'icon' => self::icon(),
            'support_subscription' => false,
            'fields' => Orboni_Tutor_Settings::form_fields(),
        ];

        return $gateways;
    }

    /**
     * @param  array<string, array<string, mixed>>  $gateways
     * @return array<string, array<string, mixed>>
     */
    public static function add_automate_gateway($gateways)
    {
        $gateways[Orboni_Tutor_Settings::ID] = [
            'label' => self::label(),
            'is_active' => Orboni_Tutor_Settings::is_active(),
            'icon' => self::icon(),
            'support_subscription' => false,
        ];

        return $gateways;
    }

    /**
     * Remove on the method's card in Tutor's settings: the card goes, and its
     * saved key and secrets with it, as Tutor's form then saves the list
     * without it; the plugin stays, and the card comes back, empty and
     * switched off, on the next visit. Tutor LMS answers Remove only for
     * its own gateways.
     */
    public static function remove_from_settings()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- which method, read before Tutor's own nonce check below.
        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        if ($slug !== Orboni_Tutor_Settings::ID) {
            return;
        }
        if (! tutor_utils()->is_nonce_verified() || ! current_user_can('manage_options')) {
            wp_send_json(['status_code' => 403, 'message' => __('You are not allowed to do this.', 'orboni-for-tutor-lms'), 'data' => null], 403);
        }

        wp_send_json(['status_code' => 200, 'message' => __('Crypto was removed from the payment methods, with its keys. Deactivate Orboni for Tutor LMS to take it away for good.', 'orboni-for-tutor-lms'), 'data' => null], 200);
    }

    /** @param  array<string, string>  $labels */
    public static function add_label($labels)
    {
        $labels[Orboni_Tutor_Settings::ID] = self::label();

        return $labels;
    }

    private static function label()
    {
        return __('Crypto (USDT, USDC, BTC and more)', 'orboni-for-tutor-lms');
    }

    private static function icon()
    {
        return esc_url_raw(ORBONI_TUTOR_URL.'assets/images/orboni.svg');
    }

    /** Where Orboni's payment page sends the student: `return` once paid, `cancel` by its back link. */
    public static function return_url($order_id, $action)
    {
        return add_query_arg([
            self::ACTION_ARG => $action,
            'order' => (int) $order_id,
            'key' => Orboni_Tutor_Orders::token($order_id),
        ], home_url('/'));
    }

    /**
     * The student back from the payment page. The session is read and
     * applied first, so the order is right even before the webhook comes, or
     * without one; then: paid or on its way → Tutor's "Order Confirmed"
     * page; still open → the same payment page; ran out → Tutor's "Payment
     * failed" page, saying the order can be paid again. By the page's back
     * link the page is closed first, and an unpaid order goes back to
     * Tutor's checkout for it, to pay again or another way.
     */
    public static function on_return()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link from Orboni's payment page; the order's own random key checks it.
        if (! isset($_GET[self::ACTION_ARG], $_GET['order'], $_GET['key'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_GET[self::ACTION_ARG]));
        $order_id = absint(wp_unslash($_GET['order']));
        $key = sanitize_text_field(wp_unslash($_GET['key']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (! in_array($action, ['return', 'cancel'], true)) {
            return;
        }

        nocache_headers();
        $order = Orboni_Tutor_Orders::get($order_id);
        $token = $order ? (string) Orboni_Tutor_Orders::meta($order_id, Orboni_Tutor_Orders::META_TOKEN) : '';
        if (! $order || $token === '' || ! hash_equals($token, $key)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $id = (string) Orboni_Tutor_Orders::meta($order_id, Orboni_Tutor_Orders::META_SESSION);
        $secret = Orboni_Tutor_Settings::secret_key();
        $session = null;
        if ($id !== '' && $secret !== '' && $order->payment_status !== OrderModel::PAYMENT_PAID) {
            $api = new Orboni_Tutor_Api($secret);
            $session = $action === 'cancel' ? $api->expire_session($id) : $api->get_session($id);
            // Closed already (it ran out, or was paid): read as it is.
            if ($action === 'cancel' && is_wp_error($session)) {
                $session = $api->get_session($id);
            }
            if (is_wp_error($session)) {
                Orboni_Tutor_Log::error(sprintf('Order %d: could not read session %s on the student\'s return: %s', $order_id, $id, $session->get_error_message()));
                $session = null;
            } else {
                Orboni_Tutor_Orders::apply_locked($order_id, $session);
                if ($action === 'cancel' && ($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') === 'unpaid') {
                    Orboni_Tutor_Orders::note($order_id, __('The student left the Orboni payment page by its back link; the page was closed.', 'orboni-for-tutor-lms'));
                }
            }
            $order = Orboni_Tutor_Orders::get($order_id);
        }

        if (in_array($order->payment_status, [OrderModel::PAYMENT_PAID, OrderModel::PAYMENT_PENDING], true)) {
            self::to_placement(OrderModel::ORDER_PLACEMENT_SUCCESS, $order_id);
        }

        if ($action === 'return' && is_array($session) && Orboni_Tutor_Payment::reusable($session)) {
            $host = (string) wp_parse_url($session['url'], PHP_URL_HOST);
            add_filter('allowed_redirect_hosts', static function ($hosts) use ($host) {
                $hosts[] = $host;

                return $hosts;
            });
            wp_safe_redirect($session['url'], 303);
            exit;
        }

        if ($action === 'cancel' && is_user_logged_in() && OrderModel::get_valid_incomplete_order($order_id, get_current_user_id())) {
            wp_safe_redirect(add_query_arg('order_id', $order_id, CheckoutController::get_page_url()), 303);
            exit;
        }

        self::to_placement(OrderModel::ORDER_PLACEMENT_FAILED, $order_id, $action === 'cancel'
            ? __('The crypto payment was cancelled. The order stays unpaid: pay it from your order list, with crypto or another way.', 'orboni-for-tutor-lms')
            : __('The payment page closed before a payment arrived. The order stays unpaid: pay it again from your order list.', 'orboni-for-tutor-lms'));
    }

    /** Tutor's own "Order Confirmed" or "Payment failed" page for the order. */
    private static function to_placement($status, $order_id, $message = '')
    {
        $args = ['tutor_order_placement' => $status, 'order_id' => (int) $order_id];
        if ($message !== '') {
            $args['error_message'] = rawurlencode($message);
        }
        wp_safe_redirect(add_query_arg($args, home_url('/')), 303);
        exit;
    }

    /**
     * The line under "Order Confirmed" while an Orboni payment is not final:
     * Tutor's own says an email is on its way.
     */
    public static function success_message($message, $order_id, $order_status = null)
    {
        $order = Orboni_Tutor_Orders::get((int) $order_id);
        if (! $order || $order->payment_method !== Orboni_Tutor_Settings::ID || $order->payment_status !== OrderModel::PAYMENT_PENDING || ! self::is_students($order)) {
            return $message;
        }

        return __('Your payment is on its way. The order will be confirmed as soon as the network confirms the transfer, and your course will open then; you can close this page.', 'orboni-for-tutor-lms');
    }

    private static function is_students($order)
    {
        return is_user_logged_in() && get_current_user_id() === (int) $order->user_id;
    }

    /** On Tutor's payment settings: what keeps crypto from the checkout, and test mode. */
    public static function settings_notices()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- which settings screen is open, read only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab_page']) ? sanitize_key(wp_unslash($_GET['tab_page'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($page !== 'tutor_settings' || $tab !== 'ecommerce_payment' || ! current_user_can('manage_options')) {
            return;
        }

        $notices = [];
        $currency = Orboni_Tutor_Settings::currency();
        if (! Orboni_Tutor_Settings::supports_currency($currency)) {
            $notices[] = ['warning', sprintf(
                /* translators: 1: the store's currency code, e.g. ARS, 2: the codes Orboni takes, comma-separated */
                __('Orboni does not take prices in %1$s, this site\'s currency: crypto is not offered at checkout. It takes %2$s.', 'orboni-for-tutor-lms'),
                $currency,
                implode(', ', array_keys(Orboni_Tutor_Settings::CURRENCIES))
            )];
        }

        $key = Orboni_Tutor_Settings::saved_secret_key();
        if ($key !== '' && Orboni_Tutor_Settings::secret_key() === '') {
            $notices[] = ['error', __('The Crypto method\'s secret key is not one: it starts with sk_live_ or sk_test_ (a publishable key, pk_…, will not work). Crypto is not offered at checkout until it is.', 'orboni-for-tutor-lms')];
        }
        $webhook = Orboni_Tutor_Settings::saved_webhook_secret();
        if ($webhook !== '' && Orboni_Tutor_Settings::webhook_secret() === '') {
            $notices[] = ['error', __('The Crypto method\'s webhook signing secret starts with whsec_: until it does, webhooks are refused, and orders are marked paid only when the student comes back or by a check a few minutes later.', 'orboni-for-tutor-lms')];
        }
        if (get_option('permalink_structure') === '') {
            $notices[] = ['warning', sprintf(
                /* translators: %s: this site's webhook address */
                __('This site uses plain permalinks, so the Webhook URL Tutor shows for Crypto does not answer. Add this address in the Orboni dashboard instead: %s', 'orboni-for-tutor-lms'),
                Orboni_Tutor_Settings::webhook_url()
            )];
        }
        if (strpos(Orboni_Tutor_Settings::secret_key(), 'sk_test_') === 0) {
            $notices[] = ['info', __('Crypto is in test mode: a test key makes test payments only. Open the payment page while signed in to your Orboni dashboard to simulate one.', 'orboni-for-tutor-lms')];
        }

        foreach ($notices as [$type, $text]) {
            echo '<div class="notice notice-'.esc_attr($type).'"><p>'.esc_html($text).'</p></div>';
        }
    }
}
