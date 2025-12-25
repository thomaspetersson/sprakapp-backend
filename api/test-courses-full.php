<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

echo "Starting...\n";

try {
    echo "Loading config...\n";
    require_once __DIR__ . '/../config/config.php';
    echo "Config loaded\n";
    
    echo "Loading session-auth...\n";
    require_once __DIR__ . '/../middleware/session-auth.php';
    echo "Session-auth loaded\n";
    
    echo "Creating database...\n";
    $database = new Database();
    $db = $database->getConnection();
    echo "Database connected\n";
    
    // Test the same query as getCourses
    $checkQuery = "SHOW COLUMNS FROM sprakapp_courses LIKE 'status'";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute();
    $hasStatusColumn = $checkStmt->rowCount() > 0;
    
    echo "Has status: " . ($hasStatusColumn ? 'YES' : 'NO') . "\n";
    
    if ($hasStatusColumn) {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM sprakapp_chapters WHERE course_id = c.id) as chapter_count
                  FROM sprakapp_courses c 
                  WHERE c.status = 'published'
                  ORDER BY c.order_index ASC, c.created_at DESC";
    } else {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM sprakapp_chapters WHERE course_id = c.id) as chapter_count
                  FROM sprakapp_courses c 
                  WHERE c.is_published = 1
                  ORDER BY c.order_index ASC, c.created_at DESC";
    }
    
    echo "Executing query...\n";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($courses) . " courses\n";
    
    // Now use sendSuccess like the real function
    echo "Calling sendSuccess...\n";
    sendSuccess($courses);
    
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
