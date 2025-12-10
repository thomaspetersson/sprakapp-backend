<?php
// Simple test to check PHP and database connection
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$response = [
    'php_version' => phpversion(),
    'timestamp' => date('Y-m-d H:i:s')
];

// Try to load config
try {
    require_once __DIR__ . '/../config/database.php';
    $response['config_loaded'] = true;
    
    $database = new Database();
    $db = $database->getConnection();
    $response['db_connected'] = ($db !== null);
    
    if ($db) {
        // Try a simple query
        $stmt = $db->query("SELECT COUNT(*) as count FROM sprakapp_courses");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $response['course_count'] = $result['count'];
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    $response['trace'] = $e->getTraceAsString();
}

echo json_encode($response, JSON_PRETTY_PRINT);
