<?php
/**
 * GET /backend/api/verify-payment.php?session_id=cs_...
 *
 * Called by the frontend immediately after Stripe redirects back.
 * Fetches the Checkout Session from Stripe, confirms it's paid,
 * and upgrades the user's plan in the database right away.
 *
 * Returns: { "plan": "pro", "user": { ... } }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Method not allowed');
}

$user      = require_auth();
$sessionId = trim($_GET['session_id'] ?? '');

if (!$sessionId || !str_starts_with($sessionId, 'cs_')) {
    json_error(422, 'Invalid session_id');
}

// ── Fetch the Checkout Session from Stripe ───────────────────────
$session = stripe('GET', '/checkout/sessions/' . urlencode($sessionId));

if (isset($session['error'])) {
    json_error(502, 'Stripe error: ' . ($session['error']['message'] ?? 'unknown'));
}

// ── Confirm it belongs to this user and is paid ──────────────────
$clientRef = (int) ($session['client_reference_id'] ?? 0);
if ($clientRef !== (int) $user['id']) {
    json_error(403, 'Session does not belong to this account');
}

$status         = $session['payment_status'] ?? '';    // 'paid' | 'unpaid' | 'no_payment_required'
$subscriptionId = $session['subscription']   ?? null;
$customerId     = $session['customer']       ?? null;

if ($status !== 'paid') {
    json_out(['plan' => 'free', 'message' => 'Payment not completed (status: ' . $status . ')']);
}

// ── Get subscription period end ──────────────────────────────────
$expiresAt = null;
if ($subscriptionId) {
    $sub = stripe('GET', '/subscriptions/' . urlencode($subscriptionId));
    if (isset($sub['current_period_end'])) {
        $expiresAt = date('Y-m-d H:i:s', $sub['current_period_end']);
    }
}

// ── Upgrade user in the database ─────────────────────────────────
db()->prepare(
    'UPDATE users
     SET plan                    = "pro",
         stripe_customer_id      = :cid,
         stripe_subscription_id  = :sid,
         plan_expires_at         = :expires
     WHERE id = :id'
)->execute([
    ':cid'     => $customerId,
    ':sid'     => $subscriptionId,
    ':expires' => $expiresAt,
    ':id'      => $user['id'],
]);

// ── Return updated user ───────────────────────────────────────────
json_out([
    'plan' => 'pro',
    'user' => [
        'id'    => $user['id'],
        'email' => $user['email'],
        'name'  => $user['name'],
        'plan'  => 'pro',
    ],
]);
