<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/session-auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe-config.php';

use Middleware\SessionAuth;

// Require authenticated user
$user = SessionAuth::requireAuth();

// Get database connection
$db = getDBConnection();

// Stripe helper function using cURL (no SDK needed)
function createStripeCheckoutSession($courseId, $courseName, $priceMonthly, $currency, $userId, $userEmail) {
    $stripeConfig = require __DIR__ . '/../config/stripe-config.php';
    
    // Convert price to cents (Stripe expects smallest currency unit)
    $priceInCents = (int)($priceMonthly * 100);
    
    // Create checkout session using Stripe API
    $data = [
        'payment_method_types' => ['card'],
        'mode' => 'subscription',
        'line_items' => [[
            'price_data' => [
                'currency' => strtolower($currency),
                'product_data' => [
                    'name' => $courseName,
                    'description' => 'Monthly subscription to ' . $courseName,
                ],
                'recurring' => [
                    'interval' => 'month',
                ],
                'unit_amount' => $priceInCents,
            ],
            'quantity' => 1,
        ]],
        'metadata' => [
            'course_id' => $courseId,
            'user_id' => $userId,
        ],
        'customer_email' => $userEmail,
        'success_url' => getenv('FRONTEND_URL') . '/courses?payment=success&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => getenv('FRONTEND_URL') . '/courses?payment=cancelled',
    ];
    
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripeConfig['secret_key'],
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log('Stripe API error: ' . $response);
        throw new Exception('Failed to create checkout session');
    }
    
    return json_decode($response, true);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'));
        
        if (!isset($data->course_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Course ID is required']);
            exit();
        }
        
        $courseId = $data->course_id;
        
        // Get course details including price
        $stmt = $db->prepare("
            SELECT id, title, price_monthly, currency 
            FROM sprakapp_courses 
            WHERE id = ?
        ");
        $stmt->execute([$courseId]);
        $course = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$course) {
            http_response_code(404);
            echo json_encode(['error' => 'Course not found']);
            exit();
        }
        
        if (!$course->price_monthly || $course->price_monthly <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Course does not have a valid price']);
            exit();
        }
        
        // Get user email
        $stmt = $db->prepare("SELECT email FROM sprakapp_users WHERE id = ?");
        $stmt->execute([$user->user_id]);
        $userEmail = $stmt->fetchColumn();
        
        // Create Stripe checkout session
        $session = createStripeCheckoutSession(
            $courseId,
            $course->title,
            $course->price_monthly,
            $course->currency ?: 'SEK',
            $user->user_id,
            $userEmail
        );
        
        echo json_encode([
            'checkout_url' => $session['url'],
            'session_id' => $session['id']
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log('Checkout error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
