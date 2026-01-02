<?php
// Session-based authentication (no JWT required)
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/EmailHelper.php';
require_once __DIR__ . '/../middleware/login-protection.php';
require_once __DIR__ . '/../lib/ActivityLogger.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();
$activityLogger = new ActivityLogger();

switch ($method) {
    case 'POST':
        if (isset($_GET['action']) && $_GET['action'] === 'register') {
            register($db);
        } elseif (isset($_GET['action']) && $_GET['action'] === 'impersonate') {
            impersonateUser($db);
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
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput);
    
    // DEBUG LOG
    error_log('[Referral DEBUG] Raw input: ' . $rawInput);
    error_log('[Referral DEBUG] Parsed email: ' . ($data->email ?? 'NULL'));
    error_log('[Referral DEBUG] Has referral_code property: ' . (isset($data->referral_code) ? 'YES' : 'NO'));
    error_log('[Referral DEBUG] Referral code value: ' . ($data->referral_code ?? 'NULL'));
    
    if (!isset($data->email) || !isset($data->password)) {
        sendError('Email and password required', 400);
    }

    // Get referral code from request
    $referralCode = isset($data->referral_code) ? trim($data->referral_code) : null;
    $referrerId = null;
    $trialDays = 7; // Default trial days
    
    error_log('[Referral DEBUG] Trimmed referral code: ' . ($referralCode ?? 'NULL'));

    try {
        // Check if user exists
        $query = "SELECT id FROM sprakapp_users WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            sendError('User already exists', 409);
        }

        // Validate referral code if provided
        if ($referralCode) {
            $query = "SELECT id FROM sprakapp_users WHERE referral_code = :referral_code";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':referral_code', $referralCode);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
                $referrerId = $referrer['id'];
                error_log('[Referral DEBUG] Found referrer with ID: ' . $referrerId);
                
                // Get bonus trial days from config
                $query = "SELECT invited_user_trial_days FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->execute();
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $trialDays = (int)$row['invited_user_trial_days'];
                    error_log('[Referral DEBUG] Using invited_user_trial_days from config: ' . $trialDays);
                }
            } else {
                error_log('[Referral DEBUG] Referral code not found in database');
            }
        } else {
            // Get default trial days for non-referred users
            error_log('[Referral DEBUG] No referral code provided - using new_user_trial_days');
            $query = "SELECT new_user_trial_days FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute();
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $trialDays = (int)$row['new_user_trial_days'];
                error_log('[Referral DEBUG] Using new_user_trial_days from config: ' . $trialDays);
            }
        }

        // Create user
        $userId = bin2hex(random_bytes(16));
        $passwordHash = password_hash($data->password, PASSWORD_DEFAULT);
        $userReferralCode = strtoupper(substr(md5($userId . time()), 0, 10));
        $trialExpiresAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
        
        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        $verificationExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        error_log('[Referral DEBUG] Creating user with trial_expires_at: ' . $trialExpiresAt);
        error_log('[Referral DEBUG] referred_by: ' . ($referrerId ?? 'NULL'));
        error_log('[Registration] Generated verification token for: ' . $data->email);
        
        $db->beginTransaction();
        
        // Insert user with referral fields and email verification
        $query = "INSERT INTO sprakapp_users (id, email, password_hash, referral_code, referred_by, trial_expires_at, onboarding_completed, email_verified, verification_token, verification_token_expires) 
                  VALUES (:id, :email, :password_hash, :referral_code, :referred_by, :trial_expires_at, 0, FALSE, :verification_token, :verification_token_expires)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->bindParam(':email', $data->email);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->bindParam(':referral_code', $userReferralCode);
        $stmt->bindParam(':referred_by', $referrerId);
        $stmt->bindParam(':trial_expires_at', $trialExpiresAt);
        $stmt->bindParam(':verification_token', $verificationToken);
        $stmt->bindParam(':verification_token_expires', $verificationExpires);
        $stmt->execute();
        
        // Create profile
        $query = "INSERT INTO sprakapp_profiles (id, role) VALUES (:id, 'user')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
        
        // Log referral event if referred (but don't award rewards yet - wait for email verification)
        if ($referrerId) {
            $query = "INSERT INTO sprakapp_referral_events (referrer_user_id, invited_user_id, event_type) 
                      VALUES (:referrer_user_id, :invited_user_id, 'signup')";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':referrer_user_id', $referrerId);
            $stmt->bindParam(':invited_user_id', $userId);
            
            if ($stmt->execute()) {
                error_log('[Referral DEBUG] Successfully logged signup event');
            } else {
                error_log('[Referral DEBUG] Failed to log signup event');
            }
        }
        
        $db->commit();
        error_log('[Referral DEBUG] Transaction committed successfully');
        
        // Log activity
        global $activityLogger;
        $activityLogger->userRegistered($userId, $data->email, $referrerId, $trialDays);
        if ($referrerId) {
            // Get referrer email
            $stmt = $db->prepare("SELECT email FROM sprakapp_users WHERE id = ?");
            $stmt->execute([$referrerId]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($referrer) {
                $activityLogger->referralUsed($referrerId, $referrer['email'], $userId, $data->email);
            }
        }
        
        // Send verification email
        try {
            $emailHelper = new EmailHelper();
            $emailSent = $emailHelper->sendVerificationEmail($data->email, $verificationToken);
            
            if ($emailSent) {
                error_log('[Registration] Verification email sent successfully to ' . $data->email);
            } else {
                error_log('[Registration] Failed to send verification email to ' . $data->email);
            }
        } catch (Exception $e) {
            error_log('[Registration] Email error: ' . $e->getMessage());
            // Don't fail registration if email fails
        }
        
        // Don't set session - user must verify email first
        sendSuccess([
            'message' => 'Registration successful! Please check your email to verify your account.',
            'email' => $data->email,
            'email_verification_required' => true
        ], 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[Referral DEBUG] Registration failed with exception: ' . $e->getMessage());
        sendError('Registration failed: ' . $e->getMessage(), 500);
    }
}

