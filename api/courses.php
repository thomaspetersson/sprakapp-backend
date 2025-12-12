<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/session-auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getCourse($db, $_GET['id']);
        } elseif (isset($_GET['action']) && $_GET['action'] === 'admin') {
            // Admin endpoint - get ALL courses
            $user = SessionAuth::requireAdmin();
            getAdminCourses($db);
        } elseif (isset($_GET['action']) && $_GET['action'] === 'user-courses') {
            // User endpoint - get assigned courses for current user
            $user = SessionAuth::requireAuth();
            getUserAssignedCourses($db, $user);
        } else {
            getCourses($db);
        }
        break;
    case 'POST':
        // Check if this is an access check request
        if (isset($_GET['action']) && $_GET['action'] === 'access') {
            $user = SessionAuth::requireAuth();
            checkCourseAccess($db, $user);
        } else {
            // Regular course creation - requires admin
            $user = SessionAuth::requireAdmin();
            createCourse($db, $user);
        }
        break;
    case 'PUT':
        $user = SessionAuth::requireAdmin();
        updateCourse($db, $_GET['id'], $user);
        break;
    case 'DELETE':
        $user = SessionAuth::requireAdmin();
        deleteCourse($db, $_GET['id'], $user);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getCourses($db) {
    try {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM sprakapp_chapters WHERE course_id = c.id) as chapter_count
                  FROM sprakapp_courses c 
                  WHERE c.is_published = 1 
                  ORDER BY c.order_index ASC, c.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($courses);
        
    } catch (Exception $e) {
        sendError('Failed to fetch courses: ' . $e->getMessage(), 500);
    }
}

function getAdminCourses($db) {
    try {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM sprakapp_chapters WHERE course_id = c.id) as chapter_count
                  FROM sprakapp_courses c 
                  ORDER BY c.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($courses);
        
    } catch (Exception $e) {
        sendError('Failed to fetch admin courses: ' . $e->getMessage(), 500);
    }
}

function getUserAssignedCourses($db, $user) {
    try {
        $userId = $user->user_id;
        
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

function getCourse($db, $id) {
    try {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM sprakapp_chapters WHERE course_id = c.id) as chapter_count
                  FROM sprakapp_courses c 
                  WHERE c.id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            sendError('Course not found', 404);
        }
        
        // Get chapters
        $query = "SELECT * FROM sprakapp_chapters WHERE course_id = :course_id ORDER BY order_number ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $id);
        $stmt->execute();
        $course['chapters'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess($course);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch course: ' . $e->getMessage()]);
    }
}

function createCourse($db, $user) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->title)) {
        sendError('Title is required', 400);
    }

    try {
        $courseId = bin2hex(random_bytes(16));
        
        $query = "INSERT INTO sprakapp_courses (id, title, description, level, language, cover_image, is_published, order_index, created_by, speech_voice_name, audio_file_url, price_monthly, currency) 
                  VALUES (:id, :title, :description, :level, :language, :cover_image, :is_published, :order_index, :created_by, :speech_voice_name, :audio_file_url, :price_monthly, :currency)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $courseId);
        $stmt->bindParam(':title', $data->title);
        $description = $data->description ?? null;
        $stmt->bindParam(':description', $description);
        $level = $data->level ?? null;
        $stmt->bindParam(':level', $level);
        $stmt->bindParam(':language', $data->language);
        $cover_image = $data->cover_image ?? null;
        $stmt->bindParam(':cover_image', $cover_image);
        $is_published = isset($data->is_published) ? (int)$data->is_published : 0;
        $stmt->bindParam(':is_published', $is_published);
        $order_index = $data->order_index ?? 0;
        $stmt->bindParam(':order_index', $order_index);
        $stmt->bindParam(':created_by', $user->id);
        $speech_voice_name = $data->speech_voice_name ?? null;
        $stmt->bindParam(':speech_voice_name', $speech_voice_name);
        $audio_file_url = $data->audio_file_url ?? null;
        $stmt->bindParam(':audio_file_url', $audio_file_url);
        $price_monthly = $data->price_monthly ?? 99.00;
        $stmt->bindParam(':price_monthly', $price_monthly);
        $currency = $data->currency ?? 'SEK';
        $stmt->bindParam(':currency', $currency);
        $stmt->execute();
        
        sendSuccess(['id' => $courseId, 'message' => 'Course created'], 201);
        
    } catch (Exception $e) {
        sendError('Failed to create course: ' . $e->getMessage(), 500);
    }
}

