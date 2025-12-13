<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/rate-limit.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'POST':
        if (isset($_GET['action']) && $_GET['action'] === 'request') {
            requestPasswordReset($db);
        } elseif (isset($_GET['action']) && $_GET['action'] === 'reset') {
            resetPassword($db);
        } elseif (isset($_GET['action']) && $_GET['action'] === 'change') {
            changePassword($db);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function requestPasswordReset($db) {
    // Rate limit password reset requests
    RateLimit::check(RateLimit::getIdentifier(), 'password-reset');
    
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email)) {
        sendError('Email is required', 400);
    }

    try {
        // Check if user exists
        $query = "SELECT id, email FROM sprakapp_users WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $data->email);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            // Don't reveal if email exists or not for security
            sendSuccess(['message' => 'If an account exists with that email, a reset link has been sent.']);
            return;
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Generate reset token (valid for 1 hour)
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store reset token
        $query = "INSERT INTO sprakapp_password_resets (user_id, token, expires_at) 
                  VALUES (:user_id, :token, :expires_at)
                  ON DUPLICATE KEY UPDATE token = :token, expires_at = :expires_at, created_at = NOW()";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user['id']);
        $stmt->bindParam(':token', $resetToken);
        $stmt->bindParam(':expires_at', $expiresAt);
        $stmt->execute();
        
        // Send password reset email
        $emailConfig = require __DIR__ . '/../config/email-config.php';
        $resetUrl = rtrim($emailConfig['app_url'], '/') . "/sprakapp/reset-password?token=" . $resetToken;
        
        $emailSent = sendPasswordResetEmail($user['email'], $resetUrl, $resetToken, $emailConfig);
        
        if (!$emailSent) {
            // Log error but don't reveal to user for security
            error_log("Failed to send password reset email to: " . $user['email']);
        }
        
        sendSuccess([
            'message' => 'If an account exists with that email, a reset link has been sent.'
        ]);
        
    } catch (Exception $e) {
        sendError('Password reset request failed: ' . $e->getMessage(), 500);
    }
}

function sendPasswordResetEmail($email, $resetUrl, $token, $emailConfig) {
    $appName = $emailConfig['app_name'];
    $fromEmail = $emailConfig['from_email'];
    $fromName = $emailConfig['from_name'];
    
    $subject = "Återställ ditt lösenord - {$appName}";
    
    // HTML email
    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f9f9f9; padding: 30px; }
            .button { display: inline-block; padding: 12px 24px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            .warning { color: #dc2626; font-weight: bold; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Återställ ditt lösenord</h1>
            </div>
            <div class='content'>
                <p>Hej,</p>
                <p>Vi har tagit emot en begäran om att återställa lösenordet för ditt {$appName}-konto.</p>
                <p>Klicka på knappen nedan för att skapa ett nytt lösenord:</p>
                <p style='text-align: center;'>
                    <a href='" . htmlspecialchars($resetUrl) . "' class='button'>Återställ lösenord</a>
                </p>
                <p>Eller kopiera och klistra in denna länk i din webbläsare:</p>
                <p style='word-break: break-all; background: #fff; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($resetUrl) . "</p>
                <p class='warning'>Denna länk är giltig i 1 timme och kan endast användas en gång.</p>
                <p>Om du inte begärde en lösenordsåterställning kan du ignorera detta meddelande.</p>
                <p>Med vänliga hälsningar,<br>{$appName}-teamet</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " {$appName}. Alla rättigheter förbehållna.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Plain text version
    $textMessage = "Återställ ditt lösenord\n\n";
    $textMessage .= "Hej,\n\n";
    $textMessage .= "Vi har tagit emot en begäran om att återställa lösenordet för ditt {$appName}-konto.\n\n";
    $textMessage .= "Klicka på länken nedan för att skapa ett nytt lösenord:\n";
    $textMessage .= $resetUrl . "\n\n";
    $textMessage .= "Denna länk är giltig i 1 timme och kan endast användas en gång.\n\n";
    $textMessage .= "Om du inte begärde en lösenordsåterställning kan du ignorera detta meddelande.\n\n";
    $textMessage .= "Med vänliga hälsningar,\n{$appName}-teamet";
    
    $boundary = "boundary-" . md5(uniqid());
    
    // Email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    
    $message = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $textMessage . "\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlMessage . "\r\n";
    $message .= "--{$boundary}--";
    
    return mail($email, $subject, $message, $headers);
        
    } catch (Exception $e) {
        sendError('Password reset request failed: ' . $e->getMessage(), 500);
    }
}

function resetPassword($db) {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->token) || !isset($data->password)) {
        sendError('Token and new password are required', 400);
    }

    if (strlen($data->password) < 6) {
        sendError('Password must be at least 6 characters', 400);
    }

    try {
        // Verify token and check expiration
        $query = "SELECT pr.user_id, pr.expires_at 
                  FROM sprakapp_password_resets pr 
                  WHERE pr.token = :token AND pr.expires_at > NOW()";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':token', $data->token);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            sendError('Invalid or expired reset token', 400);
        }
        
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update password
        $passwordHash = Auth::hashPassword($data->password);
        
        $query = "UPDATE sprakapp_users SET password_hash = :password_hash WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->bindParam(':id', $reset['user_id']);
        $stmt->execute();
        
        // Delete used token
        $query = "DELETE FROM sprakapp_password_resets WHERE user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $reset['user_id']);
        $stmt->execute();
        
        sendSuccess(['message' => 'Password reset successfully']);
        
    } catch (Exception $e) {
        sendError('Password reset failed: ' . $e->getMessage(), 500);
    }
}

function changePassword($db) {
    // Verify user is authenticated
    $decoded = SessionAuth::verifySession();
    
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->currentPassword) || !isset($data->newPassword)) {
        sendError('Current password and new password are required', 400);
    }

    if (strlen($data->newPassword) < 6) {
        sendError('New password must be at least 6 characters', 400);
    }

    try {
        // Verify current password
        $query = "SELECT password_hash FROM sprakapp_users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $decoded['user_id']);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!Auth::verifyPassword($data->currentPassword, $user['password_hash'])) {
            sendError('Current password is incorrect', 401);
        }
        
        // Update to new password
        $passwordHash = Auth::hashPassword($data->newPassword);
        
        $query = "UPDATE sprakapp_users SET password_hash = :password_hash WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->bindParam(':id', $decoded['user_id']);
        $stmt->execute();
        
        sendSuccess(['message' => 'Password changed successfully']);
        
    } catch (Exception $e) {
        sendError('Password change failed: ' . $e->getMessage(), 500);
    }
}
