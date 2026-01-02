<?php
/**
 * Debug: Check why rinapereira00@gmail.com is not showing in admin
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

echo "=== DEBUG USER LIST ===\n\n";

// Check if user exists in sprakapp_users
echo "1. Checking sprakapp_users for rinapereira00@gmail.com:\n";
$stmt = $db->prepare("SELECT id, email, email_verified, created_at FROM sprakapp_users WHERE email = 'rinapereira00@gmail.com'");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user) {
    echo "✓ User found:\n";
    print_r($user);
} else {
    echo "✗ User NOT found in sprakapp_users\n";
}
echo "\n";

// Check profile
if ($user) {
    echo "2. Checking sprakapp_profiles for user ID: " . $user['id'] . ":\n";
    $stmt = $db->prepare("SELECT * FROM sprakapp_profiles WHERE id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        echo "✓ Profile found:\n";
        print_r($profile);
    } else {
        echo "✗ No profile found (this is OK, profile is optional)\n";
    }
    echo "\n";
}

// Run the actual query from getAllProfiles
echo "3. Running getAllProfiles query:\n";
$query = "SELECT u.id, u.email, u.created_at, p.first_name, p.last_name, p.avatar_url, 
          COALESCE(p.role, 'user') as role,
          (SELECT COUNT(*) FROM sprakapp_user_course_access uc WHERE uc.user_id = u.id AND uc.subscription_status = 'active') as active_subscriptions
          FROM sprakapp_users u
          LEFT JOIN sprakapp_profiles p ON u.id = p.id
          WHERE u.email = 'rinapereira00@gmail.com'";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    echo "✓ Query returns:\n";
    print_r($result);
} else {
    echo "✗ Query returns nothing\n";
}
echo "\n";

// Check all users count
echo "4. Total users in system:\n";
$stmt = $db->query("SELECT COUNT(*) as count FROM sprakapp_users");
$count = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Total users: " . $count['count'] . "\n\n";

// Show last 5 registered users
echo "5. Last 5 registered users:\n";
$query = "SELECT u.id, u.email, u.created_at, u.email_verified,
          COALESCE(p.role, 'user') as role
          FROM sprakapp_users u
          LEFT JOIN sprakapp_profiles p ON u.id = p.id
          ORDER BY u.created_at DESC
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent as $u) {
    echo "- {$u['email']} (created: {$u['created_at']}, verified: " . ($u['email_verified'] ? 'YES' : 'NO') . ", role: {$u['role']})\n";
}
