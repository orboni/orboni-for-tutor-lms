=== Orboni for Tutor LMS ===
Contributors: orboni
Tags: crypto, cryptocurrency, usdt, bitcoin, tutor lms
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell courses for USDT, USDC, BTC, ETH and other coins, paid straight to your own wallets. Non-custodial: Orboni never holds your money.

== Description ==

Orboni adds "Crypto" to Tutor LMS's own checkout. The student presses Pay now, picks a coin on Orboni's payment page and pays from any wallet or exchange. The money goes straight to the wallet addresses you set in your Orboni dashboard; Orboni never receives, holds or forwards it. When the payment is final on the chain, the order is marked paid by itself and the student is enrolled.

It is a payment method for Tutor LMS's native eCommerce (Tutor LMS → Settings → Monetization: Tutor LMS), set up beside PayPal under Payment Methods. It works with the free Tutor LMS; Tutor LMS Pro is not needed.

**What students can pay with**

* **USDT** on TRON, Ethereum, BNB Chain, Solana, TON, Polygon, Arbitrum, Optimism and Avalanche
* **USDC** on Ethereum, Base, Solana, Polygon, Arbitrum, Optimism, Avalanche, BNB Chain and Stellar
* **DAI** and **PYUSD**
* **BTC**, **ETH**, **BNB**, **SOL**, **TRX**, **POL**, **AVAX**, **LTC**, **DOGE**, **BCH**, **XRP**, **XLM** and TON's **GRAM**

The payment page offers only the coins you have set a wallet for in the Orboni dashboard.

**How it works**

* The student picks "Crypto" at checkout and is sent to Orboni's payment page, in their language, with the courses and the order's total in your site's currency.
* The page shows the exact amount in the chosen coin, an address and a QR code, and follows the transfer on the chain.
* Orboni tells your site with a signed webhook, at the Webhook URL Tutor LMS shows for the method, and the order moves on: payment Pending while it confirms, then Paid — Tutor LMS completes the order and enrols the student.
* Without a webhook the plugin still reads the payment back when the student returns to your site, and checks again by itself a few minutes later.

**Built for real orders**

* Underpaid and reversed payments, and payments held for your review, keep the order's payment Pending with a line in its history, so nobody is enrolled by mistake; a reversed payment pauses the enrolment until it is paid again. An overpaid order is marked paid, and a line says how much arrived.
* A student who presses Pay again gets the same payment page while it has more than five minutes left. An order whose page ran out stays unpaid, and the student pays it again from their order list.
* The payment page's back link closes the page and brings the student back to Tutor's checkout for the order, to pay again or another way.
* Test keys let you try the whole flow before you take real payments.

Your site can sell in US dollars, euros, pounds, Swiss francs, złoty, Czech koruna, forints, lei, Danish, Swedish and Norwegian kroner, Icelandic krónur, lira, yen, won, yuan, Hong Kong and Singapore dollars, rupees, rupiah, ringgit, pesos (Philippine and Mexican), baht, shekels, Australian, New Zealand and Canadian dollars, reais, rand, hryvnias, Argentine and Chilean pesos, dirhams, riyals, Taiwan dollars and đồng. Orboni turns the price into dollars at the rate of the day (the European Central Bank's, or the market's for a currency the ECB does not publish), and the student pays that in the coin they pick. A payment is worth at least $1.

== Installation ==

