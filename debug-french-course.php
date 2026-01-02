<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Find ALL courses first
    echo "=== ALL COURSES ===\n";
    $stmt = $db->prepare("SELECT id, title, language, is_published FROM sprakapp_courses");
    $stmt->execute();
    $allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allCourses as $c) {
        echo "[" . $c['id'] . "] " . $c['language'] . ": " . $c['title'] . " (published: " . $c['is_published'] . ")\n";
    }
    
    // Find French course by title
    echo "\n=== FRENCH COURSE (by title) ===\n";
    $stmt = $db->prepare("SELECT id, title, language, is_published FROM sprakapp_courses WHERE title LIKE '%French%' LIMIT 1");
    $stmt->execute();
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($course) {
        echo "Course ID: " . $course['id'] . "\n";
        echo "Title: " . $course['title'] . "\n";
        echo "Language column value: " . $course['language'] . "\n";
        echo "is_published: " . var_export($course['is_published'], true) . " (0=NOT PUBLISHED, 1=PUBLISHED)\n";
        
        echo "\n=== CHAPTERS FOR THIS COURSE ===\n";
        $stmt = $db->prepare("SELECT id, title, order_number FROM sprakapp_chapters WHERE course_id = ? ORDER BY order_number ASC");
        $stmt->execute([$course['id']]);
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Total chapters: " . count($chapters) . "\n";
        if (count($chapters) > 0) {
            foreach ($chapters as $ch) {
                echo "  Ch " . $ch['order_number'] . ": " . $ch['title'] . "\n";
            }
        }
        
        if ($course['is_published'] == 0) {
            echo "\n*** PROBLEM FOUND: Course is NOT PUBLISHED (is_published = 0) ***\n";
            echo "This is why chapters are not visible to users!\n";
        }
    } else {
        echo "No French course found with any common language codes\n";
    }
    
    // Check Thomas's user
    echo "\n=== USER thomas@d90.se ===\n";
    $stmt = $db->prepare("SELECT id, email FROM sprakapp_users WHERE email = 'thomas@d90.se' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "User ID: " . $user['id'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        
        // Check subscription
        echo "\n=== SUBSCRIPTION ===\n";
        $stmt = $db->prepare("SELECT * FROM sprakapp_stripe_subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sub) {
            echo "Status: " . $sub['status'] . "\n";
            echo "Courses: " . $sub['courses'] . "\n";
            echo "Created: " . $sub['created_at'] . "\n";
        } else {
            echo "No subscription found\n";
        }
    } else {
        echo "User not found\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
