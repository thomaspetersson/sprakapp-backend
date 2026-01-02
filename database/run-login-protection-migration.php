<?php
/**
 * Run Login Protection Migration
 * 
 * This script creates the tables needed for login protection:
 * - sprakapp_failed_logins
 * - sprakapp_account_lockouts
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Login Protection Migration ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Creating sprakapp_failed_logins table...\n";
    
    $sql = "CREATE TABLE IF NOT EXISTS sprakapp_failed_logins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_ip (ip_address),
        INDEX idx_attempted_at (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "✓ sprakapp_failed_logins table created\n\n";
    
    echo "Creating sprakapp_account_lockouts table...\n";
    
    $sql = "CREATE TABLE IF NOT EXISTS sprakapp_account_lockouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        email VARCHAR(255) NOT NULL,
        locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        locked_until TIMESTAMP NOT NULL,
        reason VARCHAR(255) DEFAULT 'Too many failed login attempts',
        is_permanent BOOLEAN DEFAULT FALSE,
        INDEX idx_user_id (user_id),
        INDEX idx_email (email),
        INDEX idx_locked_until (locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "✓ sprakapp_account_lockouts table created\n\n";
    
    // Verify tables exist
    $stmt = $db->query("SHOW TABLES LIKE 'sprakapp_failed_logins'");
    $failedLoginsExists = $stmt->rowCount() > 0;
    
    $stmt = $db->query("SHOW TABLES LIKE 'sprakapp_account_lockouts'");
    $lockoutsExists = $stmt->rowCount() > 0;
    
    if ($failedLoginsExists && $lockoutsExists) {
        echo "✓ Migration completed successfully!\n\n";
        echo "You can now:\n";
        echo "1. Run test-login-protection.php to test the system\n";
        echo "2. Set up reCAPTCHA (see RECAPTCHA_INTEGRATION.md)\n";
        echo "3. Deploy to production\n";
    } else {
        echo "✗ Migration failed - tables not created\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
