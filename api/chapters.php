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
        if (isset($_GET['id'])) {
            getChapter($db, $_GET['id']);
        } else {
            getChapters($db);
        }
        break;
    case 'POST':
        $decoded = SessionAuth::requireAdmin();
        createChapter($db);
        break;
    case 'PUT':
        $decoded = SessionAuth::requireAdmin();
        updateChapter($db, $_GET['id']);
        break;
    case 'DELETE':
        $decoded = SessionAuth::requireAdmin();
        deleteChapter($db, $_GET['id']);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getChapters($db) {
    $courseId = $_GET['course_id'] ?? null;
    
    if (!$courseId) {
        sendError('course_id parameter is required', 400);
        return;
    }
    
    try {
        // Get user (allow unauthenticated for public courses)
        $user = SessionAuth::getUser();
        $userId = $user ? $user->user_id : null;
        
        // Fetch all chapters for the course
        $query = "SELECT * FROM sprakapp_chapters WHERE course_id = :course_id ORDER BY order_number ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $courseId);
        $stmt->execute();
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If user is authenticated, add access info to each chapter
        if ($userId) {
            try {
                $courseAccess = AccessControl::checkCourseAccess($db, $userId, $courseId);
                $chapterLimit = $courseAccess['chapter_limit'];
                
                // Add access info to each chapter
                foreach ($chapters as &$chapter) {
                    $chapter['is_accessible'] = ($chapterLimit === null || $chapter['order_number'] <= $chapterLimit);
                }
            } catch (Exception $e) {
                // No access to course, return empty array
                $chapters = [];
            }
        } else {
            // For unauthenticated users, check if course is free
            try {
                AccessControl::checkCourseAccess($db, null, $courseId);
                // Course is free, mark all chapters as accessible
                foreach ($chapters as &$chapter) {
                    $chapter['is_accessible'] = true;
                }
            } catch (Exception $e) {
                // Course requires payment, return empty array
                $chapters = [];
            }
        }
        
        sendSuccess($chapters);
        
    } catch (Exception $e) {
        sendError('Failed to fetch chapters: ' . $e->getMessage(), 500);
    }
}

function getChapter($db, $id) {
    try {
        // Get user (required for single chapter access)
        $user = SessionAuth::getUser();
        $userId = $user ? $user->user_id : null;
        
        // Get chapter
        $query = "SELECT * FROM sprakapp_chapters WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $chapter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$chapter) {
            sendError('Chapter not found', 404);
            return;
        }
        
        // Check access to this specific chapter
        if ($userId) {
            try {
                AccessControl::checkChapterAccess($db, $userId, $id);
            } catch (Exception $e) {
                sendError($e->getMessage(), 403);
                return;
            }
        } else {
            // Unauthenticated user - check if course is free
            try {
                AccessControl::checkCourseAccess($db, null, $chapter['course_id']);
            } catch (Exception $e) {
                sendError('Authentication required to access this chapter', 401);
                return;
            }
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
        
        $query = "INSERT INTO sprakapp_chapters (id, course_id, title, description, order_number, target_text, translation, grammar_explanation, image_url, speech_voice_name, audio_file_url, speech_rate) 
                  VALUES (:id, :course_id, :title, :description, :order_number, :target_text, :translation, :grammar_explanation, :image_url, :speech_voice_name, :audio_file_url, :speech_rate)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $chapterId);
        $stmt->bindParam(':course_id', $data->course_id);
        $stmt->bindParam(':title', $data->title);
        $description = $data->description ?? null;
        $stmt->bindParam(':description', $description);
        $order_number = $data->order_number ?? 1;
        $stmt->bindParam(':order_number', $order_number);
        $target_text = $data->target_text ?? null;
        $stmt->bindParam(':target_text', $target_text);
        $translation = $data->translation ?? null;
        $stmt->bindParam(':translation', $translation);
        $grammar_explanation = $data->grammar_explanation ?? null;
        $stmt->bindParam(':grammar_explanation', $grammar_explanation);
        $image_url = $data->image_url ?? null;
        $stmt->bindParam(':image_url', $image_url);
        $speech_voice_name = $data->speech_voice_name ?? null;
        $stmt->bindParam(':speech_voice_name', $speech_voice_name);
        $audio_file_url = $data->audio_file_url ?? null;
        $stmt->bindParam(':audio_file_url', $audio_file_url);
        $speech_rate = $data->speech_rate ?? 1.00;
        $stmt->bindParam(':speech_rate', $speech_rate);
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
        if (isset($data->order_number)) {
            $fields[] = "order_number = :order_number";
            $params[':order_number'] = $data->order_number;
        }
        if (isset($data->target_text)) {
            $fields[] = "target_text = :target_text";
            $params[':target_text'] = $data->target_text;
        }
        if (isset($data->translation)) {
            $fields[] = "translation = :translation";
            $params[':translation'] = $data->translation;
        }
        if (isset($data->grammar_explanation)) {
            $fields[] = "grammar_explanation = :grammar_explanation";
            $params[':grammar_explanation'] = $data->grammar_explanation;
        }
        if (isset($data->image_url)) {
            $fields[] = "image_url = :image_url";
            $params[':image_url'] = $data->image_url;
        }
        if (isset($data->speech_voice_name)) {
            $fields[] = "speech_voice_name = :speech_voice_name";
            $params[':speech_voice_name'] = $data->speech_voice_name;
        }
        if (isset($data->audio_file_url)) {
            $fields[] = "audio_file_url = :audio_file_url";
            $params[':audio_file_url'] = $data->audio_file_url;
        }
        if (isset($data->speech_rate)) {
            $fields[] = "speech_rate = :speech_rate";
            $params[':speech_rate'] = $data->speech_rate;
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
