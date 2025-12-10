<?php
// Session-based authentication middleware
// No JWT required - uses PHP sessions

session_start();

class SessionAuth {
    public static function requireAuth() {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        
        return (object)[
            'user_id' => $_SESSION['user_id'],
            'sub' => $_SESSION['user_id'], // For compatibility
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? 'user'
        ];
    }
    
    public static function requireAdmin() {
        $user = self::requireAuth();
        
        if ($user->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            exit;
        }
        
        return $user;
    }
    
    public static function getUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        return (object)[
            'user_id' => $_SESSION['user_id'],
            'sub' => $_SESSION['user_id'],
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? 'user'
        ];
    }
}
