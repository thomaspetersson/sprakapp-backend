<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/session-auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

// Check admin access for all endpoints
$user = SessionAuth::requireAdmin();

switch ($method) {
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'profiles') {
            getAllProfiles($db);
        } elseif (isset($_GET['action']) && $_GET['action'] === 'user-courses') {
            getUserCourses($db);
        }
        break;
    case 'POST':
        if (isset($_GET['action']) && $_GET['action'] === 'assign') {
            assignCourseToUser($db);
        }
        break;
    case 'DELETE':
        if (isset($_GET['action']) && $_GET['action'] === 'revoke') {
            revokeCourseFromUser($db);
        }
        break;
    case 'PUT':
        if (isset($_GET['action']) && $_GET['action'] === 'dates') {
            updateUserCourseDates($db);
        }
        break;
    default:
        sendError('Method not allowed', 405);
        break;
}

function getAllProfiles($db) {
    try {
        $query = "SELECT p.id, p.first_name, p.last_name, p.avatar_url, p.role, u.email, p.created_at 
                  FROM sprakapp_profiles p
                  LEFT JOIN sprakapp_users u ON p.id = u.id
                  ORDER BY p.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($profiles);
        
    } catch (Exception $e) {
        sendError('Failed to fetch profiles: ' . $e->getMessage(), 500);
    }
}

function getUserCourses($db) {
    $userId = $_GET['user_id'] ?? null;
    
    if (!$userId) {
        sendError('user_id required', 400);
    }
    
    try {
        $query = "SELECT uc.id, uc.user_id, uc.course_id, uc.start_date, uc.end_date, uc.chapter_limit, uc.granted_at, c.title as course_title 
                  FROM sprakapp_user_course_access uc
                  LEFT JOIN sprakapp_courses c ON uc.course_id = c.id
                  WHERE uc.user_id = :user_id
                  ORDER BY uc.granted_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($courses);
        
    } catch (Exception $e) {
        sendError('Failed to fetch user courses: ' . $e->getMessage(), 500);
    }
}

function assignCourseToUser($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->user_id) || !isset($data->course_id)) {
        sendError('user_id and course_id required', 400);
    }
    
    try {
        // Check if already assigned
        $query = "SELECT id FROM sprakapp_user_course_access WHERE user_id = :user_id AND course_id = :course_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $data->user_id);
        $stmt->bindParam(':course_id', $data->course_id);
        $stmt->execute();
        
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Convert ISO datetime to DATE format (YYYY-MM-DD)
        $start_date = null;
        if (isset($data->start_date) && $data->start_date) {
            $start_date = date('Y-m-d', strtotime($data->start_date));
        }
        
        $end_date = null;
        if (isset($data->end_date) && $data->end_date) {
            $end_date = date('Y-m-d', strtotime($data->end_date));
        }
        
        $chapter_limit = $data->chapter_limit ?? null;
        
        if ($exists) {
            // Update existing assignment
            $query = "UPDATE sprakapp_user_course_access 
                      SET start_date = :start_date, end_date = :end_date, chapter_limit = :chapter_limit
                      WHERE user_id = :user_id AND course_id = :course_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $data->user_id);
            $stmt->bindParam(':course_id', $data->course_id);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->bindParam(':chapter_limit', $chapter_limit, PDO::PARAM_INT);
            $stmt->execute();
            
            sendSuccess(['message' => 'Course assignment updated'], 200);
        } else {
            // Insert new assignment
            $query = "INSERT INTO sprakapp_user_course_access (user_id, course_id, start_date, end_date, chapter_limit) 
                      VALUES (:user_id, :course_id, :start_date, :end_date, :chapter_limit)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $data->user_id);
            $stmt->bindParam(':course_id', $data->course_id);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->bindParam(':chapter_limit', $chapter_limit, PDO::PARAM_INT);
            $stmt->execute();
            
            sendSuccess(['message' => 'Course assigned to user'], 201);
        }
        
    } catch (Exception $e) {
        sendError('Failed to assign course: ' . $e->getMessage(), 500);
    }
}

function revokeCourseFromUser($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->user_id) || !isset($data->course_id)) {
        sendError('user_id and course_id required', 400);
    }
    
    try {
        $query = "DELETE FROM sprakapp_user_course_access WHERE user_id = :user_id AND course_id = :course_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $data->user_id);
        $stmt->bindParam(':course_id', $data->course_id);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            sendError('Course assignment not found', 404);
        }
        
        sendSuccess(['message' => 'Course access revoked']);
        
    } catch (Exception $e) {
        sendError('Failed to revoke course: ' . $e->getMessage(), 500);
    }
}

function updateUserCourseDates($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    // Support both userCourseId (new) and user_id + course_id (legacy)
    if (isset($data->userCourseId)) {
        // New approach: use the user_course_access id directly
        try {
            $fields = [];
            $params = [':id' => $data->userCourseId];
            
            if (isset($data->start_date)) {
                $fields[] = "start_date = :start_date";
                // Convert ISO datetime to DATE format
                $params[':start_date'] = $data->start_date ? date('Y-m-d', strtotime($data->start_date)) : null;
            }
            if (isset($data->end_date)) {
                $fields[] = "end_date = :end_date";
                // Convert ISO datetime to DATE format
                $params[':end_date'] = $data->end_date ? date('Y-m-d', strtotime($data->end_date)) : null;
            }
            if (isset($data->chapter_limit)) {
                $fields[] = "chapter_limit = :chapter_limit";
                $params[':chapter_limit'] = $data->chapter_limit;
            }
            
            if (empty($fields)) {
                sendError('No dates to update', 400);
            }
            
            $query = "UPDATE sprakapp_user_course_access SET " . implode(', ', $fields) . " 
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                sendError('Course assignment not found', 404);
            }
            
            sendSuccess(['message' => 'Course dates updated']);
            
        } catch (Exception $e) {
            sendError('Failed to update course dates: ' . $e->getMessage(), 500);
        }
    } elseif (isset($data->user_id) && isset($data->course_id)) {
        // Legacy approach for backwards compatibility
        try {
            $fields = [];
            $params = [':user_id' => $data->user_id, ':course_id' => $data->course_id];
            
            if (isset($data->start_date)) {
                $fields[] = "start_date = :start_date";
                $params[':start_date'] = $data->start_date;
            }
            if (isset($data->end_date)) {
                $fields[] = "end_date = :end_date";
                $params[':end_date'] = $data->end_date;
            }
            if (isset($data->chapter_limit)) {
                $fields[] = "chapter_limit = :chapter_limit";
                $params[':chapter_limit'] = $data->chapter_limit;
            }
            
            if (empty($fields)) {
                sendError('No dates to update', 400);
            }
            
            $query = "UPDATE sprakapp_user_course_access SET " . implode(', ', $fields) . " 
                      WHERE user_id = :user_id AND course_id = :course_id";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                sendError('Course assignment not found', 404);
            }
            
            sendSuccess(['message' => 'Course dates updated']);
            
        } catch (Exception $e) {
            sendError('Failed to update course dates: ' . $e->getMessage(), 500);
        }
    } else {
        sendError('userCourseId or (user_id and course_id) required', 400);
    }
}
