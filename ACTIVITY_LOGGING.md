# Activity Logging System

## Översikt

Systemet loggar alla viktiga affärshändelser till en dedikerad loggfil: `backend/logs/activity.log`

## Loggade händelser

### Användarhantering
- **USER_REGISTERED** - När ny användare registrerar sig
- **EMAIL_VERIFIED** - När email verifieras
- **USER_LOGGED_IN** - Varje lyckad inloggning
- **ADMIN_IMPERSONATED** - När admin loggar in som annan användare

### Prenumerationer
- **SUBSCRIPTION_CREATED** - Ny prenumeration skapad
- **SUBSCRIPTION_RENEWED** - Prenumeration förnyas (återkommande betalning)
- **SUBSCRIPTION_CANCELLED** - Prenumeration avslutad
- **SUBSCRIPTION_EXPIRED** - Prenumeration har gått ut

### Kurser
- **COURSE_SELECTED** - Användare väljer en kurs (trial, köp, belöning)
- **COURSE_ACCESS_GRANTED** - Kursåtkomst beviljad
- **COURSE_ACCESS_REVOKED** - Kursåtkomst återkallad
- **TRIAL_STARTED** - Trial-period påbörjad
- **TRIAL_EXPIRED** - Trial-period avslutad

### Betalningar
- **PAYMENT_SUCCESS** - Lyckad betalning
- **PAYMENT_FAILED** - Misslyckad betalning

### Referral-system
- **REFERRAL_USED** - Referral-kod använd vid registrering

### Administration
- **ROLE_CHANGED** - Användarroll ändrad av admin

## Loggformat

```
[2026-01-02 15:30:45] EVENT_TYPE | User: email@example.com (user_id) | IP: 1.2.3.4 | Data: {"key":"value"}
```

### Exempel

```
[2026-01-02 15:30:45] USER_REGISTERED | User: test@example.com (123) | IP: 192.168.1.1 | Data: {"referred_by":"johndoe","trial_days":7}
[2026-01-02 15:31:12] USER_LOGGED_IN | User: test@example.com (123) | IP: 192.168.1.1 | Data: []
[2026-01-02 15:32:00] COURSE_SELECTED | User: test@example.com (123) | IP: 192.168.1.1 | Data: {"course_id":5,"course_title":"Spanska för nybörjare","reason":"trial"}
[2026-01-02 16:00:00] SUBSCRIPTION_CREATED | User: test@example.com (123) | IP: 192.168.1.1 | Data: {"subscription_id":"sub_abc123","plan_name":"Månadsplan","course_count":2}
[2026-01-02 16:00:05] PAYMENT_SUCCESS | User: test@example.com (123) | IP: 192.168.1.1 | Data: {"subscription_id":"sub_abc123","amount":99.00,"currency":"SEK"}
```

## Integration i kod

### Steg 1: Inkludera ActivityLogger

```php
require_once __DIR__ . '/../lib/ActivityLogger.php';
$activityLogger = new ActivityLogger();
```

### Steg 2: Logga händelser

```php
// Vid registrering
$activityLogger->userRegistered($userId, $email, $referrerId, $trialDays);

// Vid inloggning
$activityLogger->userLoggedIn($userId, $email);

// Vid prenumeration
$activityLogger->subscriptionCreated($userId, $email, $subscriptionId, $planName, $courseCount);

// Vid kursval
$activityLogger->courseSelected($userId, $email, $courseId, $courseTitle, $reason);

// Vid betalning
$activityLogger->paymentSuccess($userId, $email, $subscriptionId, $amount, $currency);
```

## Integrerade endpoints

### auth-session.php
- Registrering (USER_REGISTERED, REFERRAL_USED)
- Inloggning (USER_LOGGED_IN)
- Admin impersonation (ADMIN_IMPERSONATED)

### stripe-webhook.php
- checkout.session.completed (SUBSCRIPTION_CREATED, COURSE_ACCESS_GRANTED, PAYMENT_SUCCESS)
- customer.subscription.deleted (SUBSCRIPTION_CANCELLED)
- invoice.payment_succeeded (SUBSCRIPTION_RENEWED, PAYMENT_SUCCESS)
- invoice.payment_failed (PAYMENT_FAILED)

### admin.php
- assignCourseToUser (COURSE_ACCESS_GRANTED)
- revokeCourseFromUser (COURSE_ACCESS_REVOKED)

### referral.php
- select_trial_course (COURSE_SELECTED, TRIAL_STARTED)
- claim_reward (COURSE_SELECTED, COURSE_ACCESS_GRANTED)

## Användningsområden

### Affärsanalyser
- Hur många registrerar sig per dag?
- Konverteringsgrad från trial till betalande kund
- Vilka kurser är populärast?
- Genomsnittlig inkomst per användare

### Support
- Se användarens fullständiga historik
- Verifiera att betalningar gått igenom
- Undersöka när kursåtkomst beviljades/återkallades

### Säkerhet & Compliance
- Audit trail för admin-åtgärder
- Spåra impersonation
- Verifiera referral-användning
- GDPR-dokumentation

### Felsökning
- Se exakt när något gick fel
- Jämför timestamps mellan frontend och backend
- Identifiera misslyckade betalningar
- Upptäcka ovanliga mönster

## Test

Kör testskriptet för att verifiera funktionalitet:

```bash
php backend/test-activity-logger.php
```

Detta skapar:
1. Katalogen `backend/logs/` om den inte finns
2. Filen `backend/logs/activity.log`
3. Testloggar med olika händelsetyper

## Loggrotation (framtida förbättring)

För produktion bör log rotation implementeras för att förhindra att filen växer för stort:

```bash
# Linux logrotate config
/var/www/html/backend/logs/activity.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
}
```

Eller implementera date-baserad rotation i PHP:

```php
$logFile = __DIR__ . '/logs/activity-' . date('Y-m-d') . '.log';
```

## Säkerhet

- Loggfilen innehåller **ingen känslig data** (inga lösenord, inga kortnummer)
- Användar-email loggas för identifiering
- IP-adresser loggas för säkerhet
- Filen bör inte vara publikt tillgänglig (.htaccess eller nginx config)

## Performance

- Asynkron skrivning (file_put_contents med FILE_APPEND är snabb)
- Ingen databas-overhead
- Duplicerad till error_log som backup
- Minimal påverkan på request-tid (<1ms per loggpost)