function login($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || !isset($data->password)) {
        sendError('Email and password required', 400);
    }

    // Initialize login protection
    $loginProtection = new LoginProtection($db);
    
    // Get client information
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $sessionId = session_id();
    
    // Check if login attempt is allowed (Layer 1 & 2)
    $check = $loginProtection->checkLoginAllowed($data->email, $ipAddress, $sessionId);
    
    if (!$check['allowed']) {
        sendError($check['reason'], 429); // 429 Too Many Requests
    }
    
    // Apply progressive delay (Layer 3)
    $loginProtection->applyDelay($check['delay']);
    
    // Record this login attempt in session
    $loginProtection->recordSessionAttempt();

    try {
        $query = "SELECT u.id, u.email, u.password_hash, u.email_verified, p.role, p.first_name, p.last_name, p.avatar_url
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            // Record failed attempt (Layer 5)
            $loginProtection->recordFailedAttempt($data->email, $ipAddress, $userAgent);
            sendError('Invalid credentials', 401);
        }
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($data->password, $user['password_hash'])) {
            // Record failed attempt (Layer 5)
            $loginProtection->recordFailedAttempt($data->email, $ipAddress, $userAgent);
            sendError('Invalid credentials', 401);
        }
        
        // Clear failed attempts on successful login
        $loginProtection->clearFailedAttempts($data->email);
        
        // Check if email is verified
        if (!$user['email_verified']) {
            error_log('[Login] Email not verified for user: ' . $user['email']);
            sendError('Please verify your email address before logging in. Check your inbox for the verification link.', 403);
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'] ?? 'user';
        
        // Log activity
        global $activityLogger;
        $activityLogger->userLoggedIn($user['id'], $user['email']);
        
        // Auto-complete onboarding on first login if user was referred
        $query = "SELECT referred_by, onboarding_completed FROM sprakapp_users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $user['id']);
        $stmt->execute();
        $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userInfo && $userInfo['referred_by'] && !$userInfo['onboarding_completed']) {
            error_log('[Referral DEBUG] Auto-completing onboarding for user: ' . $user['id']);
            
            // Mark onboarding as completed
            $query = "UPDATE sprakapp_users SET onboarding_completed = 1 WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $user['id']);
            $stmt->execute();
            
            // Log completed_onboarding event
            $query = "INSERT INTO sprakapp_referral_events (referrer_user_id, invited_user_id, event_type) 
                      VALUES (:referrer_user_id, :invited_user_id, 'completed_onboarding')";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':referrer_user_id', $userInfo['referred_by']);
            $stmt->bindParam(':invited_user_id', $user['id']);
            
            if ($stmt->execute()) {
                error_log('[Referral DEBUG] Successfully logged completed_onboarding event');
            } else {
                error_log('[Referral DEBUG] Failed to log completed_onboarding event');
            }
        }
        
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
        error_log('[Login] Exception: ' . $e->getMessage());
        sendError('Login failed: ' . $e->getMessage(), 500);
    }
}

