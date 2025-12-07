<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'POST':
        if (isset($_GET['action']) && $_GET['action'] === 'register') {
            register($db);
        } else {
            login($db);
        }
        break;
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'me') {
            getCurrentUser($db);
        }
        break;
    case 'PUT':
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            logout($db);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function register($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || !isset($data->password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        return;
    }

    try {
        // Check if user exists
        $query = "SELECT id FROM sprakapp_users WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'User already exists']);
            return;
        }

        // Create user
        $userId = bin2hex(random_bytes(16));
        $passwordHash = Auth::hashPassword($data->password);
        
        $db->beginTransaction();
        
        $query = "INSERT INTO sprakapp_users (id, email, password_hash) VALUES (:id, :email, :password_hash)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->bindParam(':email', $data->email);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->execute();
        
        // Create profile
        $query = "INSERT INTO sprakapp_profiles (id, role) VALUES (:id, 'user')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
        
        $db->commit();
        
        $token = Auth::generateToken($userId, $data->email, 'user');
        
        http_response_code(201);
        echo json_encode([
            'user' => [
                'id' => $userId,
                'email' => $data->email
            ],
            'token' => $token
        ]);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
    }
}

function login($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || !isset($data->password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        return;
    }

    try {
        $query = "SELECT u.id, u.email, u.password_hash, p.role 
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!Auth::verifyPassword($data->password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }
        
        $token = Auth::generateToken($user['id'], $user['email'], $user['role'] ?? 'user');
        
        http_response_code(200);
        echo json_encode([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'user'
            ],
            'token' => $token
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Login failed: ' . $e->getMessage()]);
    }
}

function getCurrentUser($db) {
    $decoded = Auth::verifyToken();
    
    try {
        $query = "SELECT u.id, u.email, p.first_name, p.last_name, p.avatar_url, p.role 
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $decoded->user_id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        http_response_code(200);
        echo json_encode(['user' => $user]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch user: ' . $e->getMessage()]);
    }
}

function logout($db) {
    $decoded = Auth::verifyToken();
    http_response_code(200);
    echo json_encode(['message' => 'Logged out successfully']);
}
