<?php
/**
 * Referral System Debug Page
 * Access via: https://polyverbo.com/api/referral-debug.php
 * 
 * This page shows the current state of the referral system
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

$db = (new Database())->getConnection();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Referral System Debug</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #f5f5f5; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
        h3 { color: #666; margin-top: 20px; }
        pre { background: white; padding: 15px; border: 1px solid #ddd; border-radius: 5px; overflow-x: auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; background: white; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #007bff; color: white; }
    </style>
</head>
<body>
    <h2>🎁 Referral System Debug Dashboard</h2>
    <p>Generated: <?= date('Y-m-d H:i:s') ?></p>

    <?php
    // Check if tables exist
    echo "<h3>📋 Table Status</h3>";
    $tables = ['sprakapp_referral_config', 'sprakapp_referral_events', 'sprakapp_referral_rewards', 'sprakapp_referral_credits'];
    echo "<table><tr><th>Table</th><th>Status</th><th>Row Count</th></tr>";
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetchColumn();
            echo "<tr><td>$table</td><td class='success'>✓ EXISTS</td><td>$count</td></tr>";
        } catch (Exception $e) {
            echo "<tr><td>$table</td><td class='error'>✗ NOT FOUND</td><td>-</td></tr>";
        }
    }
    echo "</table>";

    // Config
    echo "<h3>⚙️ Referral Configuration</h3>";
    try {
        $stmt = $db->query('SELECT * FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1');
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($config) {
            echo "<table>";
            foreach ($config as $key => $value) {
                echo "<tr><th>" . htmlspecialchars($key) . "</th><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ No configuration found. Run referral-schema.sql!</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Recent users
    echo "<h3>👥 Recent Users (Last 5)</h3>";
    try {
        $stmt = $db->query('
            SELECT 
                id, 
                email, 
                referral_code, 
                referred_by, 
                trial_expires_at,
                onboarding_completed,
                created_at 
            FROM sprakapp_users 
            ORDER BY created_at DESC 
            LIMIT 5
        ');
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($users) {
            echo "<table><tr><th>Email</th><th>Referral Code</th><th>Referred By</th><th>Trial Expires</th><th>Onboarding</th><th>Created</th></tr>";
            foreach ($users as $user) {
                $onboarding = $user['onboarding_completed'] ? '<span class="success">✓ Completed</span>' : '<span class="warning">Pending</span>';
                echo "<tr>
                    <td>" . htmlspecialchars($user['email']) . "</td>
                    <td>" . htmlspecialchars($user['referral_code'] ?? 'NULL') . "</td>
                    <td>" . htmlspecialchars($user['referred_by'] ?? '-') . "</td>
                    <td>" . htmlspecialchars($user['trial_expires_at'] ?? 'NULL') . "</td>
                    <td>$onboarding</td>
                    <td>" . htmlspecialchars($user['created_at']) . "</td>
                </tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No users found</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Events
    echo "<h3>📊 Referral Events (Last 10)</h3>";
    try {
        $stmt = $db->query('
            SELECT 
                re.event_type,
                re.created_at,
                u1.email as referrer_email,
                u2.email as invited_email
            FROM sprakapp_referral_events re
            LEFT JOIN sprakapp_users u1 ON re.referrer_user_id = u1.id
            LEFT JOIN sprakapp_users u2 ON re.invited_user_id = u2.id
            ORDER BY re.created_at DESC
            LIMIT 10
        ');
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($events) {
            echo "<table><tr><th>Event Type</th><th>Referrer</th><th>Invited User</th><th>Time</th></tr>";
            foreach ($events as $event) {
                $type_icon = $event['event_type'] === 'signup' ? '📝' : '✅';
                echo "<tr>
                    <td>$type_icon " . htmlspecialchars($event['event_type']) . "</td>
                    <td>" . htmlspecialchars($event['referrer_email']) . "</td>
                    <td>" . htmlspecialchars($event['invited_email']) . "</td>
                    <td>" . htmlspecialchars($event['created_at']) . "</td>
                </tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ No referral events found yet</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Rewards
    echo "<h3>🎁 Referral Rewards</h3>";
    try {
        $stmt = $db->query('
            SELECT 
                r.*,
                u.email as user_email
            FROM sprakapp_referral_rewards r
            LEFT JOIN sprakapp_users u ON r.user_id = u.id
            ORDER BY r.created_at DESC
            LIMIT 10
        ');
        $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rewards) {
            echo "<table><tr><th>User</th><th>Type</th><th>Value</th><th>Status</th><th>Created</th></tr>";
            foreach ($rewards as $reward) {
                echo "<tr>
                    <td>" . htmlspecialchars($reward['user_email']) . "</td>
                    <td>" . htmlspecialchars($reward['reward_type']) . "</td>
                    <td>" . htmlspecialchars($reward['reward_value']) . "</td>
                    <td>" . htmlspecialchars($reward['reward_status']) . "</td>
                    <td>" . htmlspecialchars($reward['created_at']) . "</td>
                </tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No rewards granted yet</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Statistics per user
    echo "<h3>📈 Referral Statistics by User</h3>";
    try {
        $stmt = $db->query('
            SELECT 
                u.email,
                u.referral_code,
                COUNT(CASE WHEN re.event_type = "signup" THEN 1 END) as signups,
                COUNT(CASE WHEN re.event_type = "completed_onboarding" THEN 1 END) as completed,
                COUNT(r.id) as rewards_earned
            FROM sprakapp_users u
            LEFT JOIN sprakapp_referral_events re ON u.id = re.referrer_user_id
            LEFT JOIN sprakapp_referral_rewards r ON u.id = r.user_id
            WHERE u.referral_code IS NOT NULL
            GROUP BY u.id, u.email, u.referral_code
            HAVING signups > 0 OR rewards_earned > 0
            ORDER BY signups DESC
        ');
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($stats) {
            echo "<table><tr><th>User</th><th>Referral Code</th><th>Signups</th><th>Completed</th><th>Rewards</th></tr>";
            foreach ($stats as $stat) {
                echo "<tr>
                    <td>" . htmlspecialchars($stat['email']) . "</td>
                    <td><code>" . htmlspecialchars($stat['referral_code']) . "</code></td>
                    <td>" . $stat['signups'] . "</td>
                    <td>" . $stat['completed'] . "</td>
                    <td>" . $stat['rewards_earned'] . "</td>
                </tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No referral activity yet</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>

    <h3>🔍 Quick Actions</h3>
    <ul>
        <li>If tables are missing: Run <code>backend/database/referral-schema.sql</code></li>
        <li>Check PHP error log for <code>[Referral]</code> messages</li>
        <li>Test flow: Visit <code>/ref/YOUR_CODE</code> → Register → Login</li>
        <li>Read: <code>backend/REFERRAL_TROUBLESHOOTING.md</code></li>
    </ul>

</body>
</html>
