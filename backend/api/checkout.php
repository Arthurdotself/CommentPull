<?php
/**
 * POST /backend/api/checkout.php
 * Body: { "price_id": "pro_monthly" | "pro_yearly" }
 *
 * Creates a Stripe Checkout Session and returns the redirect URL.
 * Returns: { "url": "https://checkout.stripe.com/..." }
 *
 * Flow:
 *   1. Frontend calls this endpoint (user must be signed in)
 *   2. Server creates a Stripe Checkout Session
 *   3. Frontend redirects user to the returned Stripe URL
 *   4. After payment, Stripe redirects to success_url
 *   5. Stripe also fires a webhook → webhook.php updates the user's plan
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

$user = require_auth();
$data = body();

// Map plan slug → Stripe Price ID
$priceMap = [
    'pro_monthly' => PRICE_PRO_MONTHLY,
    'pro_yearly'  => PRICE_PRO_YEARLY,
];

$planSlug = $data['price_id'] ?? '';
if (!isset($priceMap[$planSlug])) {
    json_error(422, 'Invalid plan. Choose pro_monthly or pro_yearly.');
}
$stripePriceId = $priceMap[$planSlug];

// ── Get or create Stripe customer ───────────────────────────────
$customerId = $user['stripe_customer_id'];

if (!$customerId) {
    $customer = stripe('POST', '/customers', [
        'email'    => $user['email'],
        'name'     => $user['name'],
        'metadata[user_id]' => $user['id'],
    ]);

    if (isset($customer['error'])) {
        error_log('Stripe create customer error: ' . json_encode($customer['error']));
        json_error(500, 'Could not create billing profile');
    }

    $customerId = $customer['id'];

    db()->prepare('UPDATE users SET stripe_customer_id = :cid WHERE id = :id')
        ->execute([':cid' => $customerId, ':id' => $user['id']]);
}

// ── Create Stripe Checkout Session ──────────────────────────────
$session = stripe('POST', '/checkout/sessions', [
    'customer'                  => $customerId,
    'mode'                      => 'subscription',
    'line_items[0][price]'      => $stripePriceId,
    'line_items[0][quantity]'   => 1,
    'success_url'               => SITE_URL . '/?payment=success&session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'                => SITE_URL . '/?payment=cancelled',
    'client_reference_id'       => (string) $user['id'],
    'allow_promotion_codes'     => 'true',
    'billing_address_collection' => 'auto',
    'metadata[user_id]'         => $user['id'],
    'metadata[plan]'            => $planSlug,
]);

if (isset($session['error'])) {
    error_log('Stripe session error: ' . json_encode($session['error']));
    json_error(500, 'Could not create checkout session: ' . ($session['error']['message'] ?? 'Unknown error'));
}

json_out(['url' => $session['url']]);
