<?php
/**
 * Referral System API
 * 
 * Endpoints:
 * - GET /api/referral.php?action=config - Get referral config
 * - POST /api/referral.php?action=config - Update referral config (admin only)
 * - GET /api/referral.php?action=stats - Get user's referral stats
 * - GET /api/referral.php?action=validate&code=XXX - Validate a referral code
 * - POST /api/referral.php?action=complete_onboarding - Mark onboarding as completed
 * - POST /api/referral.php?action=claim_reward - Claim a pending reward
 * - GET /api/referral.php?action=pending_rewards - Get pending rewards
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/session-auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =====================================================
// Utility Functions
// =====================================================

/**
 * Generate a unique 10-character referral code
 */
function generateReferralCode($db) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Excludes confusing chars like 0/O, 1/I/L
    $maxAttempts = 10;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Check if code already exists
        $stmt = $db->prepare('SELECT id FROM sprakapp_users WHERE referral_code = ?');
        $stmt->execute([$code]);
        if ($stmt->rowCount() === 0) {
            return $code;
        }
    }
    
    throw new Exception('Failed to generate unique referral code');
}

/**
 * Get current referral configuration
 */
function getReferralConfig($db) {
    $stmt = $db->prepare('SELECT * FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1');
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Return defaults if no config exists
        return [
            'new_user_trial_days' => 7,
            'invited_user_trial_days' => 14,
            'trial_chapter_limit' => 3,
            'required_invites_for_reward' => 3,
            'reward_type' => 'free_month',
            'reward_value' => 30,
            'reward_chapter_limit' => 3,
            'is_active' => true
        ];
    }
    
    return $config;
}

/**
 * Count successful invites for a user
 */
function getSuccessfulInvitesCount($db, $userId) {
    $stmt = $db->prepare('
        SELECT COUNT(*) as count 
        FROM sprakapp_referral_events 
        WHERE referrer_user_id = ? AND event_type = "completed_onboarding"
    ');
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)$result['count'];
}

/**
 * Check if user is eligible for reward and grant it
 */
