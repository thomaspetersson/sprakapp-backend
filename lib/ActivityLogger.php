<?php
/**
 * Activity Logger
 * 
 * Logs important business events to a separate activity log file.
 * Events include: registrations, purchases, subscriptions, course selections, etc.
 */

class ActivityLogger {
    private $logFile;
    private $enabled;
    
    public function __construct() {
        // Log file path - ensure directory exists
        $logDir = __DIR__ . '/../logs';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->logFile = $logDir . '/activity.log';
        $this->enabled = true; // Set to false to disable logging
    }
    
    /**
     * Log an activity event
     * 
     * @param string $eventType Event type (e.g., 'user_registered', 'subscription_created')
     * @param string $userId User ID involved in the event
     * @param array $data Additional data about the event
     * @param string $email Optional email for better readability
     */
    public function log($eventType, $userId, $data = [], $email = null) {
        if (!$this->enabled) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $userIdentifier = $email ? "$email ($userId)" : $userId;
        
        $logEntry = [
            'timestamp' => $timestamp,
            'event' => $eventType,
            'user_id' => $userId,
            'email' => $email,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $logLine = sprintf(
            "[%s] %s | User: %s | IP: %s | Data: %s\n",
            $timestamp,
            strtoupper($eventType),
            $userIdentifier,
            $logEntry['ip'],
            json_encode($data)
        );
        
        // Write to file
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // Also write to PHP error log for backup
        error_log("[ACTIVITY] $logLine");
    }
    
    // Convenience methods for common events
    
    public function userRegistered($userId, $email, $referredBy = null, $trialDays = null) {
        $this->log('user_registered', $userId, [
            'referred_by' => $referredBy,
            'trial_days' => $trialDays
        ], $email);
    }
    
    public function emailVerified($userId, $email) {
        $this->log('email_verified', $userId, [], $email);
    }
    
    public function userLoggedIn($userId, $email) {
        $this->log('user_logged_in', $userId, [], $email);
    }
    
    public function adminImpersonated($adminId, $adminEmail, $targetUserId, $targetEmail) {
        $this->log('admin_impersonated', $adminId, [
            'target_user_id' => $targetUserId,
            'target_email' => $targetEmail
        ], $adminEmail);
    }
    
    public function subscriptionCreated($userId, $email, $subscriptionId, $planId, $amount) {
        $this->log('subscription_created', $userId, [
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
            'amount' => $amount
        ], $email);
    }
    
    public function subscriptionRenewed($userId, $email, $subscriptionId, $amount) {
        $this->log('subscription_renewed', $userId, [
            'subscription_id' => $subscriptionId,
            'amount' => $amount
        ], $email);
    }
    
    public function subscriptionCancelled($userId, $email, $subscriptionId) {
        $this->log('subscription_cancelled', $userId, [
            'subscription_id' => $subscriptionId
        ], $email);
    }
    
    public function subscriptionExpired($userId, $email, $subscriptionId) {
        $this->log('subscription_expired', $userId, [
            'subscription_id' => $subscriptionId
        ], $email);
    }
    
    public function courseSelected($userId, $email, $courseId, $courseTitle) {
        $this->log('course_selected', $userId, [
            'course_id' => $courseId,
            'course_title' => $courseTitle
        ], $email);
    }
    
    public function courseAccessGranted($userId, $email, $courseId, $grantedBy) {
        $this->log('course_access_granted', $userId, [
            'course_id' => $courseId,
            'granted_by' => $grantedBy
        ], $email);
    }
    
    public function courseAccessRevoked($userId, $email, $courseId, $revokedBy) {
        $this->log('course_access_revoked', $userId, [
            'course_id' => $courseId,
            'revoked_by' => $revokedBy
        ], $email);
    }
    
    public function paymentSuccess($userId, $email, $amount, $currency, $paymentIntentId) {
        $this->log('payment_success', $userId, [
            'amount' => $amount,
            'currency' => $currency,
            'payment_intent_id' => $paymentIntentId
        ], $email);
    }
    
    public function paymentFailed($userId, $email, $amount, $reason) {
        $this->log('payment_failed', $userId, [
            'amount' => $amount,
            'reason' => $reason
        ], $email);
    }
    
    public function roleChanged($userId, $email, $oldRole, $newRole, $changedBy) {
        $this->log('role_changed', $userId, [
            'old_role' => $oldRole,
            'new_role' => $newRole,
            'changed_by' => $changedBy
        ], $email);
    }
    
    public function referralUsed($referrerId, $referrerEmail, $newUserId, $newUserEmail) {
        $this->log('referral_used', $referrerId, [
            'new_user_id' => $newUserId,
            'new_user_email' => $newUserEmail
        ], $referrerEmail);
    }
    
    public function trialStarted($userId, $email, $expiresAt) {
        $this->log('trial_started', $userId, [
            'expires_at' => $expiresAt
        ], $email);
    }
    
    public function trialExpired($userId, $email) {
        $this->log('trial_expired', $userId, [], $email);
    }
}
