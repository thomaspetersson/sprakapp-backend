<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getChapter($db, $_GET['id']);
        } else {
            getChapters($db);
        }
        break;
    case 'POST':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        createChapter($db);
        break;
    case 'PUT':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        updateChapter($db, $_GET['id']);
        break;
    case 'DELETE':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        deleteChapter($db, $_GET['id']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getChapters($db) {
    $courseId = $_GET['course_id'] ?? null;
    
    try {
        if ($courseId) {
            $query = "SELECT * FROM sprakapp_chapters WHERE course_id = :course_id ORDER BY order_index ASC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $courseId);
        } else {
            $query = "SELECT * FROM sprakapp_chapters ORDER BY order_index ASC";
            $stmt = $db->prepare($query);
        }
        
        $stmt->execute();
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($chapters);
        
    } catch (Exception $e) {
        sendError('Failed to fetch chapters: ' . $e->getMessage(), 500);
    }
}

function getChapter($db, $id) {
    try {
        $query = "SELECT * FROM sprakapp_chapters WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $chapter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$chapter) {
            sendError('Chapter not found', 404);
        }
        
        // Get vocabulary
        $query = "SELECT * FROM sprakapp_vocabulary WHERE chapter_id = :chapter_id ORDER BY order_index ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':chapter_id', $id);
        $stmt->execute();
        $chapter['vocabulary'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get exercises
        $query = "SELECT * FROM sprakapp_exercises WHERE chapter_id = :chapter_id ORDER BY order_index ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':chapter_id', $id);
        $stmt->execute();
        $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON options
        foreach ($exercises as &$exercise) {
            if ($exercise['options']) {
                $exercise['options'] = json_decode($exercise['options']);
            }
        }
        $chapter['exercises'] = $exercises;
        
        sendSuccess($chapter);
        
    } catch (Exception $e) {
        sendError('Failed to fetch chapter: ' . $e->getMessage(), 500);
    }
}

function createChapter($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->title) || !isset($data->course_id)) {
        sendError('Title and course_id are required', 400);
    }

    try {
        $chapterId = bin2hex(random_bytes(16));
        
        $query = "INSERT INTO sprakapp_chapters (id, course_id, title, description, order_index, is_published) 
                  VALUES (:id, :course_id, :title, :description, :order_index, :is_published)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $chapterId);
        $stmt->bindParam(':course_id', $data->course_id);
        $stmt->bindParam(':title', $data->title);
        $stmt->bindParam(':description', $data->description);
        $order_index = $data->order_index ?? 0;
        $stmt->bindParam(':order_index', $order_index);
        $is_published = isset($data->is_published) ? (int)$data->is_published : 0;
        $stmt->bindParam(':is_published', $is_published);
        $stmt->execute();
        
        sendSuccess(['id' => $chapterId, 'message' => 'Chapter created'], 201);
        
    } catch (Exception $e) {
        sendError('Failed to create chapter: ' . $e->getMessage(), 500);
    }
}

function updateChapter($db, $id) {
    $data = json_decode(file_get_contents("php://input"));
    
    try {
        $fields = [];
        $params = [':id' => $id];
        
        if (isset($data->title)) {
            $fields[] = "title = :title";
            $params[':title'] = $data->title;
        }
        if (isset($data->description)) {
            $fields[] = "description = :description";
            $params[':description'] = $data->description;
        }
        if (isset($data->order_index)) {
            $fields[] = "order_index = :order_index";
            $params[':order_index'] = $data->order_index;
        }
        if (isset($data->is_published)) {
            $fields[] = "is_published = :is_published";
            $params[':is_published'] = (int)$data->is_published;
        }
        
        if (empty($fields)) {
            sendError('No fields to update', 400);
        }
        
        $query = "UPDATE sprakapp_chapters SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        sendSuccess(['message' => 'Chapter updated']);
        
    } catch (Exception $e) {
        sendError('Failed to update chapter: ' . $e->getMessage(), 500);
    }
}

function deleteChapter($db, $id) {
    try {
        $query = "DELETE FROM sprakapp_chapters WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        sendSuccess(['message' => 'Chapter deleted']);
        
    } catch (Exception $e) {
        sendError('Failed to delete chapter: ' . $e->getMessage(), 500);
    }
}
