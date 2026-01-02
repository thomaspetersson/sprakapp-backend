<?php

/**
 * Login Protection Middleware
 * 
 * Provides 5-layer protection against brute force attacks:
 * 1. Rate limiting (IP + Session)
 * 2. Account lockout after repeated failures
 * 3. Progressive delays
 * 4. CAPTCHA trigger after failures
 * 5. Logging and monitoring
 */

require_once __DIR__ . '/../config/database.php';

class LoginProtection {
    private $db;
    private $config;
    
    public function __construct($db) {
        $this->db = $db;
        
        // Configuration
        $this->config = [
            // Rate limiting
            'max_attempts_per_ip' => 100,     // Max attempts per IP (generous for shared IPs)
            'ip_time_window' => 900,          // 15 minutes in seconds
            'max_attempts_per_email' => 5,    // Max attempts per email address
            'email_time_window' => 300,       // 5 minutes in seconds
            'max_attempts_per_session' => 5,  // Max attempts per session
            'session_time_window' => 300,     // 5 minutes in seconds
            
            // Account lockout
            'lockout_threshold' => 10,        // Failed attempts before lockout
            'lockout_duration' => 3600,       // 1 hour lockout
            
            // Progressive delays
            'delay_after_attempts' => 3,      // Start delays after N attempts
            'min_delay' => 2,                 // Minimum delay in seconds
            'max_delay' => 5,                 // Maximum delay in seconds
            
            // CAPTCHA
            'captcha_after_attempts' => 5,    // Require CAPTCHA after N attempts
        ];
    }
    
    /**
     * Check if login attempt is allowed
     * Returns: ['allowed' => bool, 'reason' => string, 'requireCaptcha' => bool, 'delay' => int]
     */
    public function checkLoginAllowed($email, $ipAddress, $sessionId) {
        // Layer 1a: Check email-specific rate limit (most important)
        if (!empty($email)) {
            $emailAttempts = $this->getEmailAttempts($email, $this->config['email_time_window']);
            if ($emailAttempts >= $this->config['max_attempts_per_email']) {
                return [
                    'allowed' => false,
                    'reason' => 'Too many failed attempts for this account. Please try again in a few minutes.',
                    'requireCaptcha' => true,
                    'delay' => 0
                ];
            }
        }
        
        // Layer 1b: Check IP rate limit (generous for shared IPs)
        $ipAttempts = $this->getIPAttempts($ipAddress);
        if ($ipAttempts >= $this->config['max_attempts_per_ip']) {
            return [
                'allowed' => false,
                'reason' => 'Too many attempts from your IP address. Please try again later.',
                'requireCaptcha' => true,
                'delay' => 0
            ];
        }
        
        // Layer 1c: Check session rate limit
        $sessionAttempts = $this->getSessionAttempts($sessionId);
        if ($sessionAttempts >= $this->config['max_attempts_per_session']) {
            return [
                'allowed' => false,
                'reason' => 'Too many attempts. Please try again later.',
                'requireCaptcha' => true,
                'delay' => 0
            ];
        }
        
        // Layer 2: Check account lockout
        if (!empty($email)) {
            $lockout = $this->getAccountLockout($email);
            if ($lockout) {
                $minutesLeft = ceil((strtotime($lockout['locked_until']) - time()) / 60);
                return [
                    'allowed' => false,
                    'reason' => "Account temporarily locked. Please try again in $minutesLeft minutes.",
                    'requireCaptcha' => false,
                    'delay' => 0
                ];
            }
        }
        
        // Layer 3: Calculate progressive delay based on email attempts
        $emailAttempts = !empty($email) ? $this->getEmailAttempts($email, $this->config['email_time_window']) : 0;
        $delay = 0;
        if ($emailAttempts >= $this->config['delay_after_attempts']) {
            $delay = min(
                $this->config['max_delay'],
                $this->config['min_delay'] + ($emailAttempts - $this->config['delay_after_attempts'])
            );
        }
        
        // Layer 4: Check if CAPTCHA required
        $requireCaptcha = $emailAttempts >= $this->config['captcha_after_attempts'];
        
        return [
            'allowed' => true,
            'reason' => '',
            'requireCaptcha' => $requireCaptcha,
            'delay' => $delay
        ];
    }
    
