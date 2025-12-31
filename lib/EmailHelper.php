<?php
/**
 * Email Helper
 * 
 * Functions for sending emails using configured email method.
 */

class EmailHelper {
    private $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/email-config.php';
    }
    
    /**
     * Send an email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML body content
     * @param string|null $textBody Plain text body content (optional)
     * @return bool True if sent successfully
     */
    public function send($to, $subject, $htmlBody, $textBody = null) {
        $headers = $this->buildHeaders($textBody !== null);
        
        // Use boundary for multipart if we have both HTML and text
        if ($textBody !== null) {
            $boundary = md5(time());
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $textBody . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $htmlBody . "\r\n";
            $body .= "--{$boundary}--";
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body = $htmlBody;
        }
        
        // Send using PHP mail() function
        $success = mail($to, $subject, $body, $headers);
        
        if (!$success) {
            error_log("Failed to send email to {$to}: " . error_get_last()['message'] ?? 'Unknown error');
        }
        
        return $success;
    }
    
    /**
     * Build email headers
     */
    private function buildHeaders($isMultipart = false) {
        $headers = "From: {$this->config['from_name']} <{$this->config['from_email']}>\r\n";
        $headers .= "Reply-To: {$this->config['from_email']}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        
        return $headers;
    }
    
    /**
     * Send verification email
     * 
     * @param string $to Recipient email
     * @param string $token Verification token
     * @return bool
     */
    public function sendVerificationEmail($to, $token) {
        $appUrl = $this->config['app_url'];
        $appName = $this->config['app_name'];
        $verifyUrl = "{$appUrl}/verify-email?token={$token}";
        
        $subject = "Bekräfta din e-postadress - {$appName}";
        
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2D9B91 0%, #38B968 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 14px 28px; background: #2D9B91; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 20px 0; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Välkommen till {$appName}!</h1>
        </div>
        <div class="content">
            <h2>Bekräfta din e-postadress</h2>
            <p>Tack för att du registrerade dig! För att slutföra din registrering och börja använda {$appName}, vänligen bekräfta din e-postadress genom att klicka på knappen nedan:</p>
            
            <div style="text-align: center;">
                <a href="{$verifyUrl}" class="button">Bekräfta e-postadress</a>
            </div>
            
            <p>Eller kopiera och klistra in denna länk i din webbläsare:</p>
            <p style="word-break: break-all; color: #2D9B91;">{$verifyUrl}</p>
            
            <div class="warning">
                <strong>OBS:</strong> Denna länk är giltig i 24 timmar och kan endast användas en gång.
            </div>
            
            <p>Om du inte registrerade dig för {$appName}, kan du ignorera detta e-postmeddelande.</p>
        </div>
        <div class="footer">
            <p>&copy; 2025 {$appName}. Alla rättigheter förbehållna.</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $textBody = <<<TEXT
Välkommen till {$appName}!

Bekräfta din e-postadress

Tack för att du registrerade dig! För att slutföra din registrering och börja använda {$appName}, vänligen bekräfta din e-postadress genom att besöka följande länk:

{$verifyUrl}

OBS: Denna länk är giltig i 24 timmar och kan endast användas en gång.

Om du inte registrerade dig för {$appName}, kan du ignorera detta e-postmeddelande.

© 2025 {$appName}. Alla rättigheter förbehållna.
TEXT;
        
        return $this->send($to, $subject, $htmlBody, $textBody);
    }
}
