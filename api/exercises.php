<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/session-auth.php';
require_once __DIR__ . '/../middleware/access-control.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        getExercises($db);
        break;
    case 'POST':
        $decoded = SessionAuth::requireAdmin();
        createExercise($db);
        break;
    case 'PUT':
        $decoded = SessionAuth::requireAdmin();
        updateExercise($db, $_GET['id']);
        break;
    case 'DELETE':
        $decoded = SessionAuth::requireAdmin();
        deleteExercise($db, $_GET['id']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getExercises($db) {
    $chapterId = $_GET['chapter_id'] ?? null;
    
    if (!$chapterId) {
        sendError('chapter_id required', 400);
        return;
    }
    
    try {
        // Get user
        $user = SessionAuth::getUser();
        $userId = $user ? $user->user_id : null;
        
        // Check access to chapter before returning exercises
        if ($userId) {
            try {
                AccessControl::checkChapterAccess($db, $userId, $chapterId);
            } catch (Exception $e) {
                sendError($e->getMessage(), 403);
                return;
            }
        } else {
            // For unauthenticated users, get chapter's course and check if free
            $stmt = $db->prepare("SELECT course_id FROM sprakapp_chapters WHERE id = ?");
            $stmt->execute([$chapterId]);
            $chapter = $stmt->fetch(PDO::FETCH_OBJ);
            
            if (!$chapter) {
                sendError('Chapter not found', 404);
                return;
            }
            
            try {
                AccessControl::checkCourseAccess($db, null, $chapter->course_id);
            } catch (Exception $e) {
                sendError('Authentication required to access this content', 401);
                return;
            }
        }
        
        $query = "SELECT * FROM sprakapp_exercises WHERE chapter_id = :chapter_id ORDER BY order_index ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':chapter_id', $chapterId);
        $stmt->execute();
        
        $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON options
        foreach ($exercises as &$exercise) {
            if ($exercise['options']) {
                $exercise['options'] = json_decode($exercise['options']);
            }
        }
        
        sendSuccess($exercises);
        
    } catch (Exception $e) {
        sendError('Failed to fetch exercises: ' . $e->getMessage(), 500);
    }
}

function createExercise($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->chapter_id) || !isset($data->type) || !isset($data->question) || !isset($data->correct_answer)) {
        sendError('chapter_id, type, question, and correct_answer are required', 400);
    }

    try {
        $exerciseId = bin2hex(random_bytes(16));
        
        $query = "INSERT INTO sprakapp_exercises (id, chapter_id, type, question, correct_answer, options, explanation, order_index) 
                  VALUES (:id, :chapter_id, :type, :question, :correct_answer, :options, :explanation, :order_index)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $exerciseId);
        $stmt->bindParam(':chapter_id', $data->chapter_id);
        $stmt->bindParam(':type', $data->type);
        $stmt->bindParam(':question', $data->question);
        $stmt->bindParam(':correct_answer', $data->correct_answer);
        $options = isset($data->options) ? json_encode($data->options) : null;
        $stmt->bindParam(':options', $options);
        $explanation = $data->explanation ?? null;
        $stmt->bindParam(':explanation', $explanation);
        $order_index = $data->order_index ?? 0;
        $stmt->bindParam(':order_index', $order_index);
        $stmt->execute();
        
        sendSuccess(['id' => $exerciseId, 'message' => 'Exercise created'], 201);
        
    } catch (Exception $e) {
        sendError('Failed to create exercise: ' . $e->getMessage(), 500);
    }
}

function updateExercise($db, $id) {
    $data = json_decode(file_get_contents("php://input"));
    
    try {
        $fields = [];
        $params = [':id' => $id];
        
        if (isset($data->type)) {
            $fields[] = "type = :type";
            $params[':type'] = $data->type;
        }
        if (isset($data->question)) {
            $fields[] = "question = :question";
            $params[':question'] = $data->question;
        }
        if (isset($data->correct_answer)) {
            $fields[] = "correct_answer = :correct_answer";
            $params[':correct_answer'] = $data->correct_answer;
        }
        if (isset($data->options)) {
            $fields[] = "options = :options";
            $params[':options'] = json_encode($data->options);
        }
        if (isset($data->explanation)) {
            $fields[] = "explanation = :explanation";
            $params[':explanation'] = $data->explanation;
        }
        if (isset($data->order_index)) {
            $fields[] = "order_index = :order_index";
            $params[':order_index'] = $data->order_index;
        }
        
        if (empty($fields)) {
            sendError('No fields to update', 400);
        }
        
        $query = "UPDATE sprakapp_exercises SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        sendSuccess(['message' => 'Exercise updated']);
        
    } catch (Exception $e) {
        sendError('Failed to update exercise: ' . $e->getMessage(), 500);
    }
}

function deleteExercise($db, $id) {
    try {
        $query = "DELETE FROM sprakapp_exercises WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        sendSuccess(['message' => 'Exercise deleted']);
        
    } catch (Exception $e) {
        sendError('Failed to delete exercise: ' . $e->getMessage(), 500);
    }
}
