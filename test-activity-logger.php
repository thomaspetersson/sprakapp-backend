<?php
/**
 * Test script to verify ActivityLogger works correctly
 */

require_once __DIR__ . '/lib/ActivityLogger.php';

echo "=== Testing ActivityLogger ===\n\n";

$logger = new ActivityLogger();

echo "1. Testing user registration log...\n";
$logger->userRegistered(123, 'test@example.com', null, 7);

echo "2. Testing subscription created log...\n";
$logger->subscriptionCreated(123, 'test@example.com', 'sub_12345', 'Månadsplan', 1);

echo "3. Testing course selection log...\n";
$logger->courseSelected(123, 'test@example.com', 5, 'Spanska för nybörjare', 'trial');

echo "4. Testing payment success log...\n";
$logger->paymentSuccess(123, 'test@example.com', 'sub_12345', 99.00, 'SEK');

echo "\n=== Log file location ===\n";
echo "backend/logs/activity.log\n";

echo "\n=== Check if log file was created ===\n";
if (file_exists(__DIR__ . '/logs/activity.log')) {
    echo "✓ Log file created successfully!\n\n";
    echo "=== Last 10 lines of log ===\n";
    $lines = file(__DIR__ . '/logs/activity.log');
    $lastLines = array_slice($lines, -10);
    echo implode('', $lastLines);
} else {
    echo "✗ Log file was NOT created\n";
    echo "Check permissions on backend/ directory\n";
}
