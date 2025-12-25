<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP is working!<br>";

echo "Trying to load config...<br>";
require_once __DIR__ . '/../config/config.php';
echo "Config loaded!<br>";

echo "Trying database connection...<br>";
try {
    echo "Host: localhost<br>";
    echo "Database: polyverb_sprakapp<br>";
    echo "User: polyverb<br>";
    
    $conn = new PDO(
        "mysql:host=localhost;port=3306;dbname=polyverb_sprakapp",
        "polyverb",
        "Knutte4711" // ÄNDRA DETTA TILL DITT RIKTIGA LÖSENORD!
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected successfully!<br>";
    
    // Test query
    $query = "SELECT COUNT(*) as count FROM sprakapp_courses";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Courses in database: " . $result['count'] . "<br>";
    
} catch (PDOException $e) {
    echo "PDO ERROR: " . $e->getMessage() . "<br>";
    echo "Error Code: " . $e->getCode() . "<br>";
}
