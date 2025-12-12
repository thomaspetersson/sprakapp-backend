<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe-config.php';

// Get database connection
$db = getDBConnection();
$stripeConfig = require __DIR__ . '/../config/stripe-config.php';

// Helper function to verify Stripe webhook signature
function verifyStripeSignature($payload, $signature, $secret) {
    $elements = explode(',', $signature);
    $timestamp = null;
    $signatureHash = null;
    
    foreach ($elements as $element) {
        list($key, $value) = explode('=', $element, 2);
        if ($key === 't') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $signatureHash = $value;
        }
    }
    
    if (!$timestamp || !$signatureHash) {
        return false;
    }
    
    // Check timestamp is within 5 minutes
    if (abs(time() - $timestamp) > 300) {
        return false;
    }
    
    $signedPayload = $timestamp . '.' . $payload;
    $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);
    
    return hash_equals($expectedSignature, $signatureHash);
}

// Helper function to assign course access
function assignCourseAccess($db, $userId, $courseId, $subscriptionId = null, $customerId = null) {
    try {
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
                    subscription_status = 'active'
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
        
        return true;
    } catch (Exception $e) {
        error_log('Failed to assign course access: ' . $e->getMessage());
        return false;
    }
}

// Helper function to revoke course access
function revokeCourseAccess($db, $subscriptionId) {
    try {
        $stmt = $db->prepare("
            UPDATE sprakapp_user_course_access 
            SET subscription_status = 'cancelled',
                end_date = CURDATE()
            WHERE stripe_subscription_id = ?
        ");
        $stmt->execute([$subscriptionId]);
        return true;
    } catch (Exception $e) {
        error_log('Failed to revoke course access: ' . $e->getMessage());
        return false;
    }
}

// Helper function to extend course access
function extendCourseAccess($db, $subscriptionId) {
    try {
        // Extend end_date by 1 month from current end_date
        $stmt = $db->prepare("
            UPDATE sprakapp_user_course_access 
            SET end_date = DATE_ADD(end_date, INTERVAL 1 MONTH),
                subscription_status = 'active'
            WHERE stripe_subscription_id = ?
        ");
        $stmt->execute([$subscriptionId]);
        return true;
    } catch (Exception $e) {
        error_log('Failed to extend course access: ' . $e->getMessage());
        return false;
    }
}

try {
    // Get raw POST body
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    // Verify webhook signature
    if (!verifyStripeSignature($payload, $signature, $stripeConfig['webhook_secret'])) {
        error_log('Invalid Stripe webhook signature');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit();
    }
    
    // Parse event
    $event = json_decode($payload);
    
    if (!$event) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit();
    }
    
    // Handle different event types
    switch ($event->type) {
        case 'checkout.session.completed':
            // Payment successful - grant course access
            $session = $event->data->object;
            $courseId = $session->metadata->course_id;
            $userId = $session->metadata->user_id;
            $subscriptionId = $session->subscription;
            $customerId = $session->customer;
            
            if (assignCourseAccess($db, $userId, $courseId, $subscriptionId, $customerId)) {
                error_log("Course access granted: User $userId -> Course $courseId (Subscription: $subscriptionId)");
            } else {
                error_log("Failed to grant course access: User $userId -> Course $courseId");
            }
            break;
            
        case 'customer.subscription.deleted':
            // Subscription cancelled - revoke access
            $subscription = $event->data->object;
            $subscriptionId = $subscription->id;
            
            if (revokeCourseAccess($db, $subscriptionId)) {
                error_log("Course access revoked for subscription: $subscriptionId");
            } else {
                error_log("Failed to revoke course access for subscription: $subscriptionId");
            }
            break;
            
        case 'invoice.payment_succeeded':
            // Recurring payment successful - extend access by 1 month
            $invoice = $event->data->object;
            $subscriptionId = $invoice->subscription;
            
            if ($subscriptionId && extendCourseAccess($db, $subscriptionId)) {
                error_log("Course access extended for subscription: $subscriptionId");
            }
            break;
            
        case 'invoice.payment_failed':
            // Payment failed - optionally notify user or suspend access
            $invoice = $event->data->object;
            $subscriptionId = $invoice->subscription;
            
            // TODO: Optionally suspend access after failed payment
            error_log("Payment failed for subscription: $subscriptionId");
            break;
            
        default:
            error_log('Unhandled webhook event: ' . $event->type);
    }
    
    // Return 200 to acknowledge receipt
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    error_log('Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