function updateCourse($db, $id, $user) {
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
        if (isset($data->level)) {
            $fields[] = "level = :level";
            $params[':level'] = $data->level;
        }
        if (isset($data->language)) {
            $fields[] = "language = :language";
            $params[':language'] = $data->language;
        }
        if (isset($data->cover_image)) {
            $fields[] = "cover_image = :cover_image";
            $params[':cover_image'] = $data->cover_image;
        }
        if (isset($data->is_published)) {
            $fields[] = "is_published = :is_published";
            $params[':is_published'] = (int)$data->is_published;
        }
        if (isset($data->order_index)) {
            $fields[] = "order_index = :order_index";
            $params[':order_index'] = $data->order_index;
        }
        if (isset($data->price_monthly)) {
            $fields[] = "price_monthly = :price_monthly";
            $params[':price_monthly'] = $data->price_monthly;
        }
        if (isset($data->currency)) {
            $fields[] = "currency = :currency";
            $params[':currency'] = $data->currency;
        }
        
        if (empty($fields)) {
            sendError('No fields to update', 400);
        }
        
        $query = "UPDATE sprakapp_courses SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        sendSuccess(['message' => 'Course updated']);
        
    } catch (Exception $e) {
        sendError('Failed to update course: ' . $e->getMessage(), 500);
    }
}

function deleteCourse($db, $id, $user) {
    try {
        $query = "DELETE FROM sprakapp_courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        sendSuccess(['message' => 'Course deleted']);
        
    } catch (Exception $e) {
        sendError('Failed to delete course: ' . $e->getMessage(), 500);
    }
}

function checkCourseAccess($db, $decoded) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['userId'] ?? null;
        $courseId = $input['courseId'] ?? null;
        $isAdmin = $input['isAdmin'] ?? false;
        $chapterId = $input['chapterId'] ?? null;

        // If user is admin, always grant access
        if ($isAdmin || $decoded->role === 'admin') {
            sendSuccess(true);
            return;
        }

        // Check if user has an assignment for this course
        $query = "SELECT id, start_date, end_date, chapter_limit 
                  FROM sprakapp_user_course_access 
                  WHERE user_id = :user_id AND course_id = :course_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':course_id', $courseId);
        $stmt->execute();
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no assignment exists, grant access by default
        if (!$assignment) {
            sendSuccess(true);
            return;
        }

        // Check date restrictions
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        if ($assignment['start_date']) {
            $startDate = new DateTime($assignment['start_date']);
            $startDate->setTime(0, 0, 0);
            if ($today < $startDate) {
                sendSuccess(false);
                return;
            }
        }

        if ($assignment['end_date']) {
            $endDate = new DateTime($assignment['end_date']);
            $endDate->setTime(23, 59, 59);
            if ($today > $endDate) {
                sendSuccess(false);
                return;
            }
        }

        // Check chapter limit if a specific chapter is requested
        if ($chapterId && $assignment['chapter_limit'] !== null) {
            $chapterLimit = (int)$assignment['chapter_limit'];
            
            // If limit is 0, deny access to all chapters
            if ($chapterLimit === 0) {
                sendSuccess(false);
                return;
            }

            // Fetch the order number of the requested chapter
            $query = "SELECT order_number FROM sprakapp_chapters WHERE id = :chapter_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':chapter_id', $chapterId);
            $stmt->execute();
            $chapter = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$chapter) {
                // Chapter doesn't exist, deny access for safety
                sendSuccess(false);
                return;
            }

            // If chapter order number is greater than the limit, deny access
            if ($chapter['order_number'] > $chapterLimit) {
                sendSuccess(false);
                return;
            }
        }

        // All checks passed
        sendSuccess(true);
        
    } catch (Exception $e) {
        sendError('Failed to check course access: ' . $e->getMessage(), 500);
    }
}
