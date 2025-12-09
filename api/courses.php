<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getCourse($db, $_GET['id']);
        } else {
            getCourses($db);
        }
        break;
    case 'POST':
        // Check if this is an access check request
        if (isset($_GET['action']) && $_GET['action'] === 'access') {
            $decoded = Auth::verifyToken();
            checkCourseAccess($db, $decoded);
        } else {
            // Regular course creation - requires admin
            $decoded = Auth::verifyToken();
            if ($decoded->role !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Admin access required']);
                return;
            }
            createCourse($db);
        }
        break;
    case 'PUT':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        updateCourse($db, $_GET['id']);
        break;
    case 'DELETE':
        $decoded = Auth::verifyToken();
        if ($decoded->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        deleteCourse($db, $_GET['id']);
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
        $query = "SELECT * FROM sprakapp_chapters WHERE course_id = :course_id ORDER BY order_index ASC";
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

function createCourse($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->title)) {
        sendError('Title is required', 400);
    }

    try {
        $courseId = bin2hex(random_bytes(16));
        
        $query = "INSERT INTO sprakapp_courses (id, title, description, level, language, is_published, order_index) 
                  VALUES (:id, :title, :description, :level, :language, :is_published, :order_index)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $courseId);
        $stmt->bindParam(':title', $data->title);
        $stmt->bindParam(':description', $data->description);
        $stmt->bindParam(':level', $data->level);
        $stmt->bindParam(':language', $data->language);
        $is_published = isset($data->is_published) ? (int)$data->is_published : 0;
        $stmt->bindParam(':is_published', $is_published);
        $order_index = $data->order_index ?? 0;
        $stmt->bindParam(':order_index', $order_index);
        $stmt->execute();
        
        sendSuccess(['id' => $courseId, 'message' => 'Course created'], 201);
        
    } catch (Exception $e) {
        sendError('Failed to create course: ' . $e->getMessage(), 500);
    }
}

function updateCourse($db, $id) {
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
        if (isset($data->is_published)) {
            $fields[] = "is_published = :is_published";
            $params[':is_published'] = (int)$data->is_published;
        }
        if (isset($data->order_index)) {
            $fields[] = "order_index = :order_index";
            $params[':order_index'] = $data->order_index;
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

function deleteCourse($db, $id) {
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
                  FROM sprakapp_user_courses 
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