function checkAndGrantReward($db, $referrerId) {
    $config = getReferralConfig($db);
    $successfulInvites = getSuccessfulInvitesCount($db, $referrerId);
    $requiredInvites = (int)$config['required_invites_for_reward'];
    
    // Check if user already has a reward at this level
    $stmt = $db->prepare('
        SELECT id FROM sprakapp_referral_rewards 
        WHERE user_id = ? AND invites_at_grant >= ?
    ');
    $stmt->execute([$referrerId, $successfulInvites]);
    
    if ($stmt->rowCount() > 0) {
        // Already has reward at this level
        return null;
    }
    
    // Check if eligible for new reward
    if ($successfulInvites >= $requiredInvites && $successfulInvites % $requiredInvites === 0) {
        // Grant new reward
        $stmt = $db->prepare('
            INSERT INTO sprakapp_referral_rewards 
            (user_id, reward_type, reward_value, invites_at_grant, reward_status) 
            VALUES (?, ?, ?, ?, "pending")
        ');
        $stmt->execute([
            $referrerId,
            $config['reward_type'],
            $config['reward_value'],
            $successfulInvites
        ]);
        
        // If reward type is credits, add them immediately
        if ($config['reward_type'] === 'credits') {
            addCredits($db, $referrerId, (int)$config['reward_value'], 'Referral reward', $db->lastInsertId());
        }
        
        return [
            'reward_id' => $db->lastInsertId(),
            'reward_type' => $config['reward_type'],
            'reward_value' => $config['reward_value']
        ];
    }
    
    return null;
}

/**
 * Add credits to user's balance
 */
function addCredits($db, $userId, $amount, $description, $referenceId = null) {
    // Ensure user has a credits record
    $stmt = $db->prepare('
        INSERT INTO sprakapp_referral_credits (user_id, credits_balance, credits_earned_total) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            credits_balance = credits_balance + VALUES(credits_balance),
            credits_earned_total = credits_earned_total + VALUES(credits_earned_total)
    ');
    $stmt->execute([$userId, $amount, $amount]);
    
    // Get new balance
    $stmt = $db->prepare('SELECT credits_balance FROM sprakapp_referral_credits WHERE user_id = ?');
    $stmt->execute([$userId]);
    $newBalance = (int)$stmt->fetchColumn();
    
    // Log transaction
    $stmt = $db->prepare('
        INSERT INTO sprakapp_credit_transactions 
        (user_id, transaction_type, amount, balance_after, description, reference_id) 
        VALUES (?, "earned", ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $amount, $newBalance, $description, $referenceId]);
    
    return $newBalance;
}

/**
 * Spend credits from user's balance
 */
function spendCredits($db, $userId, $amount, $description, $referenceId = null) {
    // Check balance
    $stmt = $db->prepare('SELECT credits_balance FROM sprakapp_referral_credits WHERE user_id = ?');
    $stmt->execute([$userId]);
    $currentBalance = (int)$stmt->fetchColumn();
    
    if ($currentBalance < $amount) {
        return false;
    }
    
    // Deduct credits
    $stmt = $db->prepare('
        UPDATE sprakapp_referral_credits 
        SET credits_balance = credits_balance - ?, credits_spent_total = credits_spent_total + ?
        WHERE user_id = ?
    ');
    $stmt->execute([$amount, $amount, $userId]);
    
    $newBalance = $currentBalance - $amount;
    
    // Log transaction
    $stmt = $db->prepare('
        INSERT INTO sprakapp_credit_transactions 
        (user_id, transaction_type, amount, balance_after, description, reference_id) 
        VALUES (?, "spent", ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, -$amount, $newBalance, $description, $referenceId]);
    
    return $newBalance;
}

// =====================================================
// API Handlers
// =====================================================

try {
    switch ($action) {
        case 'config':
            if ($method === 'GET') {
                // Anyone can read config
                $config = getReferralConfig($db);
                // Remove sensitive fields for non-admins
                unset($config['id']);
                echo json_encode(['success' => true, 'config' => $config]);
            } elseif ($method === 'POST') {
                // Only admin can update config
                $user = SessionAuth::requireAuth();
                
                // Check if user is admin
                $stmt = $db->prepare('SELECT role FROM sprakapp_profiles WHERE id = ?');
                $stmt->execute([$user->user_id]);
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$profile || $profile['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Admin access required']);
                    exit;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $db->prepare('
                    UPDATE sprakapp_referral_config SET
                        new_user_trial_days = COALESCE(?, new_user_trial_days),
                        invited_user_trial_days = COALESCE(?, invited_user_trial_days),
                        trial_chapter_limit = COALESCE(?, trial_chapter_limit),
                        required_invites_for_reward = COALESCE(?, required_invites_for_reward),
                        reward_type = COALESCE(?, reward_type),
                        reward_value = COALESCE(?, reward_value),
                        is_active = COALESCE(?, is_active),
                        updated_at = NOW()
                    WHERE id = (SELECT id FROM (SELECT MAX(id) as id FROM sprakapp_referral_config) as t)
                ');
                $stmt->execute([
                    $data['new_user_trial_days'] ?? null,
                    $data['invited_user_trial_days'] ?? null,
                    $data['trial_chapter_limit'] ?? null,
                    $data['required_invites_for_reward'] ?? null,
                    $data['reward_type'] ?? null,
                    $data['reward_value'] ?? null,
                    isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : null
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Config updated']);
            }
            break;
            
        case 'stats':
            $user = SessionAuth::requireAuth();
            $config = getReferralConfig($db);
            
            // Get user's referral code
            $stmt = $db->prepare('SELECT referral_code, referred_by, trial_expires_at FROM sprakapp_users WHERE id = ?');
            $stmt->execute([$user->user_id]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Generate referral code if not exists
            if (empty($userData['referral_code'])) {
                $newCode = generateReferralCode($db);
                $stmt = $db->prepare('UPDATE sprakapp_users SET referral_code = ? WHERE id = ?');
                $stmt->execute([$newCode, $user->user_id]);
                $userData['referral_code'] = $newCode;
            }
            
            // Get invite counts
            $stmt = $db->prepare('
                SELECT 
                    COUNT(CASE WHEN event_type = "signup" THEN 1 END) as total_invites,
                    COUNT(CASE WHEN event_type = "completed_onboarding" THEN 1 END) as successful_invites
                FROM sprakapp_referral_events 
                WHERE referrer_user_id = ?
            ');
            $stmt->execute([$user->user_id]);
            $inviteCounts = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get rewards
            $stmt = $db->prepare('
                SELECT id, reward_type, reward_value, reward_status, course_id, created_at 
                FROM sprakapp_referral_rewards 
                WHERE user_id = ? 
                ORDER BY created_at DESC
            ');
            $stmt->execute([$user->user_id]);
            $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get credits balance
            $stmt = $db->prepare('SELECT credits_balance FROM sprakapp_referral_credits WHERE user_id = ?');
            $stmt->execute([$user->user_id]);
            $creditsBalance = (int)$stmt->fetchColumn();
            
            // Check if user has active trial course access (to hide trial selection button)
            $stmt = $db->prepare('
                SELECT course_id FROM sprakapp_user_course_access 
                WHERE user_id = ? AND end_date > NOW()
            ');
            $stmt->execute([$user->user_id]);
            $activeTrialCourse = $stmt->fetchColumn();
            
            // Check if user has trial access (trial_expires_at is not null and not expired)
            $hasTrialAccess = !empty($userData['trial_expires_at']) && strtotime($userData['trial_expires_at']) > time();
            $hasSelectedTrialCourse = !!$activeTrialCourse;

            // Calculate progress to next reward
            $successfulInvites = (int)$inviteCounts['successful_invites'];
            $requiredForNext = (int)$config['required_invites_for_reward'];
            
            // Calculate invites needed (simple subtraction, not modulo)
            $invitesUntilReward = max(0, $requiredForNext - $successfulInvites);
            
            echo json_encode([
                'success' => true,
                'referral_code' => $userData['referral_code'],
                'referral_link' => 'https://polyverbo.com/ref/' . $userData['referral_code'],
                'referred_by' => $userData['referred_by'],
                'trial_expires_at' => $userData['trial_expires_at'],
                'has_trial_access' => $hasTrialAccess,
                'has_selected_trial_course' => $hasSelectedTrialCourse,
                'total_invites' => (int)$inviteCounts['total_invites'],
                'successful_invites' => $successfulInvites,
                'invites_until_reward' => $invitesUntilReward,
                'required_invites_for_reward' => $requiredForNext,
                'rewards' => $rewards,
                'credits_balance' => $creditsBalance,
                'hasActiveTrialCourse' => !!$activeTrialCourse,
                'reward_type' => $config['reward_type'],
                'reward_value' => $config['reward_value'],
                'invited_user_trial_days' => (int)$config['invited_user_trial_days'],
                'new_user_trial_days' => (int)$config['new_user_trial_days']
            ]);
            break;
            
        case 'validate':
            $code = $_GET['code'] ?? '';
            
            if (empty($code)) {
                http_response_code(400);
                echo json_encode(['error' => 'Referral code required']);
                exit;
            }
            
            $stmt = $db->prepare('SELECT id, email FROM sprakapp_users WHERE referral_code = ?');
            $stmt->execute([$code]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$referrer) {
                http_response_code(404);
                echo json_encode(['valid' => false, 'error' => 'Invalid referral code']);
                exit;
            }
            
            $config = getReferralConfig($db);
            
            echo json_encode([
                'valid' => true,
                'referrer_id' => $referrer['id'],
                'bonus_trial_days' => (int)$config['invited_user_trial_days']
            ]);
            break;
            
        case 'complete_onboarding':
            $user = SessionAuth::requireAuth();
            
            // Mark user's onboarding as completed
            $stmt = $db->prepare('UPDATE sprakapp_users SET onboarding_completed = 1 WHERE id = ?');
            $stmt->execute([$user->user_id]);
            
            // Check if user was referred
            $stmt = $db->prepare('SELECT referred_by FROM sprakapp_users WHERE id = ?');
            $stmt->execute([$user->user_id]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($userData['referred_by'])) {
                // Log completed_onboarding event
                $stmt = $db->prepare('
                    INSERT IGNORE INTO sprakapp_referral_events 
                    (referrer_user_id, invited_user_id, event_type) 
                    VALUES (?, ?, "completed_onboarding")
                ');
                $stmt->execute([$userData['referred_by'], $user->user_id]);
                
                // Check if referrer should get a reward
                $reward = checkAndGrantReward($db, $userData['referred_by']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Onboarding completed',
                    'referrer_rewarded' => $reward !== null
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Onboarding completed'
                ]);
            }
            break;
            
        case 'pending_rewards':
            $user = SessionAuth::requireAuth();
            
            $stmt = $db->prepare('
                SELECT r.*, c.title as course_title 
                FROM sprakapp_referral_rewards r
                LEFT JOIN sprakapp_courses c ON r.course_id = c.id
                WHERE r.user_id = ? AND r.reward_status = "pending"
                ORDER BY r.created_at ASC
            ');
            $stmt->execute([$user->user_id]);
            $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'rewards' => $rewards]);
            break;
            
        case 'create_reward':
            $user = SessionAuth::requireAuth();
            
            $config = getReferralConfig($db);
            $successfulInvites = getSuccessfulInvitesCount($db, $user->user_id);
            $requiredInvites = (int)$config['required_invites_for_reward'];
            
            // Check if eligible for reward
            if ($successfulInvites < $requiredInvites) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Not enough successful invites',
                    'current' => $successfulInvites,
                    'required' => $requiredInvites
                ]);
                exit;
            }
            
            // Check if reward already exists at this level
            $stmt = $db->prepare('
                SELECT COUNT(*) as reward_count FROM sprakapp_referral_rewards 
                WHERE user_id = ?
            ');
            $stmt->execute([$user->user_id]);
            $rewardCount = (int)$stmt->fetchColumn();
            
            // Max 10 rewards per user to prevent abuse
            if ($rewardCount >= 10) {
                http_response_code(400);
                echo json_encode(['error' => 'Maximum rewards limit reached (10 rewards per user)']);
                exit;
            }
            
            $stmt = $db->prepare('
                SELECT id FROM sprakapp_referral_rewards 
                WHERE user_id = ? AND invites_at_grant >= ?
            ');
            $stmt->execute([$user->user_id, $successfulInvites]);
            
            if ($stmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Reward already created for this level']);
                exit;
            }
            
            // Create the reward
            $stmt = $db->prepare('
                INSERT INTO sprakapp_referral_rewards 
                (user_id, reward_type, reward_value, reward_status, invites_at_grant) 
                VALUES (?, ?, ?, "pending", ?)
            ');
            $stmt->execute([
                $user->user_id,
                $config['reward_type'],
                $config['reward_value'],
                $successfulInvites
            ]);
            
            $rewardId = $db->lastInsertId();
            
            // Fetch the created reward
            $stmt = $db->prepare('SELECT * FROM sprakapp_referral_rewards WHERE id = ?');
            $stmt->execute([$rewardId]);
            $reward = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'reward' => $reward]);
            break;
            
        case 'grant_trial_access':
            $user = SessionAuth::requireAuth();
            $data = json_decode(file_get_contents('php://input'), true);
            
            $courseId = $data['course_id'] ?? null;
            
            if (!$courseId) {
                http_response_code(400);
                echo json_encode(['error' => 'course_id required']);
                exit;
            }
            
            // Check if user has an active trial period
            $stmt = $db->prepare('SELECT trial_expires_at FROM sprakapp_users WHERE id = ?');
            $stmt->execute([$user->user_id]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $trialExpiresAt = $userData['trial_expires_at'];
            if (!$trialExpiresAt || strtotime($trialExpiresAt) <= time()) {
                http_response_code(403);
                echo json_encode(['error' => 'Trial period expired or not active']);
                exit;
            }
            
            // Check if course exists
            $stmt = $db->prepare('SELECT id FROM sprakapp_courses WHERE id = ?');
            $stmt->execute([$courseId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Course not found']);
                exit;
            }
            
            // Check if user already has an active trial course access
            $stmt = $db->prepare('
                SELECT COUNT(*) as count FROM sprakapp_user_course_access 
                WHERE user_id = ? AND end_date > NOW()
            ');
            $stmt->execute([$user->user_id]);
            $existingAccess = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingAccess['count'] > 0) {
                http_response_code(403);
                echo json_encode(['error' => 'You have already selected a trial course']);
                exit;
            }
            
            // Grant course access for trial period with chapter limit
            $config = getReferralConfig($db);
            $trialChapterLimit = isset($config['trial_chapter_limit']) && $config['trial_chapter_limit'] !== '' 
                ? (int)$config['trial_chapter_limit'] 
                : null;
            
            $stmt = $db->prepare('
                INSERT INTO sprakapp_user_course_access 
                (user_id, course_id, start_date, end_date, chapter_limit, granted_at) 
                VALUES (?, ?, NOW(), ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                end_date = ?,
                chapter_limit = ?,
                granted_at = NOW()
            ');
            $stmt->execute([$user->user_id, $courseId, $trialExpiresAt, $trialChapterLimit, $trialExpiresAt, $trialChapterLimit]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Trial course access granted',
                'expires_at' => $trialExpiresAt
            ]);
            break;
            
        case 'claim_reward':
            $user = SessionAuth::requireAuth();
            $data = json_decode(file_get_contents('php://input'), true);
            
            $rewardId = $data['reward_id'] ?? null;
            $courseId = $data['course_id'] ?? null;
            
            if (!$rewardId) {
                http_response_code(400);
                echo json_encode(['error' => 'reward_id required']);
                exit;
            }
            
            // Get the reward
            $stmt = $db->prepare('
                SELECT * FROM sprakapp_referral_rewards 
                WHERE id = ? AND user_id = ? AND reward_status = "pending"
            ');
            $stmt->execute([$rewardId, $user->user_id]);
            $reward = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reward) {
                http_response_code(404);
                echo json_encode(['error' => 'Reward not found or already claimed']);
                exit;
            }
            
            $db->beginTransaction();
            
            try {
                if ($reward['reward_type'] === 'free_month') {
                    // Require course selection for free_month too
                    if (!$courseId) {
                        $db->rollBack();
                        http_response_code(400);
                        echo json_encode(['error' => 'course_id required for free_month reward']);
                        exit;
                    }
                    
                    // Verify course exists
                    $stmt = $db->prepare('SELECT id FROM sprakapp_courses WHERE id = ?');
                    $stmt->execute([$courseId]);
                    if ($stmt->rowCount() === 0) {
                        $db->rollBack();
                        http_response_code(404);
                        echo json_encode(['error' => 'Course not found']);
                        exit;
                    }
                    
                    // Get chapter_limit from reward or config
                    $config = getReferralConfig($db);
                    $chapterLimit = $config['reward_chapter_limit'] ?? null;
                    
                    // Grant time-limited access to selected course with chapter limit
                    $days = (int)$reward['reward_value'];
                    $stmt = $db->prepare('
                        INSERT INTO sprakapp_user_course_access 
                        (user_id, course_id, start_date, end_date, granted_at, subscription_status) 
                        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), NOW(), "active")
                        ON DUPLICATE KEY UPDATE 
                            end_date = DATE_ADD(COALESCE(end_date, CURDATE()), INTERVAL ? DAY),
                            subscription_status = "active"
                    ');
                    $stmt->execute([$user->user_id, $courseId, $days, $days]);
                    
                    // Update reward with course selection and chapter limit
                    $stmt = $db->prepare('
                        UPDATE sprakapp_referral_rewards 
                        SET reward_status = "granted", 
                            course_id = ?, 
                            chapter_limit = ?,
                            claimed_at = NOW() 
                        WHERE id = ?
                    ');
                    $stmt->execute([$courseId, $chapterLimit, $rewardId]);
                    
                } elseif ($reward['reward_type'] === 'credits') {
                    // Credits are already added when reward is created
                    $stmt = $db->prepare('
                        UPDATE sprakapp_referral_rewards 
                        SET reward_status = "granted", claimed_at = NOW() 
                        WHERE id = ?
                    ');
                    $stmt->execute([$rewardId]);
                }
                
                $db->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Reward claimed successfully',
                    'reward_type' => $reward['reward_type'],
                    'course_id' => $courseId
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'invited_users':
            // Get list of users this person has invited
            $user = SessionAuth::requireAuth();
            
            $stmt = $db->prepare('
                SELECT 
                    u.id,
                    u.email,
                    u.created_at as signup_date,
                    u.onboarding_completed,
                    e.event_type,
                    e.created_at as event_date
                FROM sprakapp_users u
                INNER JOIN sprakapp_referral_events e ON u.id = e.invited_user_id
                WHERE e.referrer_user_id = ?
                ORDER BY u.created_at DESC
            ');
            $stmt->execute([$user->user_id]);
            $invitedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'invited_users' => $invitedUsers]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log('Referral API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
