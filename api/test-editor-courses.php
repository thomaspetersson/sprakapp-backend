<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/session-auth.php';

header('Content-Type: text/plain');

echo "=== EDITOR COURSES DEBUG ===\n\n";

try {
    // Get current user
    echo "1. Checking authentication...\n";
    $user = SessionAuth::requireAuth();
    echo "User ID: " . $user->user_id . "\n";
    echo "User Role: " . $user->role . "\n";
    echo "User Email: " . $user->email . "\n\n";
    
    // Check role
    echo "2. Checking role permissions...\n";
    if ($user->role !== 'editor' && $user->role !== 'admin') {
        echo "ERROR: User role is '{$user->role}', not editor or admin!\n";
        exit;
    }
    echo "Role check passed: User is " . $user->role . "\n\n";
    
    // Connect to database
    echo "3. Connecting to database...\n";
    $database = new Database();
    $db = $database->getConnection();
    echo "Database connected\n\n";
    
    // Query for editor courses
    echo "4. Querying for preview and published courses...\n";
    $query = "SELECT c.id, c.title, c.status, c.language,
              (SELECT COUNT(*) FROM sprakapp_chapters WHERE course_id = c.id) as chapter_count
              FROM sprakapp_courses c 
              WHERE c.status IN ('preview', 'published')
              ORDER BY c.order_index ASC, c.created_at DESC";
    
    echo "SQL: " . $query . "\n\n";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($courses) . " courses:\n\n";
    
    foreach ($courses as $course) {
        echo "- ID: {$course['id']}\n";
        echo "  Title: {$course['title']}\n";
        echo "  Status: {$course['status']}\n";
        echo "  Language: {$course['language']}\n";
        echo "  Chapters: {$course['chapter_count']}\n\n";
    }
    
    // Now query ALL courses to see status distribution
    echo "5. Checking all courses and their statuses...\n";
    $queryAll = "SELECT id, title, status FROM sprakapp_courses ORDER BY created_at DESC";
    $stmtAll = $db->prepare($queryAll);
    $stmtAll->execute();
    $allCourses = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    
    $statusCounts = ['draft' => 0, 'preview' => 0, 'published' => 0];
    
    echo "All courses:\n";
    foreach ($allCourses as $course) {
        echo "- {$course['title']}: status = '{$course['status']}'\n";
        if (isset($statusCounts[$course['status']])) {
            $statusCounts[$course['status']]++;
        }
    }
    
    echo "\nStatus distribution:\n";
    echo "- Draft: {$statusCounts['draft']}\n";
    echo "- Preview: {$statusCounts['preview']}\n";
    echo "- Published: {$statusCounts['published']}\n";
    
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
