<?php
/**
 * Email Verification Endpoint
 * 
 * Verifies user email addresses using token sent via email.
 */

require_once __DIR__ . '/../config/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = $_GET['token'] ?? null;

if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'Verification token required']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Find user with this token
    $query = "SELECT id, email, email_verified, verification_token_expires, referred_by 
              FROM sprakapp_users 
              WHERE verification_token = :token";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired verification token']);
        exit;
    }
    
    // Check if already verified
    if ($user['email_verified']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Email already verified',
            'email' => $user['email']
        ]);
        exit;
    }
    
    // Check if token has expired
    if ($user['verification_token_expires'] && strtotime($user['verification_token_expires']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Verification token has expired. Please request a new verification email.']);
        exit;
    }
    
    $db->beginTransaction();
    
    // Mark email as verified and clear token
    $query = "UPDATE sprakapp_users 
              SET email_verified = TRUE, 
                  verification_token = NULL, 
                  verification_token_expires = NULL 
              WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $user['id']);
    $stmt->execute();
    
    // If user was referred, now process the referral rewards
    if ($user['referred_by']) {
        try {
            // Log email_verified event
            $stmt = $db->prepare('
                INSERT INTO sprakapp_referral_events (referrer_user_id, invited_user_id, event_type) 
                VALUES (:referrer_id, :user_id, "email_verified")
            ');
            $stmt->bindParam(':referrer_id', $user['referred_by']);
            $stmt->bindParam(':user_id', $user['id']);
            $stmt->execute();
            
            // Process rewards (this will be picked up by the rewards processing script)
            error_log('[Email Verification] Email verified event logged for referred user ' . $user['id']);
            
            // Check referral config to award credits
            $stmt = $db->prepare('SELECT referrer_credits, is_active FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1');
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config && $config['is_active'] && $config['referrer_credits'] > 0) {
                // Award credits to referrer
                $stmt = $db->prepare('
                    INSERT INTO sprakapp_referral_credits (user_id, credits, reason) 
                    VALUES (:user_id, :credits, :reason)
                    ON DUPLICATE KEY UPDATE credits = credits + :credits
                ');
                $stmt->bindParam(':user_id', $user['referred_by']);
                $stmt->bindParam(':credits', $config['referrer_credits']);
                $reason = 'Referral bonus for verified user ' . $user['email'];
                $stmt->bindParam(':reason', $reason);
                $stmt->execute();
                
                error_log('[Email Verification] Awarded ' . $config['referrer_credits'] . ' credits to referrer ' . $user['referred_by']);
            }
        } catch (Exception $e) {
            error_log('[Email Verification] Failed to process referral rewards: ' . $e->getMessage());
            // Don't fail verification if referral processing fails
        }
    }
    
    $db->commit();
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Email successfully verified! You can now log in.',
        'email' => $user['email']
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log('[Email Verification] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Verification failed. Please try again.']);
}
