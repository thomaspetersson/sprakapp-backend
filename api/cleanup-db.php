<?php
header('Content-Type: application/json');

// Aktivera error reporting för debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session för auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kontrollera admin-behörighet (samma som andra API:er använder)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized - Admin access required']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/auth.php';

$db = new Database();
$conn = $db->getConnection();
$action = $_GET['action'] ?? '';
$limit = intval($_GET['limit'] ?? 100);

// Funktion för att kontrollera om tabell existerar
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Tabeller och deras foreign keys till users (kontrollera vilka som faktiskt existerar)
$all_tables_with_user_fk = [
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

$tables_with_user_fk = [];
foreach ($all_tables_with_user_fk as $table => $fk_column) {
    if (tableExists($conn, $table)) {
        $tables_with_user_fk[$table] = $fk_column;
    }
}

try {
    if ($action === 'scan') {
        // Hitta orphaned records
        $results = [];
        
        foreach ($tables_with_user_fk as $table => $fk_column) {
            try {
                // För sprakapp_profiles, använd FOREIGN KEY relation direkt
                if ($table === 'sprakapp_profiles') {
                    $query = "SELECT COUNT(*) as count FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users)";
                } else {
                    $query = "SELECT COUNT(*) as count FROM $table WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL";
                }
                
                $stmt = $conn->prepare($query);
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($count > 0) {
                    // Hämta exempel på orphaned records
                    if ($table === 'sprakapp_profiles') {
                        $example_query = "SELECT id FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users) LIMIT 5";
                    } else {
                        $example_query = "SELECT id, $fk_column FROM $table WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL LIMIT 5";
                    }
                    
                    $example_stmt = $conn->prepare($example_query);
                    $example_stmt->execute();
                    $examples = $example_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $results[] = [
                        'table' => $table,
                        'fk_column' => $fk_column,
                        'orphaned_count' => $count,
                        'examples' => $examples
                    ];
                }
            } catch (Exception $e) {
                error_log("Error processing table $table: " . $e->getMessage());
                // Ignorera tabeller med fel och fortsätt med nästa
                continue;
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'orphaned_records' => $results,
            'total_issues' => count($results),
            'tables_checked' => array_keys($tables_with_user_fk),
            'debug' => [
                'available_tables' => array_keys($tables_with_user_fk),
                'tables_skipped' => array_diff(array_keys($all_tables_with_user_fk), array_keys($tables_with_user_fk))
            ]
        ]);
        
    } elseif ($action === 'clean') {
        // Rensa orphaned records
        $table = $_GET['table'] ?? '';
        
        if (!isset($tables_with_user_fk[$table])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid table']);
            exit;
        }
        
        $fk_column = $tables_with_user_fk[$table];
        
        // Skapa backup före radering
        $backup_table = $table . '_backup_' . date('YmdHis');
        $conn->exec("CREATE TABLE $backup_table AS SELECT * FROM $table 
                    WHERE $fk_column NOT IN (SELECT id FROM sprakapp_users) 
                    OR $fk_column IS NULL LIMIT $limit");
        
        // Radera orphaned records
        if ($table === 'sprakapp_profiles') {
            $delete_query = "DELETE FROM $table WHERE id NOT IN (SELECT id FROM sprakapp_users) LIMIT $limit";
        } else {
            $delete_query = "DELETE FROM $table WHERE ($fk_column NOT IN (SELECT id FROM sprakapp_users) OR $fk_column IS NULL) LIMIT $limit";
        }
        
        $stmt = $conn->prepare($delete_query);
        $stmt->execute();
        $deleted_count = $stmt->rowCount();
        
        echo json_encode([
            'status' => 'success',
            'table' => $table,
            'deleted_count' => $deleted_count,
            'backup_table' => $backup_table,
            'message' => "Raderat $deleted_count orphaned records från $table. Backup skapad i $backup_table"
        ]);
        
    } elseif ($action === 'restore') {
        // Återställ från backup
        $backup_table = $_GET['backup_table'] ?? '';
        
        // Validera backup_table namn för säkerhet
        if (!preg_match('/^sprakapp_[a-z_]+_backup_\d{14}$/', $backup_table)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid backup table name']);
            exit;
        }
        
        // Kontrollera att backup-tabellen existerar
        $check_query = "SHOW TABLES LIKE ?";
        $stmt = $conn->prepare($check_query);
        $stmt->execute([$backup_table]);
        
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Backup table not found']);
            exit;
        }
        
        // Hämta original-tabellnamn från backup-tabellens namn
        preg_match('/^(sprakapp_[a-z_]+)_backup_/', $backup_table, $matches);
        $original_table = $matches[1];
        
        // Kopiera tillbaka data
        $conn->exec("INSERT INTO $original_table SELECT * FROM $backup_table");
        $restored_count = $conn->getAttribute(PDO::ATTR_ROW_COUNT);
        
        echo json_encode([
            'status' => 'success',
            'table' => $original_table,
            'restored_count' => $restored_count,
            'message' => "Återställt $restored_count records från $backup_table"
        ]);
        
    } elseif ($action === 'list-backups') {
        // Lista alla backup-tabeller
        $query = "SHOW TABLES LIKE 'sprakapp_%_backup_%'";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $backups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $backup_info = [];
        foreach ($backups as $backup) {
            $count_query = "SELECT COUNT(*) as count FROM $backup";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->execute();
            $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $backup_info[] = [
                'name' => $backup,
                'record_count' => $count
            ];
        }
        
        echo json_encode([
            'status' => 'success',
            'backups' => $backup_info,
            'total_backups' => count($backup_info)
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: scan, clean, restore, list-backups']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}
?>
