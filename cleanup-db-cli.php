#!/usr/bin/env php
<?php
/**
 * Database Cleanup CLI Script
 * 
 * Usage:
 *   php cleanup-db-cli.php scan          - Hitta orphaned records
 *   php cleanup-db-cli.php clean TABLE   - Rensa orphaned records från en tabell
 *   php cleanup-db-cli.php list-backups  - Lista alla backups
 *   php cleanup-db-cli.php restore NAME  - Återställ en backup
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/auth.php';

$action = $argv[1] ?? null;
$db = new Database();
$conn = $db->getConnection();

// Tabeller och deras foreign keys till users
$tables_with_user_fk = [
    'sprakapp_profiles' => 'id',
    'sprakapp_user_progress' => 'user_id',
    'sprakapp_exercise_attempts' => 'user_id',
    'sprakapp_user_course_access' => 'user_id',
    'sprakapp_sessions' => 'user_id',
    'sprakapp_user_subscriptions' => 'user_id',
    'sprakapp_referral_rewards' => 'user_id',
    'sprakapp_referral_credits' => 'user_id',
    'sprakapp_credit_transactions' => 'user_id',
];

function printHeader($text) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "  $text\n";
    echo str_repeat("=", 60) . "\n";
}

function printSuccess($text) {
    echo "\033[92m✓ $text\033[0m\n";
}

function printError($text) {
    echo "\033[91m✗ $text\033[0m\n";
}

function printWarning($text) {
    echo "\033[93m⚠ $text\033[0m\n";
}

function printInfo($text) {
    echo "ℹ $text\n";
}

try {
    if (!$action) {
        printError("Du måste ange en action!");
        echo "\nAnvändning:\n";
        echo "  php cleanup-db-cli.php scan          - Hitta orphaned records\n";
        echo "  php cleanup-db-cli.php clean TABLE   - Rensa orphaned records från en tabell\n";
        echo "  php cleanup-db-cli.php list-backups  - Lista alla backups\n";
        echo "  php cleanup-db-cli.php restore NAME  - Återställ en backup\n";
        exit(1);
    }

    if ($action === 'scan') {
        printHeader("SCANNA EFTER ORPHANED RECORDS");
        
        $found_issues = false;
        
        foreach ($tables_with_user_fk as $table => $fk_column) {
            if ($table === 'sprakapp_profiles') {
                $query = "SELECT COUNT(*) as count FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users)";
            } else {
                $query = "SELECT COUNT(*) as count FROM $table WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL";
            }
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count > 0) {
                $found_issues = true;
                printWarning("$table: $count orphaned records");
                
                // Visa exempel
                if ($table === 'sprakapp_profiles') {
                    $example_query = "SELECT id FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users) LIMIT 3";
                } else {
                    $example_query = "SELECT id, $fk_column FROM $table WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL LIMIT 3";
                }
                
                $example_stmt = $conn->prepare($example_query);
                $example_stmt->execute();
                $examples = $example_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($examples as $ex) {
                    echo "    - " . json_encode($ex) . "\n";
                }
            }
        }
        
        if (!$found_issues) {
            printSuccess("Databasen är ren - inga orphaned records hittade!");
        }
        
    } elseif ($action === 'clean') {
        $table = $argv[2] ?? null;
        
        if (!$table || !isset($tables_with_user_fk[$table])) {
            printError("Du måste ange en giltig tabell!");
            echo "\nGiltiga tabeller:\n";
            foreach (array_keys($tables_with_user_fk) as $t) {
                echo "  - $t\n";
            }
            exit(1);
        }
        
        $fk_column = $tables_with_user_fk[$table];
        
        printHeader("RENSA ORPHANED RECORDS FRÅN $table");
        
        // Kontrollera hur många som ska raderas
        if ($table === 'sprakapp_profiles') {
            $count_query = "SELECT COUNT(*) as count FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users)";
        } else {
            $count_query = "SELECT COUNT(*) as count FROM $table WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL";
        }
        
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute();
        $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count === 0) {
            printSuccess("Ingen orphaned records i $table - inget att göra!");
            exit(0);
        }
        
        printWarning("$count orphaned records kommer att raderas från $table");
        printInfo("En backup skapas automatiskt före radering...");
        
        // Skapa backup
        $backup_table = $table . '_backup_' . date('YmdHis');
        $conn->exec("CREATE TABLE $backup_table AS SELECT * FROM $table 
                    WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) 
                    OR $fk_column IS NULL");
        
        printSuccess("Backup skapad: $backup_table");
        
        // Bekräfta innan radering
        echo "\nÄr du säker? Skriv 'ja' för att bekräfta: ";
        $input = trim(fgets(STDIN));
        
        if ($input !== 'ja') {
            printError("Radering avbruten!");
            exit(0);
        }
        
        // Radera orphaned records
        if ($table === 'sprakapp_profiles') {
            $delete_query = "DELETE FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users)";
        } else {
            $delete_query = "DELETE FROM $table WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL";
        }
        
        $stmt = $conn->prepare($delete_query);
        $stmt->execute();
        $deleted_count = $stmt->rowCount();
        
        printSuccess("$deleted_count orphaned records raderade från $table");
        printInfo("Backup: $backup_table (kan användas för att återställa om behövs)");
        
    } elseif ($action === 'list-backups') {
        printHeader("LISTA BACKUPS");
        
        $query = "SHOW TABLES LIKE 'sprakapp_%_backup_%'";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $backups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($backups)) {
            printSuccess("Inga backups hittade!");
            exit(0);
        }
        
        foreach ($backups as $backup) {
            $count_query = "SELECT COUNT(*) as count FROM $backup";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->execute();
            $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            echo "  $backup ($count records)\n";
        }
        
    } elseif ($action === 'restore') {
        $backup_name = $argv[2] ?? null;
        
        if (!$backup_name) {
            printError("Du måste ange ett backup-namn!");
            printInfo("Använd 'php cleanup-db-cli.php list-backups' för att se tillgängliga backups");
            exit(1);
        }
        
        // Validera backup_name för säkerhet
        if (!preg_match('/^sprakapp_[a-z_]+_backup_\d{14}$/', $backup_name)) {
            printError("Ogiltigt backup-namn format!");
            exit(1);
        }
        
        // Kontrollera att backup-tabellen existerar
        $check_query = "SHOW TABLES LIKE ?";
        $stmt = $conn->prepare($check_query);
        $stmt->execute([$backup_name]);
        
        if (!$stmt->fetch()) {
            printError("Backup-tabell $backup_name hittades inte!");
            exit(1);
        }
        
        printHeader("ÅTERSTÄLL BACKUP: $backup_name");
        
        // Hämta original-tabellnamn
        preg_match('/^(sprakapp_[a-z_]+)_backup_/', $backup_name, $matches);
        $original_table = $matches[1];
        
        // Kontrollera hur många records som ska återställas
        $count_query = "SELECT COUNT(*) as count FROM $backup_name";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute();
        $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        printWarning("$count records kommer att återställas till $original_table");
        
        // Bekräfta före återställning
        echo "\nÄr du säker? Skriv 'ja' för att bekräfta: ";
        $input = trim(fgets(STDIN));
        
        if ($input !== 'ja') {
            printError("Återställning avbruten!");
            exit(0);
        }
        
        // Kopiera tillbaka data
        $conn->exec("INSERT INTO $original_table SELECT * FROM $backup_name");
        
        printSuccess("$count records återställda från $backup_name till $original_table");
        
    } else {
        printError("Okänd action: $action");
        echo "\nGiltiga actions: scan, clean, list-backups, restore\n";
        exit(1);
    }
    
    echo "\n";
    
} catch (Exception $e) {
    printError($e->getMessage());
    exit(1);
}
?>
