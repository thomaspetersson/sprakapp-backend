<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe-config.php';
require_once __DIR__ . '/../lib/ActivityLogger.php';

$activityLogger = new ActivityLogger();

// Custom logging to file
function logWebhook($message) {
    $logFile = __DIR__ . '/webhook-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message); // Also log to PHP error log
}

// Get database connection
$database = new Database();
$db = $database->getConnection();
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
        logWebhook("assignCourseAccess called: userId=$userId, courseId=$courseId, subscriptionId=$subscriptionId, customerId=$customerId");
        
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
        
        logWebhook("Existing assignment: " . ($existing ? "YES (id={$existing->id})" : "NO"));
        
        if ($existing) {
            // Update existing assignment - remove chapter limit for paid access
            $stmt = $db->prepare("
                UPDATE sprakapp_user_course_access 
                SET start_date = ?, end_date = ?, granted_at = ?,
                    stripe_subscription_id = ?, stripe_customer_id = ?,
                    subscription_status = 'active', chapter_limit = NULL
                WHERE user_id = ? AND course_id = ?
            ");
            $result = $stmt->execute([$startDate, $endDate, $grantedAt, $subscriptionId, $customerId, $userId, $courseId]);
            logWebhook("UPDATE result: " . ($result ? "SUCCESS" : "FAILED"));
        } else {
            // Create new assignment
            $stmt = $db->prepare("
                INSERT INTO sprakapp_user_course_access 
                (user_id, course_id, granted_at, start_date, end_date, stripe_subscription_id, stripe_customer_id, subscription_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $result = $stmt->execute([$userId, $courseId, $grantedAt, $startDate, $endDate, $subscriptionId, $customerId]);
            logWebhook("INSERT result: " . ($result ? "SUCCESS" : "FAILED"));
        }
        
        return true;
    } catch (Exception $e) {
        logWebhook('Failed to assign course access: ' . $e->getMessage());
        logWebhook('Stack trace: ' . $e->getTraceAsString());
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
            // Payment successful - update subscription status and grant course access
            $session = $event->data->object;
            $userSubscriptionId = $session->metadata->user_subscription_id ?? null;
            $subscriptionId = $session->subscription;
            $customerId = $session->customer;
            
            logWebhook("checkout.session.completed: userSubscriptionId=$userSubscriptionId, stripeSubscriptionId=$subscriptionId");
            
            if ($userSubscriptionId) {
                // New flow: Update subscription status to active and set stripe_subscription_id
                $stmt = $db->prepare('UPDATE sprakapp_user_subscriptions SET status = "active", stripe_subscription_id = ? WHERE id = ?');
                $result = $stmt->execute([$subscriptionId, $userSubscriptionId]);
                logWebhook("Updated subscription $userSubscriptionId to active: " . ($result ? "SUCCESS" : "FAILED"));
                
                // Get user_id and course_ids from subscription
                $stmt = $db->prepare('SELECT user_id FROM sprakapp_user_subscriptions WHERE id = ?');
                $stmt->execute([$userSubscriptionId]);
                $sub = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($sub) {
                    $userId = $sub['user_id'];
                    
                    // Get user email for logging
                    $stmtUser = $db->prepare('SELECT email FROM sprakapp_users WHERE id = ?');
                    $stmtUser->execute([$userId]);
                    $userEmail = $stmtUser->fetchColumn();
                    
                    // Get plan name
                    $stmtPlan = $db->prepare('SELECT p.name FROM sprakapp_user_subscriptions us JOIN sprakapp_subscription_plans p ON us.plan_id = p.id WHERE us.id = ?');
                    $stmtPlan->execute([$userSubscriptionId]);
                    $planName = $stmtPlan->fetchColumn();
                    
                    // Grant access to all chosen courses
                    $stmt = $db->prepare('SELECT course_id FROM sprakapp_user_subscription_courses WHERE user_subscription_id = ?');
                    $stmt->execute([$userSubscriptionId]);
                    $courses = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($courses as $courseId) {
                        assignCourseAccess($db, $userId, $courseId, $subscriptionId, $customerId);
                        logWebhook("Granted access: User $userId -> Course $courseId");
                        
                        // Get course name
                        $stmtCourse = $db->prepare('SELECT title FROM sprakapp_courses WHERE id = ?');
                        $stmtCourse->execute([$courseId]);
                        $courseTitle = $stmtCourse->fetchColumn();
                        
                        // Log course access granted
                        global $activityLogger;
                        $activityLogger->courseAccessGranted($userId, $userEmail, $courseId, $courseTitle, [
                            'reason' => 'subscription_payment',
                            'subscription_id' => $subscriptionId,
                            'plan_name' => $planName
                        ]);
                    }
                    
                    // Log subscription created
                    $activityLogger->subscriptionCreated($userId, $userEmail, $subscriptionId, $planName, count($courses));
                    
                    // Log payment success
                    $amount = $session->amount_total / 100; // Convert from cents
                    $currency = strtoupper($session->currency);
                    $activityLogger->paymentSuccess($userId, $userEmail, $subscriptionId, $amount, $currency);
                }
            } else {
                // Old flow: single course assignment (deprecated)
                $courseId = $session->metadata->course_id ?? null;
                $userId = $session->metadata->user_id ?? null;
                
                if ($userId && $courseId) {
                    if (assignCourseAccess($db, $userId, $courseId, $subscriptionId, $customerId)) {
                        error_log("Course access granted: User $userId -> Course $courseId (Subscription: $subscriptionId)");
                    } else {
                        error_log("Failed to grant course access: User $userId -> Course $courseId");
                    }
                }
            }
            break;
            
        case 'customer.subscription.deleted':
            // Subscription cancelled - revoke access
            $subscription = $event->data->object;
            $subscriptionId = $subscription->id;
            
            if (revokeCourseAccess($db, $subscriptionId)) {
                error_log("Course access revoked for subscription: $subscriptionId");
                
                // Get user details for logging
                $stmt = $db->prepare('SELECT us.user_id, u.email, p.name as plan_name 
                                     FROM sprakapp_user_subscriptions us 
                                     JOIN sprakapp_users u ON us.user_id = u.id 
                                     JOIN sprakapp_subscription_plans p ON us.plan_id = p.id 
                                     WHERE us.stripe_subscription_id = ?');
                $stmt->execute([$subscriptionId]);
                $subData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($subData) {
                    global $activityLogger;
                    $activityLogger->subscriptionCancelled($subData['user_id'], $subData['email'], $subscriptionId, $subData['plan_name']);
                }
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
                
                // Get user details for logging
                $stmt = $db->prepare('SELECT us.user_id, u.email, p.name as plan_name 
                                     FROM sprakapp_user_subscriptions us 
                                     JOIN sprakapp_users u ON us.user_id = u.id 
                                     JOIN sprakapp_subscription_plans p ON us.plan_id = p.id 
                                     WHERE us.stripe_subscription_id = ?');
                $stmt->execute([$subscriptionId]);
                $subData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($subData) {
                    global $activityLogger;
                    $amount = $invoice->amount_paid / 100;
                    $currency = strtoupper($invoice->currency);
                    
                    $activityLogger->subscriptionRenewed($subData['user_id'], $subData['email'], $subscriptionId, $subData['plan_name']);
                    $activityLogger->paymentSuccess($subData['user_id'], $subData['email'], $subscriptionId, $amount, $currency);
                }
            }
            break;
            
        case 'invoice.payment_failed':
            // Payment failed - optionally notify user or suspend access
            $invoice = $event->data->object;
            $subscriptionId = $invoice->subscription;
            
            // TODO: Optionally suspend access after failed payment
            error_log("Payment failed for subscription: $subscriptionId");
            
            // Get user details for logging
            $stmt = $db->prepare('SELECT us.user_id, u.email 
                                 FROM sprakapp_user_subscriptions us 
                                 JOIN sprakapp_users u ON us.user_id = u.id 
                                 WHERE us.stripe_subscription_id = ?');
            $stmt->execute([$subscriptionId]);
            $subData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($subData) {
                global $activityLogger;
                $amount = $invoice->amount_due / 100;
                $currency = strtoupper($invoice->currency);
                $reason = $invoice->last_payment_error->message ?? 'Unknown error';
                
                $activityLogger->paymentFailed($subData['user_id'], $subData['email'], $subscriptionId, $amount, $currency, $reason);
            }
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
