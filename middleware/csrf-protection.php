<?php

class CSRFProtection {
    private static $tokenName = 'csrf_token';
    private static $tokenLength = 32;
    
    /**
     * Generate a new CSRF token and store it in session
     */
    public static function generateToken() {
        if (!isset($_SESSION[self::$tokenName])) {
            $_SESSION[self::$tokenName] = bin2hex(random_bytes(self::$tokenLength));
        }
        return $_SESSION[self::$tokenName];
    }
    
    /**
     * Get the current CSRF token
     */
    public static function getToken() {
        return $_SESSION[self::$tokenName] ?? null;
    }
    
    /**
     * Validate CSRF token from request
     */
    public static function validate() {
        $sessionToken = self::getToken();
        
        if (!$sessionToken) {
            return false;
        }
        
        // Check token in headers first
        $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        // If not in headers, check POST data
        if (!$requestToken) {
            $requestToken = $_POST['csrf_token'] ?? null;
        }
        
        // If still not found, check JSON body
        if (!$requestToken) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            $requestToken = $data['csrf_token'] ?? null;
        }
        
        return hash_equals($sessionToken, $requestToken ?? '');
    }
    
    /**
     * Validate CSRF token and send error if invalid
     */
    public static function validateOrDie() {
        if (!self::validate()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid or missing CSRF token']);
            exit;
        }
    }
    
    /**
     * Check if request method requires CSRF protection
     */
    public static function requiresValidation($method = null) {
        $method = $method ?? $_SERVER['REQUEST_METHOD'];
        return in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH']);
    }
}
