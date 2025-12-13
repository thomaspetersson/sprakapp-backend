<?php

class RateLimit {
    private static $limits = [
        'default' => ['requests' => 100, 'window' => 60], // 100 requests per minute
        'login' => ['requests' => 5, 'window' => 300], // 5 login attempts per 5 minutes
        'password-reset' => ['requests' => 3, 'window' => 3600], // 3 password reset requests per hour
        'api' => ['requests' => 60, 'window' => 60] // 60 API requests per minute
    ];
    
    public static function check($identifier, $type = 'default') {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset(self::$limits[$type])) {
            $type = 'default';
        }
        
        $limit = self::$limits[$type]['requests'];
        $window = self::$limits[$type]['window'];
        
        // Initialize session storage for rate limiting if not exists
        if (!isset($_SESSION['rate_limit'])) {
            $_SESSION['rate_limit'] = [];
        }
        
        $key = $type . '_' . $identifier;
        $now = time();
        
        // Clean old entries
        if (isset($_SESSION['rate_limit'][$key])) {
            $_SESSION['rate_limit'][$key] = array_filter(
                $_SESSION['rate_limit'][$key],
                function($timestamp) use ($now, $window) {
                    return ($now - $timestamp) < $window;
                }
            );
        } else {
            $_SESSION['rate_limit'][$key] = [];
        }
        
        // Check if limit exceeded
        if (count($_SESSION['rate_limit'][$key]) >= $limit) {
            http_response_code(429);
            header('Content-Type: application/json');
            $retryAfter = $window - ($now - min($_SESSION['rate_limit'][$key]));
            header('Retry-After: ' . $retryAfter);
            echo json_encode([
                'error' => 'Rate limit exceeded',
                'retry_after' => $retryAfter
            ]);
            exit;
        }
        
        // Add current request
        $_SESSION['rate_limit'][$key][] = $now;
    }
    
    public static function getIdentifier() {
        // Use session ID if user is authenticated
        if (isset($_SESSION['user_id'])) {
            return 'user_' . $_SESSION['user_id'];
        }
        
        // Otherwise use IP address
        return self::getClientIP();
    }
    
    private static function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
}