    /**
     * Record a failed login attempt
     */
    public function recordFailedAttempt($email, $ipAddress, $userAgent = '') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sprakapp_failed_logins (email, ip_address, user_agent, attempted_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$email, $ipAddress, $userAgent]);
            
            // Check if we need to lock the account
            if (!empty($email)) {
                $recentFailures = $this->getEmailAttempts($email, 3600); // Last hour
                if ($recentFailures >= $this->config['lockout_threshold']) {
                    $this->lockAccount($email);
                }
            }
            
        } catch (PDOException $e) {
            error_log("Failed to record login attempt: " . $e->getMessage());
        }
    }
    
    /**
     * Clear failed attempts after successful login
     */
    public function clearFailedAttempts($email) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM sprakapp_failed_logins
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            
            // Also remove any active lockouts
            $stmt = $this->db->prepare("
                DELETE FROM sprakapp_account_lockouts
                WHERE email = ? AND locked_until > NOW()
            ");
            $stmt->execute([$email]);
            
        } catch (PDOException $e) {
            error_log("Failed to clear login attempts: " . $e->getMessage());
        }
    }
    
    /**
     * Get number of attempts from IP in time window
     */
    private function getIPAttempts($ipAddress) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM sprakapp_failed_logins
                WHERE ip_address = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$ipAddress, $this->config['ip_time_window']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'];
        } catch (PDOException $e) {
            error_log("Failed to get IP attempts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get number of attempts from session in time window
     */
    private function getSessionAttempts($sessionId) {
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        
        // Clean old attempts
        $cutoff = time() - $this->config['session_time_window'];
        $_SESSION['login_attempts'] = array_filter(
            $_SESSION['login_attempts'],
            function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            }
        );
        
        return count($_SESSION['login_attempts']);
    }
    
    /**
     * Get number of attempts for email in time window
     */
    private function getEmailAttempts($email, $timeWindow = 3600) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM sprakapp_failed_logins
                WHERE email = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$email, $timeWindow]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'];
        } catch (PDOException $e) {
            error_log("Failed to get email attempts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if account is locked
     */
    private function getAccountLockout($email) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM sprakapp_account_lockouts
                WHERE email = ?
                AND locked_until > NOW()
                ORDER BY locked_at DESC
                LIMIT 1
            ");
            $stmt->execute([$email]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to check lockout: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Lock an account after too many failures
     */
    private function lockAccount($email) {
        try {
            // Get user ID if exists
            $stmt = $this->db->prepare("SELECT id FROM sprakapp_users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $userId = $user ? $user['id'] : '';
            
            // Create lockout
            $stmt = $this->db->prepare("
                INSERT INTO sprakapp_account_lockouts 
                (user_id, email, locked_at, locked_until, reason)
                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), ?)
            ");
            $stmt->execute([
                $userId,
                $email,
                $this->config['lockout_duration'],
                'Too many failed login attempts'
            ]);
            
            error_log("Account locked: $email (too many failed attempts)");
            
        } catch (PDOException $e) {
            error_log("Failed to lock account: " . $e->getMessage());
        }
    }
    
    /**
     * Record session attempt timestamp
     */
    public function recordSessionAttempt() {
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        $_SESSION['login_attempts'][] = time();
    }
    
    /**
     * Apply progressive delay if needed
     */
    public function applyDelay($seconds) {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
    
    /**
     * Verify reCAPTCHA v3 token
     */
    public function verifyCaptcha($token, $secretKey) {
        if (empty($token)) {
            return false;
        }
        
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $token
        ];
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if ($result === false) {
            error_log("Failed to verify reCAPTCHA");
            return false;
        }
        
        $response = json_decode($result, true);
        
        // Check score (0.0-1.0, higher is more likely human)
        if ($response['success'] && isset($response['score'])) {
            return $response['score'] >= 0.5; // Configurable threshold
        }
        
        return false;
    }
    
    /**
     * Clean up old records (call periodically)
     */
    public function cleanup($daysToKeep = 7) {
        try {
            // Clean old failed attempts
            $stmt = $this->db->prepare("
                DELETE FROM sprakapp_failed_logins
                WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            
            // Clean expired lockouts
            $stmt = $this->db->prepare("
                DELETE FROM sprakapp_account_lockouts
                WHERE locked_until < NOW() AND is_permanent = FALSE
            ");
            $stmt->execute();
            
        } catch (PDOException $e) {
            error_log("Failed to cleanup login protection data: " . $e->getMessage());
        }
    }
}
