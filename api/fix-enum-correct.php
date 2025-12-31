<?php
header('Content-Type: text/plain; charset=utf-8');
require_once '../config/database.php';

echo "=== RÄTT ENUM FIX (free_month -> free_days) ===\n";
echo "Datum: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✓ Ansluten till databas: polyverb_sprakapp\n\n";
    
    // STEG 1: Kolla nuvarande tillstånd
    echo "=== STEG 1: KONTROLLERA NUVARANDE TILLSTÅND ===\n";
    
    $query = "SELECT reward_type, reward_value, COUNT(*) as count FROM sprakapp_referral_reward_tiers GROUP BY reward_type, reward_value";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Befintlig data:\n";
    foreach ($result as $row) {
        echo "- {$row['reward_type']}: {$row['reward_value']} ({$row['count']} rader)\n";
    }
    echo "\n";
    
    // STEG 2: Först konvertera alla credits tillbaka till free_days
    // (eftersom vi tidigare konverterade free_month till credits av misstag)
    echo "=== STEG 2: KONVERTERA ALLA TILL free_days ===\n";
    
    $query = "UPDATE sprakapp_referral_reward_tiers SET reward_type = 'free_days' WHERE reward_type = 'credits'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rowsUpdated = $stmt->rowCount();
    echo "✓ Konverterade {$rowsUpdated} rader från 'credits' till 'free_days'\n";
    
    // Kolla data efter konvertering  
    $query = "SELECT reward_type, COUNT(*) as count FROM sprakapp_referral_reward_tiers GROUP BY reward_type";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Efter konvertering:\n";
    foreach ($result as $row) {
        echo "- {$row['reward_type']}: {$row['count']} rader\n";
    }
    echo "\n";
    
    // STEG 3: Nu ändra ENUM-definitionen
    echo "=== STEG 3: ÄNDRA ENUM-DEFINITION ===\n";
    
    try {
        $query = "ALTER TABLE sprakapp_referral_reward_tiers MODIFY COLUMN reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'";
        $conn->exec($query);
        echo "✓ Ändrade enum till ENUM('free_days', 'credits')\n";
    } catch (PDOException $e) {
        echo "✗ Direkt ändring misslyckades: " . $e->getMessage() . "\n";
        echo "Försöker med DROP och ADD metoden...\n\n";
        
        try {
            // Lägg till temp kolumn med rätt enum
            $conn->exec("ALTER TABLE sprakapp_referral_reward_tiers ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'");
            echo "✓ Lade till ny kolumn med korrekt ENUM\n";
            
            // Kopiera data (alla är nu free_days)
            $conn->exec("UPDATE sprakapp_referral_reward_tiers SET reward_type_new = 'free_days'");
            echo "✓ Kopierade all data till ny kolumn som 'free_days'\n";
            
            // Ta bort gamla
            $conn->exec("ALTER TABLE sprakapp_referral_reward_tiers DROP COLUMN reward_type");
            echo "✓ Tog bort gamla kolumnen\n";
            
            // Byt namn
            $conn->exec("ALTER TABLE sprakapp_referral_reward_tiers CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days'");
            echo "✓ Bytte namn på ny kolumn till reward_type\n";
            
        } catch (PDOException $e2) {
            echo "✗ Alternativ metod misslyckades också: " . $e2->getMessage() . "\n";
            throw $e2;
        }
    }
    
    // STEG 4: Final verifiering
    echo "\n=== STEG 4: FINAL VERIFIERING ===\n";
    
    $query = "DESCRIBE sprakapp_referral_reward_tiers";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($result as $row) {
        if ($row['Field'] === 'reward_type') {
            echo "✓ Final reward_type ENUM: " . $row['Type'] . "\n";
            break;
        }
    }
    
    $query = "SELECT reward_type, reward_value, COUNT(*) as count FROM sprakapp_referral_reward_tiers GROUP BY reward_type, reward_value ORDER BY reward_value";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nFinal data i tabellen:\n";
    foreach ($result as $row) {
        echo "- {$row['reward_type']}: {$row['reward_value']} dagar ({$row['count']} rader)\n";
    }
    
    echo "\n=== 🎉 SUCCESS! ENUM KORREKT FIXAT! 🎉 ===\n";
    echo "✓ ENUM är nu: ENUM('free_days', 'credits')\n";
    echo "✓ Alla värden är 'free_days' (vilket är korrekt för dagar)\n";
    echo "✓ Databasen är redo för tier-systemet!\n";
    
} catch (Exception $e) {
    echo "\n✗ KRITISKT FEL: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>