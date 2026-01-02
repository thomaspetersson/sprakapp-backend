<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/middleware/access-control.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Find thomas user
    $stmt = $db->prepare("SELECT id, email FROM sprakapp_users WHERE email = 'thomas@d90.se' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "ERROR: User thomas@d90.se not found\n";
        exit;
    }
    
    echo "=== USER ===\n";
    echo "ID: " . $user['id'] . "\n";
    echo "Email: " . $user['email'] . "\n";
    
    // Find French course
    $stmt = $db->prepare("SELECT id, title, status FROM sprakapp_courses WHERE title LIKE '%French%' LIMIT 1");
    $stmt->execute();
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$course) {
        echo "\nERROR: French course not found\n";
        exit;
    }
    
    echo "\n=== FRENCH COURSE ===\n";
    echo "ID: " . $course['id'] . "\n";
    echo "Title: " . $course['title'] . "\n";
    echo "status: " . $course['status'] . "\n";
    
    if ($course['status'] !== 'published') {
        echo "*** WARNING: Course status is '" . $course['status'] . "' (not 'published') ***\n";
    }
    
    // Check if user has access to the course
    echo "\n=== ACCESS CHECK ===\n";
    try {
        $access = AccessControl::checkCourseAccess($db, $user['id'], $course['id']);
        echo "✓ User HAS ACCESS to course\n";
        echo "Access type: " . $access['type'] . "\n";
        echo "Chapter limit: " . ($access['chapter_limit'] ?? 'NULL (unlimited)') . "\n";
        
        if ($access['chapter_limit'] !== null) {
            echo "\n*** PROBLEM: Chapter limit is set to " . $access['chapter_limit'] . " ***\n";
            echo "This means user can only see chapters 1-" . $access['chapter_limit'] . "\n";
        }
    } catch (Exception $e) {
        echo "✗ User DOES NOT HAVE ACCESS\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
    
    // Count chapters
    echo "\n=== CHAPTERS ===\n";
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM sprakapp_chapters WHERE course_id = ?");
    $stmt->execute([$course['id']]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total chapters in database: " . $count['total'] . "\n";
    
    // List first 5 chapters
    $stmt = $db->prepare("SELECT id, title, order_number FROM sprakapp_chapters WHERE course_id = ? ORDER BY order_number ASC LIMIT 5");
    $stmt->execute([$course['id']]);
    $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($chapters) > 0) {
        echo "\nFirst 5 chapters:\n";
        foreach ($chapters as $ch) {
            echo "  " . $ch['order_number'] . ". " . $ch['title'] . "\n";
        }
    }
    
    // Check user's subscriptions and courses
    echo "\n=== USER'S SUBSCRIPTION ===\n";
    $stmt = $db->prepare("SELECT * FROM sprakapp_stripe_subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sub) {
        echo "Subscription ID: " . $sub['id'] . "\n";
        echo "Status: " . $sub['status'] . "\n";
        echo "Plan ID: " . $sub['plan_id'] . "\n";
        echo "Courses: " . $sub['courses'] . "\n";
        
        $courseIds = json_decode($sub['courses'], true);
        if (is_array($courseIds)) {
            echo "\nSubscribed course IDs:\n";
            foreach ($courseIds as $cid) {
                echo "  - " . $cid . "\n";
            }
            
            if (in_array($course['id'], $courseIds)) {
                echo "\n✓ French course IS in subscription\n";
            } else {
                echo "\n✗ French course IS NOT in subscription\n";
                echo "*** THIS IS THE PROBLEM ***\n";
            }
        }
    } else {
        echo "No active subscription found\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
