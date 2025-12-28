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
        
        error_log('[Referral DEBUG] Creating user with trial_expires_at: ' . $trialExpiresAt);
        error_log('[Referral DEBUG] referred_by: ' . ($referrerId ?? 'NULL'));
        
        $db->beginTransaction();
        
        // Insert user with referral fields
        $query = "INSERT INTO sprakapp_users (id, email, password_hash, referral_code, referred_by, trial_expires_at, onboarding_completed) 
                  VALUES (:id, :email, :password_hash, :referral_code, :referred_by, :trial_expires_at, 0)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->bindParam(':email', $data->email);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->bindParam(':referral_code', $userReferralCode);
        $stmt->bindParam(':referred_by', $referrerId);
        $stmt->bindParam(':trial_expires_at', $trialExpiresAt);
        $stmt->execute();
        
        // Create profile
        $query = "INSERT INTO sprakapp_profiles (id, role) VALUES (:id, 'user')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
        
        // Log referral event if referred
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
        error_log('[Referral DEBUG] Registration failed with exception: ' . $e->getMessage());
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
