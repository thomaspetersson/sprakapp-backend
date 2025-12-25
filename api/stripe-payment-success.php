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
require_once __DIR__ . '/../config/stripe-config.php';

// Require authenticated user
$user = SessionAuth::requireAuth();

// Get database connection
$database = new Database();
$db = $database->getConnection();
$stripeConfig = require __DIR__ . '/../config/stripe-config.php';

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
    
    // Get course and user info from metadata
    $courseId = $session['metadata']['course_id'] ?? null;
    $userId = $session['metadata']['user_id'] ?? null;
    $subscriptionId = $session['subscription'] ?? null;
    $customerId = $session['customer'] ?? null;
    
    if (!$courseId || !$userId) {
        throw new Exception('Missing course or user information');
    }
    
    // Verify the logged-in user matches the session user
    if ($user->user_id !== $userId) {
        http_response_code(403);
        echo json_encode(['error' => 'User mismatch']);
        exit();
    }
    
    // Calculate dates
    $grantedAt = date('Y-m-d H:i:s');
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+1 month'));
    
    // Check if assignment already exists
    $stmt = $db->prepare("
        SELECT id FROM sprakapp_user_course_access 
        WHERE user_id = ? AND course_id = ?
    ");
    $stmt->execute([$userId, $courseId]);
    $existing = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($existing) {
        // Update existing assignment
        $stmt = $db->prepare("
            UPDATE sprakapp_user_course_access 
            SET start_date = ?, end_date = ?, granted_at = ?,
                stripe_subscription_id = ?, stripe_customer_id = ?,
                subscription_status = 'active', chapter_limit = NULL
            WHERE user_id = ? AND course_id = ?
        ");
        $stmt->execute([$startDate, $endDate, $grantedAt, $subscriptionId, $customerId, $userId, $courseId]);
    } else {
        // Create new assignment
        $stmt = $db->prepare("
            INSERT INTO sprakapp_user_course_access 
            (user_id, course_id, granted_at, start_date, end_date, stripe_subscription_id, stripe_customer_id, subscription_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$userId, $courseId, $grantedAt, $startDate, $endDate, $subscriptionId, $customerId]);
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Course access granted',
        'course_id' => $courseId
    ]);
    
} catch (Exception $e) {
    error_log('Payment success handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
