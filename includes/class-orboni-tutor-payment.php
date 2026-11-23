<?php

use Ollyo\PaymentHub\Core\Payment\BasePayment;
use Tutor\Models\OrderModel;

defined('ABSPATH') || exit;

/**
 * The payment Tutor's checkout asks the gateway for. `createPayment()` sends
 * the student to Orboni's payment page for the order (a session made, or the
 * open one reused); `verifyAndCreateOrderData()` is Orboni's webhook at
 * Tutor's own address for the method, `/wp-json/tutor/v1/ecommerce-webhook/orboni`.
 */
class Orboni_Tutor_Payment extends BasePayment
{
    /**
     * Time a payment page must still have to be reused when Pay is pressed
     * again: less, and the student gets a new one. A page Orboni still calls
     * open may be past its time (held open while a network cannot be read).
     */
    public const REUSE_MIN_SECONDS = 300;

    public function setup(): void {}

    /**
     * Tutor's payment library refuses to build a payment whose check fails,
     * with an exception the webhook address would answer as a crash: the
     * checks are made where a refusal can be said ({@see createPayment()},
     * the webhook), and Tutor offers the method only while its config says
     * it is configured.
     */
    public function check(): bool
    {
        return true;
    }

    /**
     * To Orboni's payment page, or an exception whose message Tutor shows on
     * its "Payment failed" page.
     *
     * @throws Exception
     */
    public function createPayment()
    {
        $data = is_object($this->data) ? $this->data : (object) $this->data;
        $order = Orboni_Tutor_Orders::get((int) ($data->order_id ?? 0));
        $currency = Orboni_Tutor_Settings::currency();

        if (! $order) {
            throw new Exception(esc_html(self::unavailable()));
        }
        if (! Orboni_Tutor_Settings::supports_currency($currency)) {
            throw new Exception(esc_html(sprintf(
                /* translators: %s: the store's currency code, e.g. ARS */
                __('Crypto payments are not taken in %s.', 'orboni-for-tutor-lms'),
                $currency
            )));
        }
        if (Orboni_Tutor_Settings::secret_key() === '') {
            throw new Exception(esc_html(self::unavailable()));
        }
        if ($order->payment_status !== OrderModel::PAYMENT_UNPAID) {
            // Paid meanwhile on an earlier page, or with money on its way: not a second page.
            throw new Exception(esc_html__('A payment for this order has already arrived or is on its way. Check the order in your order list.', 'orboni-for-tutor-lms'));
        }

        $amount = Orboni_Tutor_Settings::minor((float) $order->total_price, $currency);
        if ($currency === 'USD' && $amount < Orboni_Tutor_Settings::MIN_CENTS) {
            throw new Exception(esc_html__('This order is below the minimum for a crypto payment ($1).', 'orboni-for-tutor-lms'));
        }

        $url = $this->session_url($order, $data, $amount, $currency);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        add_filter('allowed_redirect_hosts', static function ($hosts) use ($host) {
            $hosts[] = $host;

            return $hosts;
        });

        wp_safe_redirect($url, 303);
        exit;
    }

