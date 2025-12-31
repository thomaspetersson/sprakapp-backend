<?php
/**
 * Skapa password hash för admin-användare
 * 
 * Användning:
 * 1. Redigera $email och $password nedan
 * 2. Kör: php create-admin-hash.php
 * 3. Kopiera hash-värdet och använd i SQL
 */

// ÄNDRA DESSA VÄRDEN
$email = 'din@email.se';
$password = 'ditt_lösenord';

// Generera hash
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Email: $email\n";
echo "Password Hash:\n$hash\n\n";

echo "Använd denna SQL för att skapa admin:\n\n";

$userId = bin2hex(random_bytes(16));
$referralCode = strtoupper(substr(md5($userId . time()), 0, 10));
$trialExpires = date('Y-m-d H:i:s', strtotime('+365 days'));

echo "-- Skapa admin-användare\n";
echo "INSERT INTO sprakapp_users (id, email, password_hash, email_verified, referral_code, trial_expires_at) \n";
echo "VALUES (\n";
echo "    '$userId',\n";
echo "    '$email',\n";
echo "    '$hash',\n";
echo "    1,\n";
echo "    '$referralCode',\n";
echo "    '$trialExpires'\n";
echo ");\n\n";

echo "INSERT INTO sprakapp_profiles (id, role, first_name, last_name) \n";
echo "VALUES ('$userId', 'admin', 'Admin', 'User');\n\n";

echo "-- Verifiera\n";
echo "SELECT u.id, u.email, p.role FROM sprakapp_users u LEFT JOIN sprakapp_profiles p ON u.id = p.id WHERE u.email = '$email';\n";
