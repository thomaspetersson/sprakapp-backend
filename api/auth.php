<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rate-limit.php';
require_once __DIR__ . '/../middleware/csrf-protection.php';

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
    case 'PUT':
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            logout($db);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function register($db) {
    // Rate limit registration attempts
    RateLimit::check(RateLimit::getIdentifier(), 'default');
    
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
            error_log('[Referral DEBUG] Validating referral code: ' . $referralCode);
            
            $stmt = $db->prepare('SELECT id FROM sprakapp_users WHERE referral_code = :code');
            $stmt->bindParam(':code', $referralCode);
            $stmt->execute();
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($referrer) {
                $referrerId = $referrer['id'];
                error_log('[Referral DEBUG] Found referrer ID: ' . $referrerId);
                
                // Get referral config for trial days
                $stmt = $db->prepare('SELECT invited_user_trial_days, is_active FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1');
                $stmt->execute();
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($config && $config['is_active']) {
                    $trialDays = (int)$config['invited_user_trial_days'];
                    error_log('[Referral DEBUG] Set trial days to invited_user_trial_days: ' . $trialDays);
                }
            } else {
                error_log('[Referral DEBUG] Referral code not found in database: ' . $referralCode);
            }
        } else {
            // Get default trial days from config for non-referred users
            error_log('[Referral DEBUG] No referral code provided in request');
            $stmt = $db->prepare('SELECT new_user_trial_days FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1');
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($config) {
                $trialDays = (int)$config['new_user_trial_days'];
                error_log('[Referral DEBUG] Set trial days to new_user_trial_days: ' . $trialDays);
            }
        }

        // Generate unique referral code for new user
        $newReferralCode = generateUserReferralCode($db);
        
        // Calculate trial expiration
        $trialExpiresAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
        
        error_log('[Referral DEBUG] Creating user with:');
        error_log('[Referral DEBUG]   - Email: ' . $data->email);
        error_log('[Referral DEBUG]   - Referral code: ' . $newReferralCode);
        error_log('[Referral DEBUG]   - Referred by: ' . ($referrerId ?? 'NULL'));
        error_log('[Referral DEBUG]   - Trial expires: ' . $trialExpiresAt);

        // Create user
        $userId = bin2hex(random_bytes(16));
        $passwordHash = Auth::hashPassword($data->password);
        
        $db->beginTransaction();
        
        $query = "INSERT INTO sprakapp_users (id, email, password_hash, referral_code, referred_by, trial_expires_at) 
                  VALUES (:id, :email, :password_hash, :referral_code, :referred_by, :trial_expires_at)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->bindParam(':email', $data->email);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->bindParam(':referral_code', $newReferralCode);
        $stmt->bindParam(':referred_by', $referrerId);
        $stmt->bindParam(':trial_expires_at', $trialExpiresAt);
        $stmt->execute();
        
        // Create profile
        $query = "INSERT INTO sprakapp_profiles (id, role) VALUES (:id, 'user')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
        
        // Log referral event if user was referred
        if ($referrerId) {
            // Prevent self-referral
            if ($referrerId !== $userId) {
                try {
                    $stmt = $db->prepare('
                        INSERT INTO sprakapp_referral_events (referrer_user_id, invited_user_id, event_type) 
                        VALUES (:referrer_id, :user_id, "signup")
                    ');
                    $stmt->bindParam(':referrer_id', $referrerId);
                    $stmt->bindParam(':user_id', $userId);
                    $stmt->execute();
                    error_log('[Referral] Signup event logged for user ' . $userId . ' referred by ' . $referrerId);
                } catch (Exception $e) {
                    error_log('[Referral] Failed to log signup event: ' . $e->getMessage());
                    // Don't fail registration if referral logging fails
                }
            }
        }
        
        $db->commit();
        
        $token = Auth::generateToken($userId, $data->email, 'user');
        
        sendSuccess([
            'user' => [
                'id' => $userId,
                'email' => $data->email,
                'role' => 'user',
                'referral_code' => $newReferralCode,
                'trial_expires_at' => $trialExpiresAt,
                'was_referred' => $referrerId !== null
            ],
            'token' => $token
        ], 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        sendError('Registration failed: ' . $e->getMessage(), 500);
    }
}

/**
 * Generate a unique 10-character referral code
 */
function generateUserReferralCode($db) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxAttempts = 10;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        $stmt = $db->prepare('SELECT id FROM sprakapp_users WHERE referral_code = ?');
        $stmt->execute([$code]);
        if ($stmt->rowCount() === 0) {
            return $code;
        }
    }
    
    // Fallback to UUID-based code
    return strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

function login($db) {
    // Rate limit login attempts
    RateLimit::check(RateLimit::getIdentifier(), 'login');
    
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || !isset($data->password)) {
        sendError('Email and password required', 400);
    }

    try {
        $query = "SELECT u.id, u.email, u.password_hash, p.role 
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
        
        if (!Auth::verifyPassword($data->password, $user['password_hash'])) {
            sendError('Invalid credentials', 401);
        }
        
        // Auto-complete onboarding on first login if not already done
        $stmt = $db->prepare('SELECT onboarding_completed, referred_by FROM sprakapp_users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userData && !$userData['onboarding_completed']) {
            // Mark as completed
            $stmt = $db->prepare('UPDATE sprakapp_users SET onboarding_completed = 1 WHERE id = ?');
            $stmt->execute([$user['id']]);
            
            // If referred, log completed_onboarding event
            if (!empty($userData['referred_by'])) {
                try {
                    $stmt = $db->prepare('
                        INSERT IGNORE INTO sprakapp_referral_events 
                        (referrer_user_id, invited_user_id, event_type) 
                        VALUES (?, ?, "completed_onboarding")
                    ');
                    $stmt->execute([$userData['referred_by'], $user['id']]);
                } catch (Exception $e) {
                    // Don't fail login if referral processing fails
                    error_log('Referral onboarding error: ' . $e->getMessage());
                }
            }
        }
        
        $token = Auth::generateToken($user['id'], $user['email'], $user['role'] ?? 'user');
        
        sendSuccess([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'user'
            ],
            'token' => $token
        ]);
        
    } catch (Exception $e) {
        sendError('Login failed: ' . $e->getMessage(), 500);
    }
}

function getCurrentUser($db) {
    $decoded = Auth::verifyToken();
    
    try {
        $query = "SELECT u.id, u.email, p.first_name, p.last_name, p.avatar_url, p.role 
                  FROM sprakapp_users u 
                  LEFT JOIN sprakapp_profiles p ON u.id = p.id 
                  WHERE u.id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $decoded->user_id);
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

function logout($db) {
    $decoded = Auth::verifyToken();
    sendSuccess(['message' => 'Logged out successfully']);
}