    /**
     * The payment page for the order: the open session for its amount, or a
     * new one.
     *
     * @throws Exception
     */
    private function session_url($order, $data, $amount, $currency)
    {
        $order_id = (int) $order->id;
        $api = new Orboni_Tutor_Api(Orboni_Tutor_Settings::secret_key());
        $priced = $amount.strtolower($currency);

        // Pay pressed again for the same amount: the session still open is reused,
        // unless it has run out or is about to (REUSE_MIN_SECONDS).
        $existing = (string) Orboni_Tutor_Orders::meta($order_id, Orboni_Tutor_Orders::META_SESSION);
        if ($existing !== '' && (string) Orboni_Tutor_Orders::meta($order_id, Orboni_Tutor_Orders::META_AMOUNT) === $priced) {
            $session = $api->get_session($existing);
            if (self::reusable($session)) {
                Orboni_Tutor_Log::info(sprintf('Order %d: session %s reused.', $order_id, $existing));

                return $session['url'];
            }
        }

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) Orboni_Tutor_Orders::meta($order_id, Orboni_Tutor_Orders::META_ATTEMPT) + 1;
        $body = self::session_body($order, $data, $amount, $currency);
        $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));

        // That lost try was sent with other details (the buyer's language, a
        // changed title): Orboni refuses the key for them, and a new one is taken.
        if (is_wp_error($session) && self::error_type($session) === 'idempotency_error' && (int) (($session->get_error_data())['status'] ?? 0) === 400) {
            $attempt++;
            $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));
        }

        if (is_wp_error($session)) {
            $type = self::error_type($session);
            Orboni_Tutor_Log::error(sprintf('Order %d: Orboni refused a session (%s): %s', $order_id, $type, $session->get_error_message()));
            Orboni_Tutor_Orders::note($order_id, sprintf(
                /* translators: %s: Orboni's error, e.g. "authentication_error: Invalid API key." */
                __('Orboni did not open a payment page: %s', 'orboni-for-tutor-lms'),
                $type.': '.$session->get_error_message()
            ));

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            $param = is_array($session->get_error_data()) ? (string) (($session->get_error_data())['param'] ?? '') : '';
            if ($type === 'amount_too_small' || ($type === 'invalid_request_error' && $param === 'amount')) {
                throw new Exception(esc_html__('This order is below the minimum amount for a crypto payment.', 'orboni-for-tutor-lms'));
            }

            throw new Exception(esc_html(self::unavailable()));
        }

        if (! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            Orboni_Tutor_Log::error(sprintf('Order %d: Orboni answered without a session id or url.', $order_id));

            throw new Exception(esc_html(self::unavailable()));
        }

        $known = Orboni_Tutor_Orders::sessions($order_id);
        $known[] = $session['id'];
        $expires = (int) ($session['expires_at'] ?? 0) ?: time() + HOUR_IN_SECONDS;

        Orboni_Tutor_Orders::set_meta($order_id, Orboni_Tutor_Orders::META_SESSION, $session['id']);
        Orboni_Tutor_Orders::set_meta($order_id, Orboni_Tutor_Orders::META_SESSIONS, array_values(array_unique($known)));
        Orboni_Tutor_Orders::set_meta($order_id, Orboni_Tutor_Orders::META_AMOUNT, $priced);
        Orboni_Tutor_Orders::set_meta($order_id, Orboni_Tutor_Orders::META_ATTEMPT, $attempt);
        Orboni_Tutor_Orders::set_meta($order_id, Orboni_Tutor_Orders::META_EXPIRES, $expires);
        Orboni_Tutor_Orders::note($order_id, sprintf(
            /* translators: 1: Orboni checkout session id, 2: the amount, with its currency */
            __('Student sent to the Orboni payment page (session %1$s, %2$s).', 'orboni-for-tutor-lms'),
            $session['id'],
            Orboni_Tutor_Settings::format_minor($amount, $currency)
        ));
        Orboni_Tutor_Orders::schedule($order_id, $expires);

        Orboni_Tutor_Log::info(sprintf('Order %d: session %s for %s.', $order_id, $session['id'], Orboni_Tutor_Settings::format_minor($amount, $currency)));

        return $session['url'];
    }

    /**
     * A session the student can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  mixed  $session
     */
    public static function reusable($session)
    {
        return is_array($session)
            && ($session['status'] ?? '') === 'open'
            && is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    /**
     * A refund made in Tutor's admin (Orders → the order → Refund). Tutor
     * records it and changes the order itself; the money is in the
     * merchant's own wallet, so nothing is sent from here: the order's
     * history says where the refund is made. Without this Tutor would call
     * the refund "pending payment gateway issuance".
     */
    public function createRefund()
    {
        $data = is_object($this->data) ? $this->data : (object) $this->data;
        $order_id = (int) ($data->order_id ?? 0);
        if ($order_id <= 0) {
            return;
        }

        Orboni_Tutor_Orders::note($order_id, sprintf(
            /* translators: %s: the refunded amount with its currency, e.g. "24.00 USD" */
            __('Refunded %s in Tutor LMS. Orboni does not send refunds: send the money back to the student from your own wallet, and record the refund in the Orboni dashboard.', 'orboni-for-tutor-lms'),
            number_format((float) ($data->amount ?? 0), Orboni_Tutor_Settings::decimals(Orboni_Tutor_Settings::currency()), '.', '').' '.Orboni_Tutor_Settings::currency()
        ));
    }

    /**
     * Orboni's webhook, which Tutor hands over as it came: the signature
     * checked, the event matched to its order, and the session read back
     * before anything changes — the order follows the session as it is now,
     * never an old event's copy of it. Answered here, so Orboni hears 401,
     * 400 or 503 where they apply: 200 for an event taken or one that is not
     * this site's (Orboni sends every event of a project to each of its
     * endpoints, and a non-2xx would be sent again for days); 401 for a bad
     * signature; 400 for a body that is no event; 503 while the site cannot
     * decide yet — Orboni retries it later.
     *
     * @param  object  $payload  {get, post, server, stream}
     */
    public function verifyAndCreateOrderData(object $payload): object
    {
        $secret = Orboni_Tutor_Settings::webhook_secret();
        $key = Orboni_Tutor_Settings::secret_key();
        if ($secret === '' || $key === '') {
            self::answer(503, 'not_configured');
        }

        $body = is_string($payload->stream ?? null) ? $payload->stream : '';
        $server = is_array($payload->server ?? null) ? $payload->server : [];
        $header = isset($server['HTTP_WEBHOOK_SIGNATURE']) ? (string) $server['HTTP_WEBHOOK_SIGNATURE'] : '';
        if (! Orboni_Tutor_Signature::verify($secret, $body, $header)) {
            Orboni_Tutor_Log::error('Webhook refused: the signature does not match this site\'s signing secret.');
            self::answer(401, 'bad_signature');
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            self::answer(400, 'bad_event');
        }

        $type = $event['type'];
        $object = $event['data']['object'];
        if (strpos($type, 'checkout.session.') !== 0) {
            self::answer(200, 'ignored');
        }

        $id = (string) ($object['id'] ?? '');
        $reference = (string) ($object['client_reference_id'] ?? '');
        $site = (string) (((array) ($object['metadata'] ?? []))['tutor_site'] ?? '');
        if (preg_match(Orboni_Tutor_Api::SESSION_ID, $id) !== 1 || ! ctype_digit($reference) || $site !== Orboni_Tutor_Settings::site_key()) {
            // Another site's, or a payment link's: not ours to act on.
            self::answer(200, 'ignored');
        }

        $order = Orboni_Tutor_Orders::get((int) $reference);
        if (! $order || ! in_array($id, Orboni_Tutor_Orders::sessions($order->id), true)) {
            Orboni_Tutor_Log::info(sprintf('Webhook %s for session %s: no order of this site has it.', $type, $id));
            self::answer(200, 'ignored');
        }

        $session = (new Orboni_Tutor_Api($key))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_Tutor_Log::error(sprintf('Webhook %s, order %d: could not read session %s back: %s', $type, $order->id, $id, $session->get_error_message()));
            self::answer(503, 'retry_later');
        }

        $taken = Orboni_Tutor_Orders::apply_locked($order->id, $session, $type, (string) ($event['id'] ?? ''));
        if ($taken === null) {
            self::answer(503, 'busy');
        }

        Orboni_Tutor_Log::info(sprintf('Webhook %s, order %d: %s.', $type, $order->id, $taken ? 'applied' : 'already taken'));
        self::answer(200, $taken ? 'received' : 'duplicate');
    }

    /**
     * The webhook's answer, sent and ended here: Tutor's address answers
     * nothing of its own.
     *
     * @return never
     */
    private static function answer($code, $result)
    {
        wp_send_json(['result' => $result], $code);
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function session_body($order, $data, $amount, $currency)
    {
        $user = get_userdata((int) $order->user_id);
        $billing = is_object($data->billing_address ?? null) ? $data->billing_address : null;
        $email = $billing && is_email((string) ($billing->email ?? '')) ? (string) $billing->email : ($user ? (string) $user->user_email : '');
        $locale = $user ? get_user_locale($user) : determine_locale();

        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit(self::items($order), 250),
            'details' => self::fit(sprintf(
                /* translators: 1: order number, 2: site name */
                __('Order #%1$s at %2$s', 'orboni-for-tutor-lms'),
                (string) $order->id,
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
            ), 500),
            'client_reference_id' => (string) $order->id,
            'customer_email' => is_email($email) ? $email : null,
            'locale' => str_replace('_', '-', (string) $locale),
            'success_url' => Orboni_Tutor_Plugin::return_url($order->id, 'return'),
            'cancel_url' => Orboni_Tutor_Plugin::return_url($order->id, 'cancel'),
            'metadata' => [
                'tutor_order_id' => (string) $order->id,
                'tutor_site' => Orboni_Tutor_Settings::site_key(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /** "Photography basics, Watercolour for beginners" — what the payment page shows as the purchase. */
    private static function items($order)
    {
        $names = [];
        foreach ((array) (new OrderModel)->get_order_items_by_id((int) $order->id) as $item) {
            $id = (int) ($item->id ?? 0);
            $name = $id ? get_the_title($id) : '';
            $name = trim(wp_strip_all_tags(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')), " \t\n\r\0\x0B");
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names ? implode(', ', $names) : sprintf(
            /* translators: %s: order number */
            __('Order #%s', 'orboni-for-tutor-lms'),
            (string) $order->id
        );
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit($text, $max)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text), " \t\n\r\0\x0B");
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    /**
     * The site, the order, the amount and the try. The order's creation time
     * is in it too: a site restored from a backup numbers its orders again,
     * and Orboni keeps a key a day.
     */
    private static function idempotency_key($order, $amount, $currency, $attempt)
    {
        $instance = substr(hash('sha256', (string) $order->created_at_gmt.'|'.(string) $order->user_id), 0, 8);

        return sprintf('tutor-%s-%d-%s-%d%s-%d', Orboni_Tutor_Settings::site_key(), (int) $order->id, $instance, $amount, strtolower($currency), $attempt);
    }

    private static function error_type(WP_Error $error)
    {
        $data = $error->get_error_data();

        return is_array($data) ? (string) ($data['type'] ?? '') : '';
    }

    public static function unavailable()
    {
        return __('Crypto payment is not available right now. Please try again in a minute or choose another payment method.', 'orboni-for-tutor-lms');
    }
}