1. Install and activate the plugin (Tutor LMS 3.0 or later must be active, monetised by Tutor LMS itself).
2. Sign up at [orboni.com](https://orboni.com), add a project and set the wallet addresses you want to be paid to.
3. In the Orboni dashboard, under Developers → API keys, copy the secret key. Use a test key (`sk_test_…`) first.
4. In WordPress, go to Tutor LMS → Settings → Payment Methods, open "Crypto (USDT, USDC, BTC and more)" and paste the secret key.
5. In the Orboni dashboard, under Developers → Webhooks, add an endpoint with the Webhook URL the method shows (`https://your-site.example/wp-json/tutor/v1/ecommerce-webhook/orboni`) and paste its signing secret (`whsec_…`) into the method's settings. Switch the method on and press Save Changes.
6. Buy a course as a student. Once it works, switch to your live key.

== Frequently Asked Questions ==

= Does Orboni hold my money? =

No. Students pay to the wallet addresses you set in your dashboard, and the money is yours from the moment it is on the chain. Orboni watches the chain and tells your site; it never has your keys or your coins.

= Do I need Tutor LMS Pro? =

No. The method plugs into the free Tutor LMS's own checkout. It does not take subscriptions: Tutor offers it for courses and bundles bought once.

= I sell my courses through WooCommerce or Easy Digital Downloads. =

Then use Orboni for WooCommerce or Orboni for Easy Digital Downloads instead: this plugin is for sites where Tutor LMS sells the courses itself.

= My site is not in US dollars. =

Orboni takes 37 currencies, listed above: the payment page shows the order's total in your site's currency, and the student pays its worth at the rate of the day. With a currency outside the list crypto is not offered at checkout, and the payment settings say so.

= What happens if a student sends too little or too much? =

Too little: the payment page asks for the rest, and the order's payment is Pending with a line in its history. If the page expires part paid, it stays Pending for you to settle with the student. Too much: the order is marked paid, and a line says how much arrived in all; send the difference back from your own wallet. Only a payment far above the total (over twice it, and over $50 more) to a Bitcoin-style address is held for your review in the Orboni dashboard first, since it may be someone else's payment; the order stays Pending until you accept it there.

= How do refunds work? =

The money is in your wallet, so you send a refund from there and record it in the Orboni dashboard. Then mark the order refunded in Tutor LMS (Orders → the order → Refund), which can also take the enrolment away.

= My site uses plain permalinks. =

Tutor's Webhook URL then does not answer; the payment settings show the address to use instead (`/?rest_route=/tutor/v1/ecommerce-webhook/orboni`).

= Where are the logs? =

What happened to each order is in its history in Tutor LMS → Orders. Requests and webhooks are written to the PHP error log (wp-content/debug.log with WP_DEBUG_LOG) with "Debug log" set to "Requests and webhooks too"; errors are written either way. Keys and signing secrets are never logged.

= Can I use one Orboni project for several sites? =

Yes. Each site adds its own webhook endpoint, and each one acts only on its own orders.

== External services ==

This plugin connects to Orboni (https://orboni.com), the payment service it exists for. Without it the plugin does nothing.

* **When a student presses Pay now with "Crypto"**, the plugin sends Orboni the order's total and currency, the courses' titles, the order's number, your site's name, the student's email address and language, the addresses to return the student to, the order's id and a short hash of your site's address (to tell your sites apart). Orboni uses them to draw the payment page and to send the student a receipt.
* **When the student returns, when a webhook arrives, and in a few scheduled checks after the payment page is opened and after it expires**, the plugin asks Orboni for the payment's status by its session id. When the student leaves the payment page by its back link, the plugin asks Orboni to close that page.
* **Orboni sends your site webhooks** about the payment to `/wp-json/tutor/v1/ecommerce-webhook/orboni`, signed with your endpoint's secret.
* The student pays on Orboni's own payment page at `https://orboni.com/pay/…`.

Every request carries your Orboni secret key. Orboni's [Terms of Service](https://orboni.com/page/terms-of-service) and [Privacy Policy](https://orboni.com/page/privacy-policy) apply.

== Screenshots ==

1. "Crypto" at Tutor's checkout.
2. Orboni's payment page: the coin, the amount, the address and its QR code.
3. The method's settings in Tutor LMS → Settings → Payment Methods.
4. An order paid with crypto, with its history.

== Changelog ==

= 1.0.1 =
* The notice that a newer Tutor LMS is needed shows on the Plugins screen only.

= 1.0.0 =
* First release: Tutor LMS's own checkout and Webhook URL, prices in 37 currencies, read-backs without a webhook, paying an unpaid order again.
