<?php
// subscriptions.php
require_once __DIR__ . '/../middleware/session-auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$localConfigPath = dirname(__DIR__) . '/config/stripe-config.local.php';
$defaultConfigPath = dirname(__DIR__) . '/config/stripe-config.php';
$stripeConfig = file_exists($localConfigPath) ? require $localConfigPath : require $defaultConfigPath;

$user = SessionAuth::requireAuth();
if (!isset($user->user_id)) {
    http_response_code(401);
    echo json_encode(['error' => 'User ID not found in session']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]);
    exit();
}

function cancelStripeSubscription($subscriptionId, $secretKey)
{
    $ch = curl_init('https://api.stripe.com/v1/subscriptions/' . urlencode($subscriptionId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secretKey]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    // Return null for not found or other non-fatal Stripe responses; caller may handle
    return null;
}

function logCleanup($msg)
{
    $logFile = __DIR__ . '/subscriptions-cleanup.log';
    file_put_contents($logFile, date('c') . ' CLEANUP: ' . $msg . "\n", FILE_APPEND);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT * FROM sprakapp_user_subscriptions WHERE user_id = ? ORDER BY FIELD(status, "active", "cancelled", "expired", "none") ASC, start_date DESC');
        // Debug: log SQL and user_id
        file_put_contents(__DIR__ . '/subscriptions-cleanup.log', date('c') . " SQL: SELECT * FROM sprakapp_user_subscriptions WHERE user_id = {$user->user_id}\n", FILE_APPEND);
        $stmt->execute([$user->user_id]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Fix any existing subscriptions with empty status BEFORE processing
        try {
            $stmtFix = $db->prepare("UPDATE sprakapp_user_subscriptions SET status = 'none' WHERE user_id = ? AND (status = '' OR status IS NULL)");
            $stmtFix->execute([$user->user_id]);
            $fixedCount = $stmtFix->rowCount();
            if ($fixedCount > 0) {
                // Re-fetch after fix
                $stmt->execute([$user->user_id]);
                $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                file_put_contents(__DIR__ . '/subscriptions-cleanup.log', date('c') . " FIXED_EMPTY_STATUS: count={$fixedCount}\n", FILE_APPEND);
            }
        } catch (Exception $ee) {
            error_log('subscriptions.php: Failed to fix empty status: ' . $ee->getMessage());
        }
        file_put_contents(__DIR__ . '/subscriptions-cleanup.log', date('c') . " GET_SUBS: user={$user->user_id} count=" . count($subs) . "\n", FILE_APPEND);
        file_put_contents(__DIR__ . '/subscriptions-cleanup.log', date('c') . " SUB_ROWS: " . json_encode($subs) . "\n", FILE_APPEND);

        foreach ($subs as &$sub) {
            // Normalize empty status to 'none'
            if (empty($sub['status'])) {
                $sub['status'] = 'none';
            }
            $stmtPlan = $db->prepare('SELECT name, num_courses, price_monthly, currency, stripe_price_id FROM sprakapp_subscription_plans WHERE id = ?');
            $stmtPlan->execute([$sub['plan_id']]);
            $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
            if ($plan) $sub = array_merge($sub, $plan);

            $stmt2 = $db->prepare(
                'SELECT c.id, c.title, uca.stripe_subscription_id, uca.end_date '
                . 'FROM sprakapp_user_subscription_courses usc '
                . 'JOIN sprakapp_courses c ON usc.course_id = c.id '
                . 'LEFT JOIN sprakapp_user_course_access uca ON uca.user_id = ? AND uca.course_id = c.id '
                . 'WHERE usc.user_subscription_id = ?'
            );
            $stmt2->execute([$sub['user_id'], $sub['id']]);
            $courses = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $sub['courses'] = $courses;

            // derive stripe_subscription_id and end_date from access rows if present
            // BUT: do not copy Stripe ID to none/pending subscriptions (they should remain clean)
            $sub['stripe_subscription_id'] = null;
            $sub['end_date'] = null;
            if ($sub['status'] !== 'none' && $sub['status'] !== 'pending') {
                foreach ($courses as $c) {
                    if (!empty($c['stripe_subscription_id'])) {
                        $sub['stripe_subscription_id'] = $c['stripe_subscription_id'];
                    }
                    if (!empty($c['end_date'])) {
                        if ($sub['end_date'] === null || $c['end_date'] > $sub['end_date']) {
                            $sub['end_date'] = $c['end_date'];
                        }
                    }
                }
            }
        }

        // simple server-side dedupe: keep one per plan (prefer active, then recent)
        $grouped = [];
        foreach ($subs as $s) {
            $key = isset($s['plan_id']) ? $s['plan_id'] : $s['id'];
            $grouped[$key][] = $s;
        }

        $deduped = [];
        foreach ($grouped as $group) {
            usort($group, function ($a, $b) {
                $sa = $a['status'] ?? '';
                $sb = $b['status'] ?? '';
                if ($sa === 'active' && $sb !== 'active') return -1;
                if ($sb === 'active' && $sa !== 'active') return 1;
                return strcmp($b['start_date'] ?? '', $a['start_date'] ?? '');
            });
            $deduped[] = $group[0];
        }

        file_put_contents(__DIR__ . '/subscriptions-cleanup.log', date('c') . " DEDUPED: " . json_encode($deduped) . "\n", FILE_APPEND);
        http_response_code(200);
        echo json_encode(['subscriptions' => $deduped]);
        exit();
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['subscription_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'subscription_id is required']);
            exit();
        }

        $subscriptionId = $data['subscription_id'];
        $stmt = $db->prepare('SELECT * FROM sprakapp_user_subscriptions WHERE id = ? AND user_id = ?');
        $stmt->execute([$subscriptionId, $user->user_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            http_response_code(404);
            echo json_encode(['error' => 'Subscription not found']);
            exit();
        }

        // create a new local subscription and open Stripe Checkout
        $planId = $sub['plan_id'];
        $stmt = $db->prepare('SELECT course_id FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ?');
        $stmt->execute([$subscriptionId]);
        $courseIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $startDate = date('Y-m-d');
        if (!empty($sub['end_date']) && $sub['end_date'] > $startDate) {
            // Start the new subscription the day after the old one ends
            $startDate = date('Y-m-d', strtotime($sub['end_date'] . ' +1 day'));
        }

        // Status is 'active' if starting today, otherwise 'none' (pending future start)
        $newStatus = ($startDate <= date('Y-m-d')) ? 'active' : 'none';
        $stmt = $db->prepare('INSERT INTO sprakapp_user_subscriptions (user_id, plan_id, start_date, slots_total, slots_used, status, created_at) VALUES (?, ?, ?, ?, 0, ?, NOW())');
        $stmt->execute([$user->user_id, $planId, $startDate, $sub['slots_total'], $newStatus]);
        $newSubId = $db->lastInsertId();

        try {
            $stmtExpire = $db->prepare("UPDATE sprakapp_user_subscriptions SET status = 'expired' WHERE user_id = ? AND plan_id = ? AND id != ? AND status = 'active'");
            $stmtExpire->execute([$user->user_id, $planId, $newSubId]);
        } catch (Exception $ee) {
            error_log('subscriptions.php: Failed to expire old subscriptions: ' . $ee->getMessage());
        }

        foreach ($courseIds as $courseId) {
            $stmt = $db->prepare('INSERT INTO sprakapp_user_subscription_courses (user_subscription_id, course_id) VALUES (?, ?)');
            $stmt->execute([$newSubId, $courseId]);
        }

        $stmt = $db->prepare('SELECT stripe_price_id FROM sprakapp_subscription_plans WHERE id = ?');
        $stmt->execute([$planId]);
        $stripePriceId = $stmt->fetchColumn();
        if (!$stripePriceId) {
            http_response_code(400);
            echo json_encode(['error' => 'Ingen Stripe-pris kopplad till denna plan.']);
            exit();
        }

        $successUrl = 'https://polyverbo.com/courses?payment=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = 'https://polyverbo.com/courses?payment=cancelled';
        $stripeParams = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'mode' => 'subscription',
            'payment_method_types[0]' => 'card',
            'line_items[0][price]' => $stripePriceId,
            'line_items[0][quantity]' => 1,
            'metadata[user_subscription_id]' => $newSubId,
            'customer_email' => $user->email,
        ];

        // If subscription starts in the future, use trial_period_days
        // Note: Stripe will show "Trial" but it's the only way to delay billing start date
        if ($startDate > date('Y-m-d')) {
            $nowTs = strtotime(date('Y-m-d'));
            $startTs = strtotime($startDate . ' 00:00:00');
            $days = (int) ceil(($startTs - $nowTs) / 86400);
            if ($days > 0) {
                $stripeParams['subscription_data[trial_period_days]'] = $days;
            }
        }

        $postData = http_build_query($stripeParams);
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripeConfig['secret_key'],
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $stripeResult = json_decode($response, true);
        // Log Stripe response for debugging
        file_put_contents(__DIR__ . '/subscriptions-cleanup.log', date('c') . ' STRIPE_CREATE: ' . json_encode($stripeResult) . "\n", FILE_APPEND);

        // Cleanup: clear stripe_subscription_id on cancelled rows that have an active sibling for same user+plan
        try {
            $stmtCleanup2 = $db->prepare(
                "UPDATE sprakapp_user_subscriptions s
                 JOIN sprakapp_user_subscriptions a
                   ON a.user_id = s.user_id
                  AND a.plan_id = s.plan_id
                  AND a.status = 'active'
                 SET s.stripe_subscription_id = NULL
                 WHERE s.status = 'cancelled' AND s.id <> a.id AND s.user_id = ? AND s.plan_id = ?"
            );
            $stmtCleanup2->execute([$user->user_id, $planId]);
            $affected2 = $stmtCleanup2->rowCount();
            logCleanup("create-new-sub: user={$user->user_id} plan={$planId} cleared_rows={$affected2}");
        } catch (Exception $ee) {
            error_log('subscriptions.php: Failed cleanup after creating new subscription: ' . $ee->getMessage());
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $out = ['success' => true];
            if (isset($stripeResult['url'])) $out['checkout_url'] = $stripeResult['url'];
            if (isset($stripeResult['id'])) $out['session_id'] = $stripeResult['id'];
            http_response_code(200);
            echo json_encode($out);
            exit();
        }

        http_response_code(500);
        echo json_encode(['error' => $stripeResult['error']['message'] ?? 'Kunde inte skapa Stripe-session.', 'stripe_response' => $stripeResult]);
        exit();
    }

    if ($method === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['subscription_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'subscription_id is required']);
            exit();
        }

        $subscriptionId = $data['subscription_id'];

        $stmt = $db->prepare("SELECT id, course_id FROM sprakapp_user_course_access WHERE user_id = ? AND stripe_subscription_id = ?");
        $stmt->execute([$user->user_id, $subscriptionId]);
        $accessRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$accessRows) {
            http_response_code(404);
            echo json_encode(['error' => 'Subscription not found']);
            exit();
        }

        // Attempt to cancel at Stripe (best-effort)
        $cancelledSubscription = null;
        try {
            $cancelledSubscription = cancelStripeSubscription($subscriptionId, $stripeConfig['secret_key']);
        } catch (Exception $e) {
            error_log('subscriptions.php: Stripe cancel error: ' . $e->getMessage());
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE sprakapp_user_course_access SET subscription_status = 'cancelled' WHERE stripe_subscription_id = ? AND user_id = ?");
            $stmt->execute([$subscriptionId, $user->user_id]);

            $stmt = $db->prepare("UPDATE sprakapp_user_subscriptions SET status = 'cancelled' WHERE stripe_subscription_id = ? AND user_id = ?");
            $stmt->execute([$subscriptionId, $user->user_id]);
            $db->commit();
        } catch (Exception $ee) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('subscriptions.php: Failed to mark cancelled: ' . $ee->getMessage());
        }

        // Determine end_date: prefer Stripe's current_period_end, otherwise use MAX(end_date) from access rows
        $endDate = null;
        if (!empty($cancelledSubscription) && isset($cancelledSubscription['current_period_end'])) {
            $endDate = date('Y-m-d', $cancelledSubscription['current_period_end']);
        } else {
            $stmtMax = $db->prepare('SELECT MAX(end_date) FROM sprakapp_user_course_access WHERE user_id = ? AND stripe_subscription_id = ?');
            $stmtMax->execute([$user->user_id, $subscriptionId]);
            $maxEnd = $stmtMax->fetchColumn();
            if (!empty($maxEnd)) {
                $endDate = date('Y-m-d', strtotime($maxEnd));
            }
        }

        if ($endDate) {
            try {
                $stmtUpdateEnd = $db->prepare('UPDATE sprakapp_user_subscriptions SET end_date = ? WHERE stripe_subscription_id = ? AND user_id = ?');
                $stmtUpdateEnd->execute([$endDate, $subscriptionId, $user->user_id]);
                logCleanup("set-end-date: user={$user->user_id} stripe={$subscriptionId} end_date={$endDate}");
            } catch (Exception $ee) {
                error_log('subscriptions.php: Failed to set end_date after cancel: ' . $ee->getMessage());
            }
        }

        // Cleanup: clear stripe_subscription_id on cancelled rows that have an active sibling for same user+plan
        try {
            $stmtCleanup3 = $db->prepare(
                "UPDATE sprakapp_user_subscriptions s
                 JOIN sprakapp_user_subscriptions a
                   ON a.user_id = s.user_id
                  AND a.plan_id = s.plan_id
                  AND a.status = 'active'
                 SET s.stripe_subscription_id = NULL
                 WHERE s.status = 'cancelled' AND s.id <> a.id AND s.user_id = ?"
            );
            $stmtCleanup3->execute([$user->user_id]);
            $affected3 = $stmtCleanup3->rowCount();
            logCleanup("cancel: user={$user->user_id} cleared_rows={$affected3}");
        } catch (Exception $ee) {
            error_log('subscriptions.php: Failed cleanup after cancel: ' . $ee->getMessage());
        }

        http_response_code(200);
        echo json_encode([
            'message' => !empty($cancelledSubscription) ? 'Subscription cancelled successfully' : 'Prenumerationen markerad som avslutad lokalt',
            'subscription' => $cancelledSubscription,
        ]);
        exit();
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();

} catch (Exception $e) {
    error_log('subscriptions.php: Uncaught exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'details' => $e->getMessage()]);
    exit();
}
