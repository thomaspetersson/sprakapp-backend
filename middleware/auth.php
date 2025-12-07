<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    
    public static function verifyToken() {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['error' => 'No authorization header']);
            exit();
        }

        $authHeader = $headers['Authorization'];
        $arr = explode(" ", $authHeader);
        
        if (count($arr) != 2 || $arr[0] != 'Bearer') {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid authorization format']);
            exit();
        }

        $jwt = $arr[1];

        try {
            $decoded = JWT::decode($jwt, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
            return $decoded;
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token: ' . $e->getMessage()]);
            exit();
        }
    }

    public static function generateToken($userId, $email, $role = 'user') {
        $issuedAt = time();
        $expirationTime = $issuedAt + JWT_EXPIRATION;
        
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'user_id' => $userId,
            'email' => $email,
            'role' => $role
        ];

        return JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}
