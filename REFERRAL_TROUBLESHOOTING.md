# Referral System Troubleshooting Guide

## Problem: Inget visas i Invite Progress / Referral Dashboard

### Steg 1: Verifiera att tabellerna finns i databasen

Kör denna SQL-fil för att kolla tabellerna:
```bash
mysql -u username -p database_name < backend/database/test-referral-system.sql
```

Om tabellerna INTE finns, kör:
```bash
mysql -u username -p database_name < backend/database/referral-schema.sql
```

### Steg 2: Kolla error_log

Efter att någon registrerat sig med en referral-länk, kolla:
- `backend/api/error_log` 
- Apache/PHP error log

Sök efter:
- `[Referral] Signup event logged`
- `[Referral] Failed to log signup event`

### Steg 3: Testa manuellt i databasen

```sql
-- Kolla om användare har referral codes
SELECT id, email, referral_code, referred_by, trial_expires_at, onboarding_completed 
FROM sprakapp_users 
ORDER BY created_at DESC 
LIMIT 5;

-- Kolla om events loggades
SELECT * FROM sprakapp_referral_events ORDER BY created_at DESC LIMIT 10;

-- Kolla om config finns
SELECT * FROM sprakapp_referral_config;
```

### Steg 4: Test-flöde

1. **Användare A** (referrer):
   - Loggar in
   - Går till /referral
   - Kopierar sin länk (t.ex. `/ref/ABC123XYZ`)

2. **Ny användare B** (invited):
   - Klickar på länken `/ref/ABC123XYZ`
   - Ser "You've Been Invited!" sida
   - Klickar "Get Started"
   - Registrerar sig (ska se grön banner om bonus days)

3. **Kontrollera DB**:
   ```sql
   -- Användare B ska ha:
   SELECT email, referred_by, trial_expires_at 
   FROM sprakapp_users 
   WHERE email = 'userB@example.com';
   
   -- Signup event ska finnas:
   SELECT * FROM sprakapp_referral_events 
   WHERE event_type = 'signup' 
   ORDER BY created_at DESC LIMIT 1;
   ```

4. **Användare B** loggar in:
   - Första inloggningen triggar `onboarding_completed`
   - `sprakapp_referral_events` ska få en ny rad med `event_type = 'completed_onboarding'`

5. **Användare A** laddar om dashboard:
   - Ska se `total_invites: 1`
   - Ska se `successful_invites: 1`
   - Om `required_invites_for_reward = 3`, ska se progress 1/3

## Vanliga problem:

### Problem: "Table doesn't exist"
**Lösning**: Kör `referral-schema.sql`

### Problem: "signup" event skapas men inte "completed_onboarding"
**Lösning**: Användare B måste logga in efter registrering. Första inloggningen triggar onboarding completion automatiskt.

### Problem: Inget visas trots att events finns
**Lösning**: Kolla att frontend hämtar från rätt endpoint:
- `/api/referral.php?action=stats` ska returnera JSON med `total_invites` och `successful_invites`

### Problem: "referred_by" är NULL trots att jag använde referral-länk
**Lösning**: 
1. Kolla att sessionStorage har `referral_code` innan registrering
2. Öppna DevTools → Application → Session Storage → Sök efter `referral_code`
3. Kolla browser console för `[Register] Found referral code: ...`

## Debug mode

För att aktivera mer detaljerad logging, lägg till i början av auth.php:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
```

## Snabb-test endpoint

Skapa en test-fil `backend/api/referral-debug.php`:
```php
<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();

echo "<h2>Referral System Debug</h2>";

// Config
$stmt = $db->query('SELECT * FROM sprakapp_referral_config');
echo "<h3>Config:</h3><pre>";
print_r($stmt->fetch(PDO::FETCH_ASSOC));
echo "</pre>";

// Recent users
$stmt = $db->query('SELECT id, email, referral_code, referred_by, onboarding_completed FROM sprakapp_users ORDER BY created_at DESC LIMIT 5');
echo "<h3>Recent Users:</h3><pre>";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";

// Events
$stmt = $db->query('SELECT * FROM sprakapp_referral_events ORDER BY created_at DESC LIMIT 10');
echo "<h3>Recent Events:</h3><pre>";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";
?>
```

Gå till `https://polyverbo.com/api/referral-debug.php` för att se all debug-info.
