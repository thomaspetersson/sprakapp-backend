<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Find thomas user
    $stmt = $db->prepare("SELECT id, email FROM sprakapp_users WHERE email = 'thomas@d90.se' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "ERROR: User not found\n";
        exit;
    }
    
    echo "=== USER thomas@d90.se ===\n";
    echo "ID: " . $user['id'] . "\n\n";
    
    // Find French course
    $stmt = $db->prepare("SELECT id, title FROM sprakapp_courses WHERE title LIKE '%French%' LIMIT 1");
    $stmt->execute();
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$course) {
        echo "ERROR: French course not found\n";
        exit;
    }
    
    echo "=== FRENCH COURSE ===\n";
    echo "ID: " . $course['id'] . "\n";
    echo "Title: " . $course['title'] . "\n\n";
    
    // Check user_course_access
    echo "=== USER COURSE ACCESS TABLE ===\n";
    $stmt = $db->prepare("SELECT * FROM sprakapp_user_course_access WHERE user_id = ? AND course_id = ?");
    $stmt->execute([$user['id'], $course['id']]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($access) {
        echo "Access record found:\n";
        foreach ($access as $key => $value) {
            echo "  " . $key . ": " . var_export($value, true) . "\n";
        }
        
        if ($access['chapter_limit'] && $access['chapter_limit'] < 20) {
            echo "\n*** PROBLEM: chapter_limit is " . $access['chapter_limit'] . " ***\n";
            echo "It should be NULL (unlimited) for paid subscriptions\n\n";
            
            // Fix it
            echo "Fixing chapter_limit to NULL (unlimited)...\n";
            $stmt = $db->prepare("UPDATE sprakapp_user_course_access SET chapter_limit = NULL WHERE id = ?");
            $stmt->execute([$access['id']]);
            echo "✓ Fixed! Thomas now has unlimited access to all chapters.\n";
        }
    } else {
        echo "No access record found\n";
        echo "User will get trial access (5 chapters by default)\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
