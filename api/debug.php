<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: PHP is working<br>";

try {
    echo "Step 2: Trying to load config...<br>";
    require_once __DIR__ . '/../config/config.php';
    echo "Step 3: Config loaded successfully<br>";
} catch (Exception $e) {
    die("Error loading config: " . $e->getMessage());
}

try {
    echo "Step 4: Trying to load auth middleware...<br>";
    require_once __DIR__ . '/../middleware/auth.php';
    echo "Step 5: Auth loaded successfully<br>";
} catch (Exception $e) {
    die("Error loading auth: " . $e->getMessage());
}

try {
    echo "Step 6: Creating database connection...<br>";
    $database = new Database();
    echo "Step 7: Database object created<br>";
    
    $db = $database->getConnection();
    echo "Step 8: Database connected successfully<br>";
} catch (Exception $e) {
    die("Error with database: " . $e->getMessage());
}

echo "All steps completed!";
