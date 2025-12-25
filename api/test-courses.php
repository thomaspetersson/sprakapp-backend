<?php
// Simple test to see what's happening
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if status column exists
    $checkQuery = "SHOW COLUMNS FROM sprakapp_courses LIKE 'status'";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute();
    $hasStatusColumn = $checkStmt->rowCount() > 0;
    
    echo "Has status column: " . ($hasStatusColumn ? 'YES' : 'NO') . "\n";
    
    // Try to fetch courses
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
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Number of courses: " . count($courses) . "\n";
    echo "Query used: " . $query . "\n";
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'hasStatusColumn' => $hasStatusColumn,
        'courseCount' => count($courses),
        'data' => $courses
    ]);
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString();
}
