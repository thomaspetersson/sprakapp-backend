<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Direct production DB connection
$host = 'd90.se.mysql';
$db_name = 'd90_sed90';
$username = 'd90_sed90';
$password = 'd3d407b65fb9d58cf6000f9662887f62';
$port = '3306';

try {
    $db = new PDO(
        "mysql:host=" . $host . 
        ";port=" . $port .
        ";dbname=" . $db_name,
        $username,
        $password
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("set names utf8mb4");
    
    // Find French course
    echo "=== FRENCH COURSE ===\n";
    $stmt = $db->prepare("DESCRIBE sprakapp_chapters");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns in sprakapp_chapters:\n";
    foreach ($columns as $col) {
        echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
    echo "\n=== FRENCH COURSE DATA ===\n";
    $stmt = $db->prepare("SELECT id, title, language, is_published FROM sprakapp_courses WHERE language = 'fr' LIMIT 1");
    $stmt->execute();
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($course) {
        echo "Course ID: " . $course['id'] . "\n";
        echo "Title: " . $course['title'] . "\n";
        echo "Language: " . $course['language'] . "\n";
        echo "is_published: " . var_export($course['is_published'], true) . "\n";
        
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
    } else {
        echo "No French course found\n";
        
        // Show all courses
        echo "\n=== ALL COURSES ===\n";
        $stmt = $db->prepare("SELECT id, title, language FROM sprakapp_courses");
        $stmt->execute();
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($courses as $c) {
            echo $c['language'] . ": " . $c['title'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
