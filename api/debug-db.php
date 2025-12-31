<?php
header('Content-Type: text/plain; charset=utf-8');
require_once '../config/database.php';

echo "=== DATABAS DEBUG ===\n";
echo "Datum: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✓ Databas-anslutning fungerar\n\n";
    
    // Kolla vilken databas vi är anslutna till
    $query = "SELECT DATABASE() as current_db";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Nuvarande databas: " . $result['current_db'] . "\n\n";
    
    // Kolla om tabellen finns
    $query = "SHOW TABLES LIKE 'sprakapp_referral_reward_tiers'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($result)) {
        echo "✗ TABELLEN 'sprakapp_referral_reward_tiers' FINNS INTE!\n";
        
        // Lista alla tabeller
        $query = "SHOW TABLES";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Befintliga tabeller:\n";
        foreach ($tables as $table) {
            echo "- " . array_values($table)[0] . "\n";
        }
        
    } else {
        echo "✓ Tabellen 'sprakapp_referral_reward_tiers' finns\n\n";
        
        // Kolla tabellstruktur
        $query = "DESCRIBE sprakapp_referral_reward_tiers";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Tabellstruktur:\n";
        foreach ($columns as $col) {
            echo "- {$col['Field']}: {$col['Type']} (Default: {$col['Default']})\n";
        }
        echo "\n";
        
        // Kolla data
        $query = "SELECT * FROM sprakapp_referral_reward_tiers";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Data i tabellen (" . count($rows) . " rader):\n";
        foreach ($rows as $row) {
            echo "- ID: {$row['id']}, Invites: {$row['required_invites']}, Type: {$row['reward_type']}, Value: {$row['reward_value']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ FEL: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>