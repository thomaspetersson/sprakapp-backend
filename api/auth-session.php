<?php
// Session-based authentication (no JWT required)
session_start();

require_once __DIR__ . '/../config/config.php';

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
    case 'DELETE':
        logout();
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        break;
}

function register($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || !isset($data->password)) {
        sendError('Email and password required', 400);
    }

    try {
        // Check if user exists
        $query = "SELECT id FROM sprakapp_users WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            sendError('User already exists', 409);
        }

        // Create user
        $userId = bin2hex(random_bytes(16));
        $passwordHash = password_hash($data->password, PASSWORD_DEFAULT);
        
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
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['email'] = $data->email;
        $_SESSION['role'] = 'user';
        
        sendSuccess([
            'user' => [
                'id' => $userId,
                'email' => $data->email,
                'role' => 'user'
            ]
        ], 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        sendError('Registration failed: ' . $e->getMessage(), 500);
    }
}

function login($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || !isset($data->password)) {
        sendError('Email and password required', 400);
    }

    try {
        $query = "SELECT u.id, u.email, u.password_hash, p.role, p.first_name, p.last_name, p.avatar_url
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            sendError('Invalid credentials', 401);
        }
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($data->password, $user['password_hash'])) {
            sendError('Invalid credentials', 401);
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'] ?? 'user';
        
        sendSuccess([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'user',
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'avatar_url' => $user['avatar_url']
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Login failed: ' . $e->getMessage(), 500);
    }
}

function getCurrentUser($db) {
    if (!isset($_SESSION['user_id'])) {
        sendError('Not authenticated', 401);
    }
    
    try {
        $query = "SELECT u.id, u.email, p.first_name, p.last_name, p.avatar_url, p.role 
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_SESSION['user_id']);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            sendError('User not found', 404);
        }
        
        sendSuccess($user);
        
    } catch (Exception $e) {
        sendError('Failed to fetch user: ' . $e->getMessage(), 500);
    }
}

function logout() {
    session_destroy();
    sendSuccess(['message' => 'Logged out successfully']);
}
