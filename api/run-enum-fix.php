<?php
header('Content-Type: text/plain');
require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "=== ENUM FIX START ===\n";
    echo "Ansluten till databas!\n";
    echo "Kör enum-fix för reward_type...\n\n";
    
    // Steg 1: Lägg till temporära kolumner
    echo "STEG 1: Lägger till temporära kolumner...\n";
    
    $queries = [
        "ALTER TABLE sprakapp_referral_config ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'",
        "ALTER TABLE sprakapp_referral_rewards ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'", 
        "ALTER TABLE sprakapp_referral_reward_tiers ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'"
    ];
    
    foreach ($queries as $query) {
        try {
            $conn->exec($query);
            echo "✓ " . substr($query, 0, 60) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "~ Kolumn finns redan: " . substr($query, 0, 40) . "...\n";
            } else {
                echo "✗ FEL: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Steg 2: Kopiera data
    echo "\nSTEG 2: Kopierar och konverterar data...\n";
    
    $queries = [
        "UPDATE sprakapp_referral_config SET reward_type_new = CASE WHEN reward_type = 'free_month' THEN 'free_days' WHEN reward_type = 'credits' THEN 'credits' ELSE 'free_days' END",
        "UPDATE sprakapp_referral_rewards SET reward_type_new = CASE WHEN reward_type = 'free_month' THEN 'free_days' WHEN reward_type = 'credits' THEN 'credits' ELSE 'free_days' END",
        "UPDATE sprakapp_referral_reward_tiers SET reward_type_new = CASE WHEN reward_type = 'free_month' THEN 'free_days' WHEN reward_type = 'credits' THEN 'credits' ELSE 'free_days' END"
    ];
    
    foreach ($queries as $query) {
        try {
            $conn->exec($query);
            echo "✓ Data konverterad\n";
        } catch (PDOException $e) {
            echo "✗ FEL: " . $e->getMessage() . "\n";
        }
    }
    
    // Steg 3: Ta bort gamla kolumner
    echo "\nSTEG 3: Tar bort gamla kolumner...\n";
    
    $queries = [
        "ALTER TABLE sprakapp_referral_config DROP COLUMN reward_type",
        "ALTER TABLE sprakapp_referral_rewards DROP COLUMN reward_type", 
        "ALTER TABLE sprakapp_referral_reward_tiers DROP COLUMN reward_type"
    ];
    
    foreach ($queries as $query) {
        try {
            $conn->exec($query);
            echo "✓ Gamla kolumnen borttagen\n";
        } catch (PDOException $e) {
            echo "✗ FEL: " . $e->getMessage() . "\n";
        }
    }
    
    // Steg 4: Byt namn
    echo "\nSTEG 4: Byter namn på nya kolumner...\n";
    
    $queries = [
        "ALTER TABLE sprakapp_referral_config CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'",
        "ALTER TABLE sprakapp_referral_rewards CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'",
        "ALTER TABLE sprakapp_referral_reward_tiers CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'"
    ];
    
    foreach ($queries as $query) {
        try {
            $conn->exec($query);
            echo "✓ Kolumn omdöpt\n";
        } catch (PDOException $e) {
            echo "✗ FEL: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== VERIFIERING ===\n";
    
    // Kontrollera resultat för sprakapp_referral_reward_tiers
    $query = "DESCRIBE sprakapp_referral_reward_tiers";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($result as $row) {
        if ($row['Field'] === 'reward_type') {
            echo "sprakapp_referral_reward_tiers.reward_type: " . $row['Type'] . "\n";
        }
    }
    
    echo "\n=== FIX KLAR! ===\n";
    echo "Nu ska reward_type vara 'free_days' eller 'credits' istället för 'free_month'\n";
    
} catch (Exception $e) {
    echo "FEL: " . $e->getMessage() . "\n";
}
?>