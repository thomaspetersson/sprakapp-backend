<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Find French course
    $stmt = $db->prepare("SELECT id, title, status, is_published FROM sprakapp_courses WHERE title LIKE '%French%' LIMIT 1");
    $stmt->execute();
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($course) {
        echo "Found course: " . $course['title'] . "\n";
        echo "Current status: " . ($course['status'] ?? 'NO STATUS COLUMN') . "\n";
        echo "Current is_published: " . ($course['is_published'] ?? 'NO IS_PUBLISHED COLUMN') . "\n\n";
        
        // Set status to 'published' (the correct way)
        $stmt = $db->prepare("UPDATE sprakapp_courses SET status = 'published' WHERE id = ?");
        $stmt->execute([$course['id']]);
        echo "✓ Course status set to 'published'\n";
        
        // Also set is_published to 1 if column exists (for backwards compatibility until migration runs)
        try {
            $stmt = $db->prepare("UPDATE sprakapp_courses SET is_published = 1 WHERE id = ?");
            $stmt->execute([$course['id']]);
            echo "✓ Also set is_published = 1 (for backwards compatibility)\n";
        } catch (Exception $e) {
            echo "Note: is_published column may not exist (that's OK if migration already ran)\n";
        }
        
        echo "\nCourse is now published!\n";
    } else {
        echo "ERROR: No French course found\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
