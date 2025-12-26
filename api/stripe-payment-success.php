<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/session-auth.php';
require_once __DIR__ . '/../config/database.php';
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

// Require authenticated user
$user = SessionAuth::requireAuth();

// Get database connection
$database = new Database();
$db = $database->getConnection();
// $stripeConfig laddas ovan

try {
    // Get session_id from query parameter
    $sessionId = $_GET['session_id'] ?? null;
    
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        exit();
    }
    
    // Retrieve the session from Stripe
    $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripeConfig['secret_key'],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to retrieve checkout session');
    }
    
    $session = json_decode($response, true);
    
    // Verify payment was successful
    if ($session['payment_status'] !== 'paid') {
        http_response_code(400);
        echo json_encode(['error' => 'Payment not completed']);
        exit();
    }
    
    // Ny logik: lås upp alla kurser kopplade till user_subscription_id
    $userSubscriptionId = $session['metadata']['user_subscription_id'] ?? null;
    $subscriptionId = $session['subscription'] ?? null;
    $customerId = $session['customer'] ?? null;

    if (!$userSubscriptionId) {
        throw new Exception('Missing user_subscription_id in Stripe metadata');
    }

    // Hämta subscription och användare
    $stmt = $db->prepare('SELECT * FROM sprakapp_user_subscriptions WHERE id = ?');
    $stmt->execute([$userSubscriptionId]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$subscription) {
        throw new Exception('Subscription not found');
    }
    // Kontrollera att inloggad användare äger subscription
    if ($subscription['user_id'] !== $user->user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'User mismatch']);
        exit();
    }

    // Hämta alla kurser kopplade till subscription
    $stmt = $db->prepare('SELECT course_id FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ?');
    $stmt->execute([$userSubscriptionId]);
    $courseIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$courseIds) {
        throw new Exception('No courses found for this subscription');
    }

    // Uppdatera subscriptions-tabellen med stripe_subscription_id och status='active'
    if (!empty($subscriptionId)) {
        $stmt = $db->prepare('UPDATE sprakapp_user_subscriptions SET stripe_subscription_id = ?, status = ? WHERE id = ?');
        $stmt->execute([$subscriptionId, 'active', $userSubscriptionId]);
    } else {
        // Om ingen subscription_id (ovanligt), sätt bara status='active'
        $stmt = $db->prepare('UPDATE sprakapp_user_subscriptions SET status = ? WHERE id = ?');
        $stmt->execute(['active', $userSubscriptionId]);
    }

    // Lås upp alla kurser för användaren
    // Använd subscription.start_date om tillgängligt, annars dagens datum
    $grantedAt = date('Y-m-d H:i:s');
    $startDate = $subscription['start_date'] ?? date('Y-m-d');
    // end_date = 1 månad från start_date
    $endDate = date('Y-m-d', strtotime($startDate . ' +1 month'));
    
    foreach ($courseIds as $courseId) {
        // Kolla om access redan finns
        $stmt = $db->prepare('SELECT id FROM sprakapp_user_course_access WHERE user_id = ? AND course_id = ?');
        $stmt->execute([$user->user_id, $courseId]);
        $existing = $stmt->fetch(PDO::FETCH_OBJ);
        if ($existing) {
            // Uppdatera befintlig access
            $stmt = $db->prepare('UPDATE sprakapp_user_course_access SET start_date = ?, end_date = ?, granted_at = ?, stripe_subscription_id = ?, stripe_customer_id = ?, subscription_status = "active", chapter_limit = NULL WHERE user_id = ? AND course_id = ?');
            $stmt->execute([$startDate, $endDate, $grantedAt, $subscriptionId, $customerId, $user->user_id, $courseId]);
        } else {
            // Skapa ny access
            $stmt = $db->prepare('INSERT INTO sprakapp_user_course_access (user_id, course_id, granted_at, start_date, end_date, stripe_subscription_id, stripe_customer_id, subscription_status) VALUES (?, ?, ?, ?, ?, ?, ?, "active")');
            $stmt->execute([$user->user_id, $courseId, $grantedAt, $startDate, $endDate, $subscriptionId, $customerId]);
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'All courses for subscription unlocked',
        'course_ids' => $courseIds
    ]);
    
} catch (Exception $e) {
    error_log('Payment success handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
