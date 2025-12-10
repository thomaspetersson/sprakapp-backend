<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/session-auth.php'; // TEMPORARILY DISABLED

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        getVocabulary($db);
        break;
    case 'POST':
        $decoded = SessionAuth::requireAdmin();
        createVocabulary($db);
        break;
    case 'PUT':
        $decoded = SessionAuth::requireAdmin();
        updateVocabulary($db, $_GET['id']);
        break;
    case 'DELETE':
        $decoded = SessionAuth::requireAdmin();
        deleteVocabulary($db, $_GET['id']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getVocabulary($db) {
    $chapterId = $_GET['chapter_id'] ?? null;
    
    if (!$chapterId) {
        sendError('chapter_id required', 400);
    }
    
    try {
        $query = "SELECT * FROM sprakapp_vocabulary WHERE chapter_id = :chapter_id ORDER BY order_index ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':chapter_id', $chapterId);
        $stmt->execute();
        
        $vocabulary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($vocabulary);
        
    } catch (Exception $e) {
        sendError('Failed to fetch vocabulary: ' . $e->getMessage(), 500);
    }
}

function createVocabulary($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->chapter_id) || !isset($data->word) || !isset($data->translation)) {
        sendError('chapter_id, word, and translation are required', 400);
    }

    try {
        $vocabId = bin2hex(random_bytes(16));
        
        $query = "INSERT INTO sprakapp_vocabulary (id, chapter_id, word, translation, pronunciation, audio_url, example_sentence, order_index) 
                  VALUES (:id, :chapter_id, :word, :translation, :pronunciation, :audio_url, :example_sentence, :order_index)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $vocabId);
        $stmt->bindParam(':chapter_id', $data->chapter_id);
        $stmt->bindParam(':word', $data->word);
        $stmt->bindParam(':translation', $data->translation);
        $pronunciation = $data->pronunciation ?? null;
        $stmt->bindParam(':pronunciation', $pronunciation);
        $audio_url = $data->audio_url ?? null;
        $stmt->bindParam(':audio_url', $audio_url);
        $example_sentence = $data->example_sentence ?? null;
        $stmt->bindParam(':example_sentence', $example_sentence);
        $order_index = $data->order_index ?? 0;
        $stmt->bindParam(':order_index', $order_index);
        $stmt->execute();
        
        sendSuccess(['id' => $vocabId, 'message' => 'Vocabulary created'], 201);
        
    } catch (Exception $e) {
        sendError('Failed to create vocabulary: ' . $e->getMessage(), 500);
    }
}

function updateVocabulary($db, $id) {
    $data = json_decode(file_get_contents("php://input"));
    
    try {
        $fields = [];
        $params = [':id' => $id];
        
        if (isset($data->word)) {
            $fields[] = "word = :word";
            $params[':word'] = $data->word;
        }
        if (isset($data->translation)) {
            $fields[] = "translation = :translation";
            $params[':translation'] = $data->translation;
        }
        if (isset($data->pronunciation)) {
            $fields[] = "pronunciation = :pronunciation";
            $params[':pronunciation'] = $data->pronunciation;
        }
        if (isset($data->audio_url)) {
            $fields[] = "audio_url = :audio_url";
            $params[':audio_url'] = $data->audio_url;
        }
        if (isset($data->example_sentence)) {
            $fields[] = "example_sentence = :example_sentence";
            $params[':example_sentence'] = $data->example_sentence;
        }
        if (isset($data->order_index)) {
            $fields[] = "order_index = :order_index";
            $params[':order_index'] = $data->order_index;
        }
        
        if (empty($fields)) {
            sendError('No fields to update', 400);
        }
        
        $query = "UPDATE sprakapp_vocabulary SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        sendSuccess(['message' => 'Vocabulary updated']);
        
    } catch (Exception $e) {
        sendError('Failed to update vocabulary: ' . $e->getMessage(), 500);
    }
}

function deleteVocabulary($db, $id) {
    try {
        $query = "DELETE FROM sprakapp_vocabulary WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        sendSuccess(['message' => 'Vocabulary deleted']);
        
    } catch (Exception $e) {
        sendError('Failed to delete vocabulary: ' . $e->getMessage(), 500);
    }
}
