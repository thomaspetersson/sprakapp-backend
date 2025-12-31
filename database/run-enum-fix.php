<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Ansluten till databas!\n";
    echo "Kör enum-fix för reward_type...\n\n";
    
    // Läs SQL-filen
    $sql = file_get_contents('fix-enum-direct.sql');
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            echo "Kör: " . substr($statement, 0, 80) . "...\n";
            $conn->exec($statement);
            echo "✓ OK\n";
        } catch (PDOException $e) {
            echo "✗ FEL: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== VERIFIERING ===\n";
    
    // Kontrollera resultat
    $query = "DESCRIBE sprakapp_referral_config";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($result as $row) {
        if ($row['Field'] === 'reward_type') {
            echo "sprakapp_referral_config.reward_type: " . $row['Type'] . "\n";
        }
    }
    
    $query = "DESCRIBE sprakapp_referral_reward_tiers";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($result as $row) {
        if ($row['Field'] === 'reward_type') {
            echo "sprakapp_referral_reward_tiers.reward_type: " . $row['Type'] . "\n";
        }
    }
    
    echo "\nFIX KLAR! ✓\n";
    
} catch (Exception $e) {
    echo "FEL: " . $e->getMessage() . "\n";
}
?>