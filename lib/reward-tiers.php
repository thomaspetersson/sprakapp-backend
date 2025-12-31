<?php
/**
 * Reward Tiers Helper Functions
 * Support functions for dynamic multi-tier reward system
 */

/**
 * Get next available reward tier for user
 */
function getNextAvailableRewardTier($db, $userId) {
    $successfulInvites = getSuccessfulInvitesCount($db, $userId);
    
    $stmt = $db->prepare('
        SELECT t.*
        FROM sprakapp_referral_reward_tiers t
        WHERE t.is_active = TRUE
        AND t.required_invites <= ?
        AND NOT EXISTS (
            SELECT 1 FROM sprakapp_referral_rewards r 
            WHERE r.user_id = ? AND r.tier_id = t.id
        )
        ORDER BY t.required_invites ASC
        LIMIT 1
    ');
    $stmt->execute([$successfulInvites, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all reward tiers user qualifies for but hasn't claimed
 */
function getAvailableRewardTiers($db, $userId) {
    $successfulInvites = getSuccessfulInvitesCount($db, $userId);
    
    $stmt = $db->prepare('
        SELECT 
            t.*,
            CASE WHEN r.id IS NOT NULL THEN r.reward_status ELSE NULL END as claimed_status
        FROM sprakapp_referral_reward_tiers t
        LEFT JOIN sprakapp_referral_rewards r ON (r.tier_id = t.id AND r.user_id = ?)
        WHERE t.is_active = TRUE
        AND t.required_invites <= ?
        ORDER BY t.required_invites ASC
    ');
    $stmt->execute([$userId, $successfulInvites]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get next reward tier to show progress towards
 */
function getNextRewardTier($db, $userId) {
    $successfulInvites = getSuccessfulInvitesCount($db, $userId);
    
    $stmt = $db->prepare('
        SELECT t.*
        FROM sprakapp_referral_reward_tiers t
        WHERE t.is_active = TRUE
        AND t.required_invites > ?
        ORDER BY t.required_invites ASC
        LIMIT 1
    ');
    $stmt->execute([$successfulInvites]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Create reward for specific tier
 */
function createRewardForTier($db, $userId, $tierId) {
    // Get tier details
    $stmt = $db->prepare('SELECT * FROM sprakapp_referral_reward_tiers WHERE id = ? AND is_active = TRUE');
    $stmt->execute([$tierId]);
    $tier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tier) {
        throw new Exception('Invalid or inactive tier');
    }
    
    // Check if user qualifies
    $successfulInvites = getSuccessfulInvitesCount($db, $userId);
    if ($successfulInvites < $tier['required_invites']) {
        throw new Exception('User does not qualify for this tier');
    }
    
    // Check if reward already exists for this tier
    $stmt = $db->prepare('SELECT id FROM sprakapp_referral_rewards WHERE user_id = ? AND tier_id = ?');
    $stmt->execute([$userId, $tierId]);
    if ($stmt->rowCount() > 0) {
        throw new Exception('Reward already created for this tier');
    }
    
    // Create the reward
    $stmt = $db->prepare('
        INSERT INTO sprakapp_referral_rewards 
        (user_id, reward_type, reward_value, reward_status, invites_at_grant, tier_id, chapter_limit) 
        VALUES (?, ?, ?, "pending", ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        $tier['reward_type'],
        $tier['reward_value'],
        $tier['required_invites'], // Store how many invites this reward was for
        $tierId,
        $tier['chapter_limit']
    ]);
    
    $rewardId = $db->lastInsertId();
    
    // Get created reward
    $stmt = $db->prepare('SELECT * FROM sprakapp_referral_rewards WHERE id = ?');
    $stmt->execute([$rewardId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if reward tiers system is enabled (has active tiers)
 */
function hasRewardTiers($db) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM sprakapp_referral_reward_tiers WHERE is_active = TRUE');
    $stmt->execute();
    return $stmt->fetchColumn() > 0;
}

/**
 * Get reward statistics for user (with tiers support)
 */
function getRewardStatsWithTiers($db, $userId) {
    $successfulInvites = getSuccessfulInvitesCount($db, $userId);
    $availableTiers = getAvailableRewardTiers($db, $userId);
    $nextTier = getNextRewardTier($db, $userId);
    
    // Count unclaimed rewards
    $unclaimedCount = 0;
    foreach ($availableTiers as $tier) {
        if ($tier['claimed_status'] === null) {
            $unclaimedCount++;
        }
    }
    
    $result = [
        'successful_invites' => $successfulInvites,
        'available_tiers' => $availableTiers,
        'unclaimed_rewards_count' => $unclaimedCount,
        'next_tier' => $nextTier,
        'invites_until_next_reward' => $nextTier ? max(0, $nextTier['required_invites'] - $successfulInvites) : 0
    ];
    
    return $result;
}

/**
 * Enhanced version of checkAndGrantReward that always uses tiers
 */
function checkAndGrantRewardWithTiers($db, $referrerId) {
    $nextTier = getNextAvailableRewardTier($db, $referrerId);
    
    if ($nextTier) {
        return createRewardForTier($db, $referrerId, $nextTier['id']);
    }
    
    return null;
}

?>