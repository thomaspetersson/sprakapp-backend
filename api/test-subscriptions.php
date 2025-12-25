<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

try {
    // Test 1: Check all user_course_access with subscription data
    echo "=== ALL USER COURSE ACCESS WITH SUBSCRIPTIONS ===\n";
    $query = "SELECT user_id, course_id, subscription_status, stripe_subscription_id, start_date, end_date 
              FROM sprakapp_user_course_access 
              WHERE stripe_subscription_id IS NOT NULL";
    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n\n";
    
    // Test 2: Count active subscriptions per user
    echo "=== ACTIVE SUBSCRIPTIONS COUNT PER USER ===\n";
    $query = "SELECT user_id, COUNT(*) as active_count 
              FROM sprakapp_user_course_access 
              WHERE subscription_status = 'active' 
              GROUP BY user_id";
    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n\n";
    
    // Test 3: Test the exact query from getAllProfiles
    echo "=== TEST EXACT QUERY FROM getAllProfiles ===\n";
    $query = "SELECT p.id, p.first_name, p.last_name, u.email, p.role, 
              (SELECT COUNT(*) FROM sprakapp_user_course_access uc WHERE uc.user_id = p.id AND uc.subscription_status = 'active') as active_subscriptions
              FROM sprakapp_profiles p
              LEFT JOIN sprakapp_users u ON p.id = u.id
              ORDER BY p.created_at DESC";
    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString();
}
