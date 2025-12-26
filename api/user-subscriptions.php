<?php
// DEBUG: Ange version och tidpunkt för att säkerställa att rätt fil körs
header('X-Debug-User-Subscriptions: v2025-12-25-1');
if (isset($_GET['debug'])) {
    echo json_encode(['debug' => 'user-subscriptions.php v2025-12-25-1', 'time' => date('c')]);
    exit;
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/session-auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = (new Database())->getConnection();

$user = SessionAuth::requireAuth();

switch ($method) {
    case 'GET':
        // List user's active subscriptions and slots
        $stmt = $db->prepare('SELECT us.*, p.name, p.num_courses, p.price_monthly, p.currency FROM sprakapp_user_subscriptions us JOIN sprakapp_subscription_plans p ON us.plan_id = p.id WHERE us.user_id = ? AND us.status = "active" ORDER BY us.start_date DESC');
        $stmt->execute([$user->user_id]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Add chosen courses for each subscription
        foreach ($subs as &$sub) {
            $stmt2 = $db->prepare('SELECT course_id FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ?');
            $stmt2->execute([$sub['id']]);
            $sub['chosen_courses'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode($subs);
        break;
    case 'POST':
        $data = json_decode(file_get_contents('php://input'));
        // Nytt flöde: skapa subscription och koppla kurser direkt
        if (isset($data->plan_id, $data->course_ids) && is_array($data->course_ids) && count($data->course_ids) > 0) {
            // Hämta plan
            $stmt = $db->prepare('SELECT * FROM sprakapp_subscription_plans WHERE id = ? AND is_active = 1');
            $stmt->execute([$data->plan_id]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$plan) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan not found']);
                exit;
            }
            // Kolla om det finns en cancelled/expired plan med end_date i framtiden
            $stmt = $db->prepare('SELECT * FROM sprakapp_user_subscriptions WHERE user_id = ? AND plan_id = ? AND status IN ("cancelled", "expired") ORDER BY end_date DESC LIMIT 1');
            $stmt->execute([$user->user_id, $plan['id']]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            $today = date('Y-m-d');
            $start_date = $today;
            if ($old && !empty($old['end_date']) && $old['end_date'] > $today) {
                // Starta ny prenumeration dagen efter gamla slutdatumet
                $start_date = date('Y-m-d', strtotime($old['end_date'] . ' +1 day'));
            }
            // Förhindra överlappande aktiva prenumerationer
            $stmt = $db->prepare('SELECT COUNT(*) FROM sprakapp_user_subscriptions WHERE user_id = ? AND plan_id = ? AND status = "active" AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)');
            $stmt->execute([$user->user_id, $plan['id'], $start_date, $start_date]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Du har redan en aktiv prenumeration för denna plan under denna period.']);
                exit;
            }
            // Skapa subscription med rätt startdatum
            $stmt = $db->prepare('INSERT INTO sprakapp_user_subscriptions (user_id, plan_id, start_date, slots_total, slots_used, status, created_at) VALUES (?, ?, ?, ?, 0, "active", NOW())');
            $stmt->execute([$user->user_id, $plan['id'], $start_date, $plan['num_courses']]);
            $user_subscription_id = $db->lastInsertId();
            // Koppla kurser
            $used = 0;
            foreach ($data->course_ids as $course_id) {
                $stmt = $db->prepare('INSERT INTO sprakapp_user_subscription_courses (user_subscription_id, course_id) VALUES (?, ?)');
                $stmt->execute([$user_subscription_id, $course_id]);
                $used++;
            }
            // Uppdatera slots_used
            $stmt = $db->prepare('UPDATE sprakapp_user_subscriptions SET slots_used = ? WHERE id = ?');
            $stmt->execute([$used, $user_subscription_id]);
            // Skapa Stripe Checkout-session
            // Hämta stripe_price_id från plan
            $stripe_price_id = $plan['stripe_price_id'] ?? null;
            if (!$stripe_price_id) {
                // Om stripe_price_id saknas, returnera fel
                echo json_encode(['success' => true, 'user_subscription_id' => $user_subscription_id, 'warning' => 'Ingen Stripe-pris kopplad till denna plan.']);
                exit;
            }
            // Skapa Stripe Checkout-session direkt här
            // Ladda alltid public_html/config/stripe-config.local.php om den finns, annars stripe-config.php
            $localConfigPath = $_SERVER['DOCUMENT_ROOT'] . '/config/stripe-config.local.php';
            $defaultConfigPath = $_SERVER['DOCUMENT_ROOT'] . '/config/stripe-config.php';
            if (file_exists($localConfigPath)) {
                $stripeConfig = require $localConfigPath;
                $usedConfigPath = $localConfigPath;
            } else {
                $stripeConfig = require $defaultConfigPath;
                $usedConfigPath = $defaultConfigPath;
            }
            $secretKey = $stripeConfig['secret_key'];
            // Logga vilken config-fil och nyckel som används
            file_put_contents(dirname(__FILE__) . '/stripe-debug.log', date('c') . " CONFIG_PATH: $usedConfigPath\n", FILE_APPEND);
            file_put_contents(dirname(__FILE__) . '/stripe-debug.log', date('c') . " SECRET_KEY: $secretKey\n", FILE_APPEND);
            $successUrl = 'https://polyverbo.com/courses?payment=success&session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = 'https://polyverbo.com/courses?payment=cancelled';
            $stripeParams = [
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'mode' => 'subscription',
                'payment_method_types[0]' => 'card',
                'line_items[0][price]' => $stripe_price_id,
                'line_items[0][quantity]' => 1,
                'metadata[user_subscription_id]' => $user_subscription_id,
                'customer_email' => $user->email,
            ];
            // Om start_date är i framtiden, sätt Stripe subscription_data[start_date]
            if ($start_date > date('Y-m-d')) {
                $stripeParams['subscription_data[start_date]'] = strtotime($start_date . ' 00:00:00');
            }
            $postData = http_build_query($stripeParams);
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            // Logga Stripe-svar
            file_put_contents(dirname(__FILE__) . '/stripe-debug.log', date('c') . " STRIPE_RESP ($httpCode): $response\n", FILE_APPEND);
            $stripeResult = json_decode($response, true);
            if ($httpCode === 200 && isset($stripeResult['url'])) {
                // Spara stripe_subscription_id i subscriptions-tabellen om det finns
                if (!empty($stripeResult['subscription'])) {
                    $stripeSubId = $stripeResult['subscription'];
                    $stmt = $db->prepare('UPDATE sprakapp_user_subscriptions SET stripe_subscription_id = ? WHERE id = ?');
                    $stmt->execute([$stripeSubId, $user_subscription_id]);
                    // Uppdatera alla access-rader för denna subscription med stripe_subscription_id
                    $stmt = $db->prepare('UPDATE sprakapp_user_course_access SET stripe_subscription_id = ? WHERE user_id = ? AND course_id IN (SELECT course_id FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ?)');
                    $stmt->execute([$stripeSubId, $user->user_id, $user_subscription_id]);
                }
                echo json_encode(['success' => true, 'user_subscription_id' => $user_subscription_id, 'checkout_url' => $stripeResult['url']]);
            } else {
                echo json_encode(['success' => true, 'user_subscription_id' => $user_subscription_id, 'stripe_error' => $stripeResult['error']['message'] ?? 'Kunde inte skapa Stripe-session.']);
            }
            exit;
        }
        // Gamla flödet: välj kurs till befintlig subscription
        if (!isset($data->user_subscription_id, $data->course_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }
        // Check if user owns this subscription and has free slots
        $stmt = $db->prepare('SELECT * FROM sprakapp_user_subscriptions WHERE id = ? AND user_id = ? AND status = "active"');
        $stmt->execute([$data->user_subscription_id, $user->user_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            http_response_code(403);
            echo json_encode(['error' => 'Subscription not found or not active']);
            exit;
        }
        if ($sub['slots_used'] >= $sub['slots_total']) {
            http_response_code(400);
            echo json_encode(['error' => 'No free slots left']);
            exit;
        }
        // Check if course already chosen
        $stmt = $db->prepare('SELECT COUNT(*) FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ? AND course_id = ?');
        $stmt->execute([$data->user_subscription_id, $data->course_id]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Course already chosen']);
            exit;
        }
        // Add course
        $stmt = $db->prepare('INSERT INTO sprakapp_user_subscription_courses (user_subscription_id, course_id) VALUES (?, ?)');
        $stmt->execute([$data->user_subscription_id, $data->course_id]);
        // Update slots_used
        $stmt = $db->prepare('UPDATE sprakapp_user_subscriptions SET slots_used = slots_used + 1 WHERE id = ?');
        $stmt->execute([$data->user_subscription_id]);

        // Skapa access i sprakapp_user_course_access om det inte redan finns
        $stmt = $db->prepare('SELECT id FROM sprakapp_user_course_access WHERE user_id = ? AND course_id = ?');
        $stmt->execute([$user->user_id, $data->course_id]);
        $access = $stmt->fetch(PDO::FETCH_ASSOC);
        $hasStripe = !empty($sub['stripe_subscription_id']);
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+1 month'));
        if (!$access) {
            if ($hasStripe) {
                $stmt = $db->prepare('INSERT INTO sprakapp_user_course_access (user_id, course_id, start_date, end_date, granted_at, stripe_subscription_id, subscription_status) VALUES (?, ?, ?, ?, NOW(), ?, "active")');
                $stmt->execute([$user->user_id, $data->course_id, $today, $endDate, $sub['stripe_subscription_id']]);
            } else {
                $stmt = $db->prepare('INSERT INTO sprakapp_user_course_access (user_id, course_id, start_date, granted_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([$user->user_id, $data->course_id, $today]);
            }
        } else if ($hasStripe) {
            // Om access redan finns men saknar stripe-id/status, uppdatera den
            $stmt = $db->prepare('UPDATE sprakapp_user_course_access SET start_date = ?, end_date = ?, stripe_subscription_id = ?, subscription_status = "active" WHERE id = ?');
            $stmt->execute([$today, $endDate, $sub['stripe_subscription_id'], $access['id']]);
        }

        echo json_encode(['success' => true]);
        exit;
    case 'DELETE':
        // Ta bort ej slutförd prenumeration (endast om status = none eller pending och ingen stripe_subscription_id)
        $data = json_decode(file_get_contents('php://input'));
        if (!isset($data->user_subscription_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'user_subscription_id is required']);
            exit;
        }
        $stmt = $db->prepare('SELECT * FROM sprakapp_user_subscriptions WHERE id = ? AND user_id = ?');
        $stmt->execute([$data->user_subscription_id, $user->user_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            http_response_code(404);
            echo json_encode(['error' => 'Subscription not found']);
            exit;
        }
        if (!in_array($sub['status'], ['none', 'pending']) || !empty($sub['stripe_subscription_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Only pending/none subscriptions without Stripe id can be deleted']);
            exit;
        }
        // Ta bort kopplingar till kurser
        $stmt = $db->prepare('DELETE FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ?');
        $stmt->execute([$data->user_subscription_id]);
        // Ta bort själva prenumerationen
        $stmt = $db->prepare('DELETE FROM sprakapp_user_subscriptions WHERE id = ? AND user_id = ?');
        $stmt->execute([$data->user_subscription_id, $user->user_id]);
        echo json_encode(['success' => true]);
        exit;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
