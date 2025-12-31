<?php
/**
 * Referral Reward Tiers API
 * Handles dynamic reward tier management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/session-auth.php';
require_once __DIR__ . '/../lib/reward-tiers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    switch ($action) {
        case 'list':
            // Get all reward tiers (admin only)
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
            
            $stmt = $db->query('
                SELECT * FROM sprakapp_referral_reward_tiers 
                ORDER BY required_invites ASC
            ');
            $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'tiers' => $tiers]);
            break;
            
        case 'active_tiers':
            // Get active reward tiers (public endpoint for displaying progress)
            $stmt = $db->query('
                SELECT id, required_invites, reward_type, reward_value, chapter_limit
                FROM sprakapp_referral_reward_tiers 
                WHERE is_active = TRUE
                ORDER BY required_invites ASC
            ');
            $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'tiers' => $tiers]);
            break;
            
        case 'create':
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
            
            error_log('🔍 CREATE received: ' . json_encode($data));
            
            $required_invites = (int)($data['required_invites'] ?? 0);
            $reward_type = $data['reward_type'] ?? 'free_days';
            $reward_value = (int)($data['reward_value'] ?? 0);
            // Handle JSON null correctly
            $chapter_limit = $data['chapter_limit'] === null ? null : (int)$data['chapter_limit'];
            $is_active = $data['is_active'] ?? true;
            $display_order = (int)($data['display_order'] ?? 0);
            
            error_log('🔍 CREATE chapter_limit: ' . json_encode($data['chapter_limit']) . ' → ' . ($chapter_limit === null ? 'NULL' : $chapter_limit));
            
            if ($required_invites <= 0 || $reward_value <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Required invites and reward value must be positive']);
                exit;
            }
            
            // Check if tier with same required_invites exists
            $stmt = $db->prepare('SELECT id FROM sprakapp_referral_reward_tiers WHERE required_invites = ?');
            $stmt->execute([$required_invites]);
            if ($stmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Tier with this required invites already exists']);
                exit;
            }
            
            $stmt = $db->prepare('
                INSERT INTO sprakapp_referral_reward_tiers 
                (required_invites, reward_type, reward_value, chapter_limit, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            
            $params = [
                $required_invites,
                $reward_type, 
                $reward_value,
                $chapter_limit,
                $is_active ? 1 : 0,
                $display_order
            ];
            
            error_log('🔍 CREATE SQL params: ' . json_encode($params));
            $result = $stmt->execute($params);
            
            if ($result) {
                $tier_id = $db->lastInsertId();
                
                // Verify what was actually saved
                $checkStmt = $db->prepare('SELECT * FROM sprakapp_referral_reward_tiers WHERE id = ?');
                $checkStmt->execute([$tier_id]);
                $savedTier = $checkStmt->fetch(PDO::FETCH_ASSOC);
                error_log('🔍 SAVED TIER: ' . json_encode($savedTier));
                
                echo json_encode(['success' => true, 'tier' => $savedTier]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create tier']);
            }
            break;
            
        case 'update':
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
            
            $tier_id = (int)($_GET['id'] ?? 0);
            if ($tier_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Valid tier ID required']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $updates = [];
            $params = [];
            
            if (isset($data['required_invites'])) {
                $required_invites = (int)$data['required_invites'];
                if ($required_invites <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Required invites must be positive']);
                    exit;
                }
                
                // Check if another tier uses this required_invites
                $stmt = $db->prepare('SELECT id FROM sprakapp_referral_reward_tiers WHERE required_invites = ? AND id != ?');
                $stmt->execute([$required_invites, $tier_id]);
                if ($stmt->rowCount() > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Another tier already uses this required invites value']);
                    exit;
                }
                
                $updates[] = 'required_invites = ?';
                $params[] = $required_invites;
            }
            
            if (isset($data['reward_type'])) {
                $updates[] = 'reward_type = ?';
                $params[] = $data['reward_type'];
            }
            
            if (isset($data['reward_value'])) {
                $reward_value = (int)$data['reward_value'];
                if ($reward_value <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Reward value must be positive']);
                    exit;
                }
                $updates[] = 'reward_value = ?';
                $params[] = $reward_value;
            }
            
            // Use array_key_exists instead of isset because isset returns false when value is null
            if (array_key_exists('chapter_limit', $data)) {
                // Handle JSON null correctly
                $chapter_limit_value = $data['chapter_limit'] === null ? null : (int)$data['chapter_limit'];
                error_log('🔍 UPDATE chapter_limit: ' . json_encode($data['chapter_limit']) . ' → ' . ($chapter_limit_value === null ? 'NULL' : $chapter_limit_value));
                $updates[] = 'chapter_limit = ?';
                $params[] = $chapter_limit_value;
            }
            
            if (isset($data['is_active'])) {
                $updates[] = 'is_active = ?';
                $params[] = $data['is_active'] ? 1 : 0;
            }
            
            if (isset($data['display_order'])) {
                $updates[] = 'display_order = ?';
                $params[] = (int)$data['display_order'];
            }
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                exit;
            }
            
            $updates[] = 'updated_at = CURRENT_TIMESTAMP';
            $params[] = $tier_id;
            
            $sql = 'UPDATE sprakapp_referral_reward_tiers SET ' . implode(', ', $updates) . ' WHERE id = ?';
            
            error_log('🔍 UPDATE SQL: ' . $sql);
            error_log('🔍 UPDATE params: ' . json_encode($params));
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            // Get updated tier to see what was actually saved
            $stmt = $db->prepare('SELECT * FROM sprakapp_referral_reward_tiers WHERE id = ?');
            $stmt->execute([$tier_id]);
            $tier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log('🔍 UPDATE result: ' . json_encode($tier));
            
            if (!$tier) {
                http_response_code(404);
                echo json_encode(['error' => 'Tier not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'tier' => $tier]);
            break;
            
        case 'delete':
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
            
            $tier_id = (int)($_GET['id'] ?? 0);
            if ($tier_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Valid tier ID required']);
                exit;
            }
            
            // Check if tier is referenced in existing rewards
            $stmt = $db->prepare('SELECT COUNT(*) FROM sprakapp_referral_rewards WHERE tier_id = ?');
            $stmt->execute([$tier_id]);
            $rewardCount = $stmt->fetchColumn();
            
            if ($rewardCount > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete tier that has been used for rewards']);
                exit;
            }
            
            $stmt = $db->prepare('DELETE FROM sprakapp_referral_reward_tiers WHERE id = ?');
            $stmt->execute([$tier_id]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Tier not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Tier deleted successfully']);
            break;
            
        case 'available_rewards':
            // Get available rewards for current user (those they qualify for but haven't claimed)
            $user = SessionAuth::requireAuth();
            
            $stmt = $db->prepare('
                SELECT 
                    t.*,
                    CASE 
                        WHEN r.id IS NOT NULL THEN r.reward_status 
                        ELSE NULL 
                    END as claimed_status
                FROM sprakapp_referral_reward_tiers t
                LEFT JOIN sprakapp_referral_rewards r ON (r.tier_id = t.id AND r.user_id = ?)
                WHERE t.is_active = TRUE
                AND t.required_invites <= (
                    SELECT COUNT(*) FROM sprakapp_referral_events e 
                    WHERE e.referrer_user_id = ? AND e.event_type = "completed_onboarding"
                )
                ORDER BY t.required_invites ASC
            ');
            $stmt->execute([$user->user_id, $user->user_id]);
            $available_rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'rewards' => $available_rewards]);
            break;
            
        case 'create_reward':
            // Create reward for specific tier
            $user = SessionAuth::requireAuth();
            $data = json_decode(file_get_contents('php://input'), true);
            
            $tierId = $data['tier_id'] ?? null;
            if (!$tierId) {
                http_response_code(400);
                echo json_encode(['error' => 'tier_id required']);
                exit;
            }
            
            try {
                $reward = createRewardForTier($db, $user->user_id, $tierId);
                echo json_encode(['success' => true, 'reward' => $reward]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Database error in reward-tiers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error in reward-tiers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred: ' . $e->getMessage()]);
}
?>