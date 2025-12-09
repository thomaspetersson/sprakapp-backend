<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();
$decoded = Auth::verifyToken();

switch ($method) {
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'chapter') {
            getUserChapterStats($db, $decoded->user_id);
        }
        break;
    case 'POST':
        if (isset($_GET['action']) && $_GET['action'] === 'update') {
            updateUserChapterStats($db, $decoded->user_id);
        }
        break;
    default:
        sendError('Method not allowed', 405);
        break;
}

function getUserChapterStats($db, $userId) {
    $chapterId = $_GET['chapter_id'] ?? null;
    
    if (!$chapterId) {
        sendError('chapter_id required', 400);
    }
    
    try {
        $query = "SELECT vocabulary_correct, vocabulary_incorrect, exercises_correct, exercises_incorrect, last_accessed 
                  FROM sprakapp_user_progress 
                  WHERE user_id = :user_id AND chapter_id = :chapter_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':chapter_id', $chapterId);
        $stmt->execute();
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats) {
            // Return default stats if no record exists
            $stats = [
                'vocabulary_correct' => 0,
                'vocabulary_incorrect' => 0,
                'exercises_correct' => 0,
                'exercises_incorrect' => 0,
                'last_accessed' => null
            ];
        }
        
        sendSuccess($stats);
        
    } catch (Exception $e) {
        sendError('Failed to fetch stats: ' . $e->getMessage(), 500);
    }
}

function updateUserChapterStats($db, $userId) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->chapter_id)) {
        sendError('chapter_id required', 400);
    }
    
    try {
        // Check if record exists
        $query = "SELECT id FROM sprakapp_user_progress WHERE user_id = :user_id AND chapter_id = :chapter_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':chapter_id', $data->chapter_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            // Update existing record
            $fields = [];
            $params = [':user_id' => $userId, ':chapter_id' => $data->chapter_id];
            
            if (isset($data->vocabulary_correct)) {
                $fields[] = "vocabulary_correct = vocabulary_correct + :vocabulary_correct";
                $params[':vocabulary_correct'] = $data->vocabulary_correct;
            }
            if (isset($data->vocabulary_incorrect)) {
                $fields[] = "vocabulary_incorrect = vocabulary_incorrect + :vocabulary_incorrect";
                $params[':vocabulary_incorrect'] = $data->vocabulary_incorrect;
            }
            if (isset($data->exercises_correct)) {
                $fields[] = "exercises_correct = exercises_correct + :exercises_correct";
                $params[':exercises_correct'] = $data->exercises_correct;
            }
            if (isset($data->exercises_incorrect)) {
                $fields[] = "exercises_incorrect = exercises_incorrect + :exercises_incorrect";
                $params[':exercises_incorrect'] = $data->exercises_incorrect;
            }
            
            $fields[] = "last_accessed = NOW()";
            
            $query = "UPDATE sprakapp_user_progress SET " . implode(', ', $fields) . " 
                      WHERE user_id = :user_id AND chapter_id = :chapter_id";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
        } else {
            // Create new record
            $query = "INSERT INTO sprakapp_user_progress 
                      (user_id, chapter_id, vocabulary_correct, vocabulary_incorrect, exercises_correct, exercises_incorrect) 
                      VALUES (:user_id, :chapter_id, :vocabulary_correct, :vocabulary_incorrect, :exercises_correct, :exercises_incorrect)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':chapter_id', $data->chapter_id);
            $vocabulary_correct = $data->vocabulary_correct ?? 0;
            $stmt->bindParam(':vocabulary_correct', $vocabulary_correct);
            $vocabulary_incorrect = $data->vocabulary_incorrect ?? 0;
            $stmt->bindParam(':vocabulary_incorrect', $vocabulary_incorrect);
            $exercises_correct = $data->exercises_correct ?? 0;
            $stmt->bindParam(':exercises_correct', $exercises_correct);
            $exercises_incorrect = $data->exercises_incorrect ?? 0;
            $stmt->bindParam(':exercises_incorrect', $exercises_incorrect);
            $stmt->execute();
        }
        
        sendSuccess(['message' => 'Stats updated']);
        
    } catch (Exception $e) {
        sendError('Failed to update stats: ' . $e->getMessage(), 500);
    }
}
