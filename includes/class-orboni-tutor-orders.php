<?php

use Tutor\Models\OrderActivitiesModel;
use Tutor\Models\OrderMetaModel;
use Tutor\Models\OrderModel;

defined('ABSPATH') || exit;

/**
 * An Orboni checkout session, read back, onto its Tutor LMS order. The one
 * place an order changes for a payment: the webhook, the buyer's return, the
 * page's back link and the scheduled check all come here with the session as
 * Orboni shows it now, so the order follows the payment whatever arrives
 * first, twice or out of order.
 *
 * - complete + paid → Paid (Tutor completes the order and enrols the student);
 * - on its way (processing), short of the amount (partially_paid) or held
 *   for the merchant's review (held) → payment Pending, with what happened;
 * - expired with nothing paid → a line; the order stays unpaid, so the
 *   student can pay it again from the order list;
 * - a paid order goes back to Pending only when Orboni says money that paid
 *   it left the chain (`payment_reversed`); nothing else downgrades it.
 *
 * Every line goes into the order's history in Tutor's admin; the student
 * hears only Tutor's own emails.
 */
class Orboni_Tutor_Orders
{
    /** The session the order is paid on now. */
    public const META_SESSION = 'orboni_session_id';

    /** Every session made for the order: a buyer may still pay an older one. */
    public const META_SESSIONS = 'orboni_session_ids';

    /** The amount the current session asks, in the currency's smallest unit, and its currency. */
    public const META_AMOUNT = 'orboni_amount';

    public const META_ATTEMPT = 'orboni_attempt';

    public const META_EXPIRES = 'orboni_expires_at';

    /** What was last applied, "id:status:payment_status": the same state twice adds no second line. */
    public const META_STATE = 'orboni_state';

    /** The webhook events already taken, so a retry or a replay adds nothing twice. */
    public const META_EVENTS = 'orboni_events';

    /** The random key the return and back addresses carry. */
    public const META_TOKEN = 'orboni_token';

    public const EVENTS_KEPT = 30;

    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** The scheduled read-back (WP-Cron). */
    public const CHECK_HOOK = 'orboni_tutor_check_order';

    /** Minutes after the session is made, then after it expires, that it is read back. */
    private const CHECK_AFTER_CREATED = [10];

    private const CHECK_AFTER_EXPIRY = [2, 30, 65];

    /** Seconds a lock left by a request that died is waited out. */
    private const LOCK_SECONDS = 60;

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    private const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    public static function register()
    {
        add_action(self::CHECK_HOOK, [self::class, 'check']);
    }

    /** The order's own row, fresh from the database: id, user_id, order_status, payment_status, total_price… */
    public static function get($order_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tutor's own orders table, read fresh under the order's lock.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tutor_orders WHERE id = %d", (int) $order_id));

        return $row ?: null;
    }

    /** @return mixed the meta's value, or null */
    public static function meta($order_id, $key)
    {
        $row = OrderMetaModel::get_meta((int) $order_id, $key);

        return $row ? maybe_unserialize($row->meta_value) : null;
    }

    public static function set_meta($order_id, $key, $value)
    {
        OrderMetaModel::update_meta((int) $order_id, $key, $value);
    }

    /** @return list<string> */
    public static function sessions($order_id)
    {
        $known = self::meta($order_id, self::META_SESSIONS);

        return is_array($known) ? array_values(array_filter($known, 'is_string')) : [];
    }

    /** The order's random key for its return and back addresses, made the first time it is asked. */
    public static function token($order_id)
    {
        $token = (string) self::meta($order_id, self::META_TOKEN);
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            self::set_meta($order_id, self::META_TOKEN, $token);
        }

