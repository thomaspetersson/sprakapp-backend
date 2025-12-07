<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        getExercises($db);
        break;
    case 'POST':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        createExercise($db);
        break;
    case 'PUT':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        updateExercise($db, $_GET['id']);
        break;
    case 'DELETE':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
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
        http_response_code(400);
        echo json_encode(['error' => 'chapter_id required']);
        return;
    }
    
    try {
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
        
        http_response_code(200);
        echo json_encode(['exercises' => $exercises]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch exercises: ' . $e->getMessage()]);
    }
}

function createExercise($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->chapter_id) || !isset($data->type) || !isset($data->question) || !isset($data->correct_answer)) {
        http_response_code(400);
        echo json_encode(['error' => 'chapter_id, type, question, and correct_answer are required']);
        return;
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
        
        http_response_code(201);
        echo json_encode(['id' => $exerciseId, 'message' => 'Exercise created']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create exercise: ' . $e->getMessage()]);
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
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE sprakapp_exercises SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        http_response_code(200);
        echo json_encode(['message' => 'Exercise updated']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update exercise: ' . $e->getMessage()]);
    }
}

function deleteExercise($db, $id) {
    try {
        $query = "DELETE FROM sprakapp_exercises WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        http_response_code(200);
        echo json_encode(['message' => 'Exercise deleted']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete exercise: ' . $e->getMessage()]);
    }
}
