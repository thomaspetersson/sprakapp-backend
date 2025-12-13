<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

// Helper function to cancel Stripe subscription
function cancelStripeSubscription($subscriptionId, $secretKey) {
    $ch = curl_init("https://api.stripe.com/v1/subscriptions/{$subscriptionId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log('Stripe API error (HTTP ' . $httpCode . '): ' . $response);
        if ($curlError) {
            error_log('cURL error: ' . $curlError);
        }
        throw new Exception('Failed to cancel subscription: ' . $response);
    }
    
    return json_decode($response, true);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // Get user's subscriptions
        $stmt = $db->prepare("
            SELECT 
                uca.id,
                uca.course_id,
                c.title as course_name,
                c.price_monthly,
                c.currency,
                uca.start_date,
                uca.end_date,
                uca.stripe_subscription_id,
                uca.subscription_status
            FROM sprakapp_user_course_access uca
            JOIN sprakapp_courses c ON uca.course_id = c.id
            WHERE uca.user_id = ?
            AND uca.stripe_subscription_id IS NOT NULL
            ORDER BY uca.subscription_status = 'active' DESC, uca.start_date DESC
        ");
        
        $stmt->execute([$user->id]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        http_response_code(200);
        echo json_encode([
            'subscriptions' => $subscriptions
        ]);
        
    } elseif ($method === 'DELETE') {
        // Cancel subscription
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['subscription_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'subscription_id is required']);
            exit();
        }
        
        $subscriptionId = $data['subscription_id'];
        
        // Verify that this subscription belongs to the user
        $stmt = $db->prepare("
            SELECT id, stripe_subscription_id 
            FROM sprakapp_user_course_access 
            WHERE user_id = ? AND stripe_subscription_id = ?
        ");
        $stmt->execute([$user->id, $subscriptionId]);
        $access = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$access) {
            http_response_code(404);
            echo json_encode(['error' => 'Subscription not found']);
            exit();
        }
        
        // Cancel subscription in Stripe
        try {
            $cancelledSubscription = cancelStripeSubscription($subscriptionId, $stripeConfig['secret_key']);
            
            // Update local database - set status to cancelled
            // Note: The webhook will also update this, but we do it here for immediate feedback
            $stmt = $db->prepare("
                UPDATE sprakapp_user_course_access 
                SET subscription_status = 'cancelled'
                WHERE id = ?
            ");
            $stmt->execute([$access->id]);
            
            http_response_code(200);
            echo json_encode([
                'message' => 'Subscription cancelled successfully',
                'subscription' => $cancelledSubscription
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
