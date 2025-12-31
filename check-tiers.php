<?php
// Direct database test to check reward tiers
require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Checking reward tiers table ===\n";
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'sprakapp_referral_reward_tiers'");
    $tableExists = $stmt->rowCount() > 0;
    echo "Table exists: " . ($tableExists ? "YES" : "NO") . "\n";
    
    if ($tableExists) {
        // Check table structure
        $stmt = $db->query("DESCRIBE sprakapp_referral_reward_tiers");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nTable structure:\n";
        foreach ($columns as $column) {
            echo "- {$column['Field']}: {$column['Type']}\n";
        }
        
        // Check existing data
        $stmt = $db->query("SELECT * FROM sprakapp_referral_reward_tiers ORDER BY required_invites");
        $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nExisting tiers: " . count($tiers) . "\n";
        foreach ($tiers as $tier) {
            echo "- ID {$tier['id']}: {$tier['required_invites']} invites → {$tier['reward_value']} {$tier['reward_type']}\n";
        }
        
        // Check referral config
        echo "\n=== Checking referral config ===\n";
        $stmt = $db->query("SELECT * FROM sprakapp_referral_config");
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Config entries: " . count($configs) . "\n";
        foreach ($configs as $config) {
            echo "- ID {$config['id']}: {$config['new_user_trial_days']} trial days, reward: {$config['reward_value']} {$config['reward_type']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>