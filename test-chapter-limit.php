<?php
// Test database column constraints for chapter_limit

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Testing chapter_limit NULL constraint</h2>";
    
    // Check table structure
    $stmt = $db->query("DESCRIBE sprakapp_referral_reward_tiers");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Table structure:</h3>";
    foreach ($columns as $column) {
        if ($column['Field'] === 'chapter_limit') {
            echo "<strong>chapter_limit column:</strong><br>";
            echo "Type: " . $column['Type'] . "<br>";
            echo "Null: " . $column['Null'] . "<br>";
            echo "Default: " . $column['Default'] . "<br>";
            echo "Extra: " . $column['Extra'] . "<br>";
            break;
        }
    }
    
    // Try to insert a row with NULL chapter_limit
    echo "<h3>Testing NULL insert:</h3>";
    try {
        $stmt = $db->prepare("INSERT INTO sprakapp_referral_reward_tiers 
                             (required_invites, reward_type, reward_value, chapter_limit) 
                             VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([999, 'free_days', 1, null]);
        
        if ($result) {
            echo "✅ Successfully inserted row with NULL chapter_limit<br>";
            
            // Verify what was actually saved
            $stmt = $db->prepare("SELECT * FROM sprakapp_referral_reward_tiers WHERE required_invites = 999");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "Saved value: " . json_encode($row['chapter_limit']) . "<br>";
            
            // Clean up
            $stmt = $db->prepare("DELETE FROM sprakapp_referral_reward_tiers WHERE required_invites = 999");
            $stmt->execute();
            echo "Test row cleaned up<br>";
        } else {
            echo "❌ Failed to insert row with NULL chapter_limit<br>";
        }
    } catch (Exception $e) {
        echo "❌ Exception during NULL insert: " . $e->getMessage() . "<br>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>