function getCurrentUser($db) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }

    try {
        $query = "SELECT u.id, u.email, p.role, p.first_name, p.last_name, p.avatar_url
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_SESSION['user_id']);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            session_destroy();
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
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
        sendError('Failed to get user: ' . $e->getMessage(), 500);
    }
}

function logout() {
    session_destroy();
    sendSuccess(['message' => 'Logged out successfully']);
}

function impersonateUser($db) {
    // Verify admin is logged in
    if (!isset($_SESSION['user_id'])) {
        sendError('Not authenticated', 401);
    }
    
    try {
        // Check if current user is admin
        $query = "SELECT p.role FROM sprakapp_profiles p WHERE p.id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_SESSION['user_id']);
        $stmt->execute();
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentUser || $currentUser['role'] !== 'admin') {
            error_log('[Impersonate] Non-admin user attempted impersonation: ' . $_SESSION['user_id']);
            sendError('Only admins can impersonate users', 403);
        }
        
        $data = json_decode(file_get_contents("php://input"));
        
        if (!isset($data->user_id)) {
            sendError('User ID required', 400);
        }
        
        // Get target user
        $query = "SELECT u.id, u.email, u.email_verified, p.role, p.first_name, p.last_name, p.avatar_url
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.id = :target_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':target_id', $data->user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            sendError('Target user not found', 404);
        }
        
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Security: Don't allow impersonating another admin (optional - you can remove this check)
        if ($targetUser['role'] === 'admin' && $targetUser['id'] !== $_SESSION['user_id']) {
            error_log('[Impersonate] Admin attempted to impersonate another admin: ' . $_SESSION['user_id'] . ' -> ' . $targetUser['id']);
            sendError('Cannot impersonate another admin', 403);
        }
        
        // Log the impersonation for audit trail
        error_log('[Impersonate] Admin ' . $_SESSION['user_id'] . ' (' . $_SESSION['email'] . ') impersonating user ' . $targetUser['id'] . ' (' . $targetUser['email'] . ')');
        
        // Log activity
        global $activityLogger;
        $activityLogger->adminImpersonated($_SESSION['user_id'], $_SESSION['email'], $targetUser['id'], $targetUser['email']);
        
        // Store original admin info before switching
        $_SESSION['impersonating'] = true;
        $_SESSION['original_admin_id'] = $_SESSION['user_id'];
        $_SESSION['original_admin_email'] = $_SESSION['email'];
        
        // Switch session to target user
        $_SESSION['user_id'] = $targetUser['id'];
        $_SESSION['email'] = $targetUser['email'];
        $_SESSION['role'] = $targetUser['role'] ?? 'user';
        
        sendSuccess([
            'user' => [
                'id' => $targetUser['id'],
                'email' => $targetUser['email'],
                'role' => $targetUser['role'] ?? 'user',
                'first_name' => $targetUser['first_name'],
                'last_name' => $targetUser['last_name'],
                'avatar_url' => $targetUser['avatar_url']
            ],
            'impersonating' => true,
            'original_admin' => $_SESSION['original_admin_email']
        ]);
        
    } catch (Exception $e) {
        error_log('[Impersonate] Exception: ' . $e->getMessage());
        sendError('Impersonation failed: ' . $e->getMessage(), 500);
    }
}