        return $token;
    }

    /** A line in the order's history, which Tutor's admin shows on the order. */
    public static function note($order_id, $message)
    {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a row of Tutor's own order history table, not a WordPress meta query.
        (new OrderActivitiesModel)->add_order_meta((object) [
            'order_id' => (int) $order_id,
            'meta_key' => OrderActivitiesModel::META_KEY_HISTORY,
            'meta_value' => $message,
        ]);
        // phpcs:enable
    }

    /**
     * {@see apply()} with the order loaded fresh under a lock: the webhook
     * and the buyer's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken, null the order is busy
     */
    public static function apply_locked($order_id, array $session, $event = '', $event_id = '')
    {
        $order_id = (int) $order_id;
        $lock = 'orboni_tutor_lock_'.$order_id;
        // add_option() inserts or fails: one request at a time holds it.
        if (! add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) > time() - self::LOCK_SECONDS) {
                return null;
            }
            update_option($lock, time(), false);
        }

        try {
            $order = self::get($order_id);
            if (! $order) {
                return false;
            }

            if ($event_id !== '') {
                $taken = self::meta($order_id, self::META_EVENTS);
                $taken = is_array($taken) ? $taken : [];
                if (in_array($event_id, $taken, true)) {
                    return false;
                }
                $taken[] = $event_id;
                self::set_meta($order_id, self::META_EVENTS, array_slice($taken, -self::EVENTS_KEPT));
            }

            self::apply($order, $session, $event);

            return true;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Read-backs for a new session: the order is right even when no webhook
     * is set up and the buyer never comes back.
     */
    public static function schedule($order_id, $expires_at)
    {
        $order_id = (int) $order_id;
        $times = [];
        foreach (self::CHECK_AFTER_CREATED as $minutes) {
            $times[] = time() + $minutes * MINUTE_IN_SECONDS;
        }
        foreach (self::CHECK_AFTER_EXPIRY as $minutes) {
            $times[] = (int) $expires_at + $minutes * MINUTE_IN_SECONDS;
        }

        wp_clear_scheduled_hook(self::CHECK_HOOK, [$order_id]);
        foreach (array_unique($times) as $time) {
            wp_schedule_single_event($time, self::CHECK_HOOK, [$order_id]);
        }
    }

    /** A scheduled read-back. */
    public static function check($order_id)
    {
        $order = self::get((int) $order_id);
        $secret = Orboni_Tutor_Settings::secret_key();
        if (! $order || $secret === '' || $order->payment_status === OrderModel::PAYMENT_PAID) {
            return;
        }

        $id = (string) self::meta($order->id, self::META_SESSION);
        if ($id === '') {
            return;
        }

        $session = (new Orboni_Tutor_Api($secret))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_Tutor_Log::error(sprintf('Order %d: scheduled check could not read session %s: %s', $order->id, $id, $session->get_error_message()));

            return;
        }

        self::apply_locked($order->id, $session);
    }

    /**
     * This order's session: made for it by this site. The webhook checks the
     * same before reading anything back, and a session read back is checked
     * again here.
     *
     * @param  array<string, mixed>  $session
     */
    public static function belongs($order, array $session)
    {
        $id = (string) ($session['id'] ?? '');

        return $id !== ''
            && in_array($id, self::sessions($order->id), true)
            && (string) ($session['client_reference_id'] ?? '') === (string) $order->id
            && (string) (((array) ($session['metadata'] ?? []))['tutor_site'] ?? '') === Orboni_Tutor_Settings::site_key();
    }

    /**
     * @param  object  $order  the order's row
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private static function apply($order, array $session, $event = '')
    {
        $order_id = (int) $order->id;
        $id = (string) ($session['id'] ?? '');
        if (! self::belongs($order, $session)) {
            Orboni_Tutor_Log::error(sprintf('Order %d: session %s is not this order\'s; left alone.', $order_id, $id));

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $state = $id.':'.$status.':'.$payment;
        $paid_here = $status === 'complete' && $payment === 'paid';

        if ($order->payment_status === OrderModel::PAYMENT_PAID || in_array($order->payment_status, [OrderModel::PAYMENT_REFUNDED, OrderModel::PAYMENT_PARTIALLY_REFUNDED], true)) {
            self::after_paid($order, $session, $event, $paid_here, $state);

            return;
        }

        if ((string) self::meta($order_id, self::META_STATE) === $state) {
            return;
        }
        self::set_meta($order_id, self::META_STATE, $state);

        // Cancelled in the admin (or trashed) before the money came: not brought back by itself.
        if (in_array($order->order_status, [OrderModel::ORDER_CANCELLED, OrderModel::ORDER_TRASH], true)) {
            if ($paid_here || in_array($payment, ['processing', 'partially_paid', 'held'], true)) {
                self::note($order_id, sprintf(
                    /* translators: 1: Orboni session id, 2: the amount received, with its currency */
                    __('A payment arrived for this cancelled order on Orboni session %1$s (%2$s received). The order was left cancelled: restore it, or send the money back from your wallet.', 'orboni-for-tutor-lms'),
                    $id,
                    self::money($session, 'amount_received')
                ));
                Orboni_Tutor_Log::error(sprintf('Order %d: paid on session %s after it was cancelled.', $order_id, $id));
            }

            return;
        }

        if ($paid_here) {
            self::complete($order, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                self::pending($order, $session, __('A payment is on the chain and waiting for confirmations. The order will be marked paid by itself once it is final.', 'orboni-for-tutor-lms'));

                return;

            case 'partially_paid':
                self::pending($order, $session, $status === 'expired'
                    ? sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('The payment page closed partly paid: %1$s of %2$s arrived in your wallet. Settle it with the student (send the rest back or ask for the difference); Orboni will not complete this order.', 'orboni-for-tutor-lms'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    )
                    : sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('Partly paid so far: %1$s of %2$s. The payment page asks the student for the rest.', 'orboni-for-tutor-lms'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    ));

                return;

            case 'held':
                self::pending($order, $session, sprintf(
                    /* translators: %s: why Orboni held the payment, e.g. "late" or "overpaid" */
                    __('The money is in your wallet but held for your review in the Orboni dashboard (reason: %s). Accept it there and this order is marked paid.', 'orboni-for-tutor-lms'),
                    (string) ($session['review_reason'] ?? '—')
                ));

                return;
        }

        if ($status === 'expired' && $payment === 'unpaid' && (string) self::meta($order_id, self::META_SESSION) === $id) {
            // A payment seen on its way and then gone (dropped from the chain): unpaid again.
            if ($order->payment_status === OrderModel::PAYMENT_PENDING) {
                self::set_status($order, OrderModel::ORDER_INCOMPLETE, OrderModel::PAYMENT_UNPAID);
            }
            self::note($order_id, __('The Orboni payment page expired with nothing paid. The order stays unpaid: the student can pay it again from their order list.', 'orboni-for-tutor-lms'));
        }
    }

    /**
     * Paid in full: Tutor's own payment update, as its gateways' webhooks
     * make it, completes the order and enrols the student.
     *
     * @param  array<string, mixed>  $session
     */
    private static function complete($order, array $session)
    {
        $currency = Orboni_Tutor_Settings::currency();
        $asked = (int) ($session['amount_total'] ?? 0);
        $asked_in = strtoupper((string) ($session['currency'] ?? ''));
        $due = Orboni_Tutor_Settings::minor((float) $order->total_price, $currency);

        // The order costs more than this page asked (its tax recalculated, the store's currency changed).
        if ($asked_in !== $currency || $asked < $due) {
            self::pending($order, $session, sprintf(
                /* translators: 1: the amount the session asked, with its currency, 2: the order's total, with its currency */
                __('Orboni completed a payment of %1$s, but this order totals %2$s. Check it before marking the order paid.', 'orboni-for-tutor-lms'),
                Orboni_Tutor_Settings::format_minor($asked, $asked_in),
                Orboni_Tutor_Settings::format_minor($due, $currency)
            ));

            return;
        }

        self::note($order->id, self::paid_note($session));
        self::payment_updated($order, $session, OrderModel::PAYMENT_PAID);
        Orboni_Tutor_Log::info(sprintf('Order %d: paid by session %s.', $order->id, $session['id']));
    }

    /**
     * A line, and the payment Pending: no Pay button for the student while
     * money is on its way or with the merchant.
     *
     * @param  array<string, mixed>  $session
     */
    private static function pending($order, array $session, $note)
    {
        self::note($order->id, $note);
        if ($order->payment_status !== OrderModel::PAYMENT_PENDING) {
            self::payment_updated($order, $session, OrderModel::PAYMENT_PENDING);
        }
    }

    /**
     * Tutor's `tutor_order_payment_updated`, the action its own webhook
     * fires: the order's statuses, its transaction and payload, then the
     * student's enrolment and the earnings.
     *
     * @param  array<string, mixed>  $session
     */
    private static function payment_updated($order, array $session, $payment_status)
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tutor LMS's own action, fired as its gateways' webhooks fire it.
        do_action('tutor_order_payment_updated', (object) [
            'id' => (int) $order->id,
            'payment_status' => $payment_status,
            'transaction_id' => (string) $session['id'],
            'payment_method' => Orboni_Tutor_Settings::ID,
            'payment_payload' => self::payload($session),
            'earnings' => null,
            'fees' => null,
        ]);
    }

    /**
     * Statuses set directly, for what Tutor's payment update leaves alone: a
     * paid order (it ignores those, so a late failure never unenrols a
     * student) and an order back to unpaid.
     */
    private static function set_status($order, $order_status, $payment_status)
    {
        $model = new OrderModel;
        $updated = $model->update_order((int) $order->id, [
            'order_status' => $order_status,
            'payment_status' => $payment_status,
            'updated_at_gmt' => current_time('mysql', true),
        ]);
        if ($updated) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tutor LMS's own action, fired as its admin fires it after a status change.
            do_action('tutor_order_payment_status_changed', (int) $order->id, $order->payment_status, $payment_status);
        }
    }

    /** @param  array<string, mixed>  $session */
    private static function after_paid($order, array $session, $event, $paid_here, $state)
    {
        $id = (string) $session['id'];

        if ((string) $order->transaction_id === $id) {
            if ($event === self::REVERSED && ! $paid_here && $order->payment_status === OrderModel::PAYMENT_PAID) {
                // Recorded, so the session paid in full again marks the order paid again.
                self::set_meta($order->id, self::META_STATE, $state);
                self::note($order->id, sprintf(
                    /* translators: %s: the amount still counted as received, with its currency */
                    __('Orboni reports that money which paid this order left the chain (a reorganisation or a double spend); %s still counts as received. The enrolment is paused until the Orboni dashboard shows it paid again.', 'orboni-for-tutor-lms'),
                    self::money($session, 'amount_received')
                ));
                self::set_status($order, OrderModel::ORDER_PENDING, OrderModel::PAYMENT_PENDING);
                Orboni_Tutor_Log::error(sprintf('Order %d: payment reversed on session %s.', $order->id, $id));
            } elseif ($event === self::EXTRA) {
                self::note($order->id, sprintf(
                    /* translators: %s: the amount received in all, with its currency */
                    __('More money arrived after this order was paid: %s received in all. Orboni does not complete an order twice; send the difference back from your wallet if the student asks.', 'orboni-for-tutor-lms'),
                    self::money($session, 'amount_received')
                ));
            }

            return;
        }

        // Another of the order's sessions paid too, or the order was marked paid by hand.
        if ($paid_here && (string) self::meta($order->id, self::META_STATE) !== $state) {
            self::set_meta($order->id, self::META_STATE, $state);
            self::note($order->id, sprintf(
                /* translators: 1: Orboni session id, 2: the amount received, with its currency */
                __('This order was already paid, and another payment page (%1$s) was paid as well: %2$s. Send it back to the student from your wallet.', 'orboni-for-tutor-lms'),
                $id,
                self::money($session, 'amount_received')
            ));
            Orboni_Tutor_Log::error(sprintf('Order %d: paid twice, again by session %s.', $order->id, $id));
        }
    }

    /** "Paid with 25.00 USDT on TRON (25.00 USD) through Orboni, session cs_live_…." @param  array<string, mixed>  $session */
    private static function paid_note(array $session)
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.__('(test payment)', 'orboni-for-tutor-lms') : '';
        $late = ! empty($session['paid_late']) ? ' '.__('It arrived after the payment page expired.', 'orboni-for-tutor-lms') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            return sprintf(
                /* translators: 1: coin amount, 2: coin, 3: network, 4: the amount it counted for, with its currency, 5: session id */
                __('Paid with %1$s %2$s on %3$s (%4$s) through Orboni, session %5$s.', 'orboni-for-tutor-lms'),
                (string) $payment['amount'],
                (string) $payment['coin'],
                self::CHAIN_NAMES[(string) ($payment['chain'] ?? '')] ?? ucfirst((string) ($payment['chain'] ?? '')),
                self::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return sprintf(
            /* translators: 1: the amount, with its currency, 2: session id */
            __('Paid %1$s through Orboni, session %2$s.', 'orboni-for-tutor-lms'),
            self::money($session, 'amount_received'),
            (string) $session['id']
        ).$test.$late;
    }

    /**
     * What Tutor keeps as the order's payment payload: the session's state,
     * never the buyer's address or anything secret.
     *
     * @param  array<string, mixed>  $session
     */
    private static function payload(array $session)
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];

        return (string) wp_json_encode(array_filter([
            'session' => (string) ($session['id'] ?? ''),
            'status' => (string) ($session['status'] ?? ''),
            'payment_status' => (string) ($session['payment_status'] ?? ''),
            'amount_received' => isset($session['amount_received']) ? (string) $session['amount_received'] : null,
            'currency' => (string) ($session['currency'] ?? ''),
            'coin' => isset($payment['coin']) ? (string) $payment['coin'] : null,
            'chain' => isset($payment['chain']) ? (string) $payment['chain'] : null,
            'livemode' => ! empty($session['livemode']),
        ], static function ($value) {
            return $value !== null && $value !== '';
        }));
    }

    /**
     * One of the session's decimal amounts with its currency: "25.00 EUR", "1500 JPY".
     *
     * @param  array<string, mixed>  $session
     */
    private static function money(array $session, $field)
    {
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        return number_format((float) ($session[$field] ?? 0), Orboni_Tutor_Settings::decimals($currency), '.', '').' '.$currency;
    }
}
