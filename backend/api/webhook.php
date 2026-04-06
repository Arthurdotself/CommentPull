<?php
/**
 * POST /backend/api/webhook.php
 *
 * Stripe sends events here. Register this URL in:
 *   Stripe Dashboard → Developers → Webhooks → Add endpoint
 *   URL: https://your-domain.com/backend/api/webhook.php
 *
 * Events to enable in Stripe:
 *   - checkout.session.completed
 *   - customer.subscription.deleted
 *   - invoice.payment_failed
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// ── Read raw body BEFORE any output ─────────────────────────────
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verify Stripe signature ──────────────────────────────────────
if (!verify_stripe_signature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
$type  = $event['type'] ?? '';
$obj   = $event['data']['object'] ?? [];

switch ($type) {

    // ── Payment completed ─────────────────────────────────────────
    case 'checkout.session.completed':
        $userId         = (int) ($obj['client_reference_id'] ?? 0);
        $subscriptionId = $obj['subscription'] ?? null;
        $customerId     = $obj['customer'] ?? null;

        if ($userId && $subscriptionId) {
            // Fetch subscription to get period end
            $sub = stripe_get('/subscriptions/' . $subscriptionId);
            $expiresAt = isset($sub['current_period_end'])
                ? date('Y-m-d H:i:s', $sub['current_period_end'])
                : null;

            db()->prepare(
                'UPDATE users
                 SET plan = "pro",
                     stripe_customer_id     = :cid,
                     stripe_subscription_id = :sid,
                     plan_expires_at        = :expires
                 WHERE id = :id'
            )->execute([
                ':cid'     => $customerId,
                ':sid'     => $subscriptionId,
                ':expires' => $expiresAt,
                ':id'      => $userId,
            ]);
        }
        break;

    // ── Subscription cancelled/deleted ───────────────────────────
    case 'customer.subscription.deleted':
        $subscriptionId = $obj['id'] ?? null;
        if ($subscriptionId) {
            db()->prepare(
                'UPDATE users
                 SET plan = "free",
                     stripe_subscription_id = NULL,
                     plan_expires_at        = NULL
                 WHERE stripe_subscription_id = :sid'
            )->execute([':sid' => $subscriptionId]);
        }
        break;

    // ── Payment failed ────────────────────────────────────────────
    case 'invoice.payment_failed':
        $customerId = $obj['customer'] ?? null;
        if ($customerId) {
            // Log it — you may want to email the user here
            error_log('Payment failed for Stripe customer: ' . $customerId);
        }
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);

// ── Helpers ──────────────────────────────────────────────────────

function verify_stripe_signature(string $payload, string $header, string $secret): bool {
    // Parse t= and v1= values from the signature header
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$key] = $val;
    }

    $timestamp = $parts['t'] ?? '';
    $signature = $parts['v1'] ?? '';

    if (!$timestamp || !$signature) {
        return false;
    }

    // Reject events older than 5 minutes (replay attack protection)
    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    return hash_equals($expected, $signature);
}

function stripe_get(string $path): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.stripe.com/v1' . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}
