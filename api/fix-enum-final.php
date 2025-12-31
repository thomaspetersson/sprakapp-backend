<?php
header('Content-Type: text/plain; charset=utf-8');
require_once '../config/database.php';

echo "=== DATABAS ENUM FIX START ===\n";
echo "Datum: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✓ Ansluten till databas: polyverb_sprakapp\n\n";
    
    // STEG 1: Kolla nuvarande tillstånd
    echo "=== STEG 1: KONTROLLERA NUVARANDE TILLSTÅND ===\n";
    
    $query = "DESCRIBE sprakapp_referral_reward_tiers";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $currentEnumType = '';
    foreach ($result as $row) {
        if ($row['Field'] === 'reward_type') {
            $currentEnumType = $row['Type'];
            echo "Nuvarande reward_type: " . $currentEnumType . "\n";
            break;
        }
    }
    
    if (strpos($currentEnumType, 'free_month') === false) {
        echo "✓ ENUM är redan korrekt - inget att fixa!\n";
        exit;
    }
    
    // Kolla befintlig data
    $query = "SELECT reward_type, COUNT(*) as count FROM sprakapp_referral_reward_tiers GROUP BY reward_type";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nBefintlig data:\n";
    foreach ($result as $row) {
        echo "- {$row['reward_type']}: {$row['count']} rader\n";
    }
    echo "\n";
    
    // STEG 2: Uppdatera befintlig data
    echo "=== STEG 2: KONVERTERA BEFINTLIG DATA ===\n";
    
    $query = "UPDATE sprakapp_referral_reward_tiers SET reward_type = 'credits' WHERE reward_type = 'free_month'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rowsUpdated = $stmt->rowCount();
    echo "✓ Konverterade {$rowsUpdated} rader från 'free_month' till 'credits'\n\n";
    
    // STEG 3: Ändra enum-definitionen
    echo "=== STEG 3: ÄNDRA ENUM-DEFINITION ===\n";
    
    // Metod 1: Direkt ALTER
    try {
        $query = "ALTER TABLE sprakapp_referral_reward_tiers MODIFY COLUMN reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'";
        $conn->exec($query);
        echo "✓ Ändrade enum direkt till ENUM('free_days', 'credits')\n";
    } catch (PDOException $e) {
        echo "✗ Direkt ändring misslyckades: " . $e->getMessage() . "\n";
        echo "Försöker med temporär kolumn-metoden...\n\n";
        
        // Metod 2: Temporär kolumn
        try {
            // Lägg till temp kolumn
            $conn->exec("ALTER TABLE sprakapp_referral_reward_tiers ADD COLUMN reward_type_temp ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'");
            echo "✓ Lade till temporär kolumn\n";
            
            // Kopiera data
            $conn->exec("UPDATE sprakapp_referral_reward_tiers SET reward_type_temp = CASE WHEN reward_type = 'credits' THEN 'credits' ELSE 'free_days' END");
            echo "✓ Kopierade data till temporär kolumn\n";
            
            // Ta bort gamla
            $conn->exec("ALTER TABLE sprakapp_referral_reward_tiers DROP COLUMN reward_type");
            echo "✓ Tog bort gamla kolumnen\n";
            
            // Byt namn
            $conn->exec("ALTER TABLE sprakapp_referral_reward_tiers CHANGE COLUMN reward_type_temp reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'");
            echo "✓ Bytte namn på temporär kolumn\n";
            
        } catch (PDOException $e2) {
            echo "✗ Temporär kolumn-metoden misslyckades också: " . $e2->getMessage() . "\n";
            throw $e2;
        }
    }
    
    // STEG 4: Verifiera resultat
    echo "\n=== STEG 4: VERIFIERING ===\n";
    
    $query = "DESCRIBE sprakapp_referral_reward_tiers";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($result as $row) {
        if ($row['Field'] === 'reward_type') {
            echo "✓ Ny reward_type: " . $row['Type'] . "\n";
            break;
        }
    }
    
    $query = "SELECT reward_type, COUNT(*) as count FROM sprakapp_referral_reward_tiers GROUP BY reward_type";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nFinal data:\n";
    foreach ($result as $row) {
        echo "- {$row['reward_type']}: {$row['count']} rader\n";
    }
    
    echo "\n=== SUCCESS! ENUM FIXAT! ===\n";
    echo "reward_type är nu ENUM('free_days', 'credits')\n";
    echo "Alla 'free_month' värden konverterade till 'credits'\n";
    
} catch (Exception $e) {
    echo "\n✗ KRITISKT FEL: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>