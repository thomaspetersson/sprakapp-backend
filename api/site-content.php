<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/session-auth.php';

$db = new Database();
$conn = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Public endpoint - ingen auth behövs
        $content_key = $_GET['key'] ?? null;
        
        if ($content_key) {
            // Hämta specifik content
            $stmt = $conn->prepare("SELECT * FROM sprakapp_site_content WHERE content_key = ? AND is_active = 1");
            $stmt->execute([$content_key]);
            $content = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($content) {
                echo json_encode(['success' => true, 'content' => $content]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Content not found']);
            }
        } else {
            // Hämta all content
            $stmt = $conn->prepare("SELECT * FROM sprakapp_site_content WHERE is_active = 1 ORDER BY content_key");
            $stmt->execute();
            $contents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'contents' => $contents]);
        }
        
    } elseif ($method === 'POST' || $method === 'PUT') {
        // Kräver admin
        $user = SessionAuth::requireAdmin();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $content_key = $data['content_key'] ?? '';
        $title_sv = $data['title_sv'] ?? '';
        $title_en = $data['title_en'] ?? '';
        $description_sv = $data['description_sv'] ?? '';
        $description_en = $data['description_en'] ?? '';
        $content_sv = $data['content_sv'] ?? '';
        $content_en = $data['content_en'] ?? '';
        $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
        
        if (!$content_key) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'content_key is required']);
            exit;
        }
        
        // Kolla om content redan finns
        $check_stmt = $conn->prepare("SELECT id FROM sprakapp_site_content WHERE content_key = ?");
        $check_stmt->execute([$content_key]);
        $exists = $check_stmt->fetch();
        
        if ($exists) {
            // Update
            $stmt = $conn->prepare("
                UPDATE sprakapp_site_content 
                SET title_sv = ?, title_en = ?, description_sv = ?, description_en = ?, 
                    content_sv = ?, content_en = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                WHERE content_key = ?
            ");
            $stmt->execute([
                $title_sv, $title_en, $description_sv, $description_en,
                $content_sv, $content_en, $is_active, $content_key
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Content updated successfully']);
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO sprakapp_site_content 
                (content_key, title_sv, title_en, description_sv, description_en, content_sv, content_en, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $content_key, $title_sv, $title_en, $description_sv, $description_en,
                $content_sv, $content_en, $is_active
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Content created successfully']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
