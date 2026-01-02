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
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("[EmailHelper] Invalid recipient email: {$to}");
            return false;
        }
        
        // Use SMTP if configured
        if ($this->config['method'] === 'smtp') {
            return $this->sendViaSMTP($to, $subject, $htmlBody, $textBody);
        }
        
        // Fall back to mail()
        return $this->sendViaMail($to, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Send email via SMTP
     */
    private function sendViaSMTP($to, $subject, $htmlBody, $textBody = null) {
        $smtp = $this->config['smtp'];
        
        error_log("[EmailHelper] Using SMTP: {$smtp['host']}:{$smtp['port']} ({$smtp['encryption']})");
        
        try {
            // For SSL (port 465), use ssl:// prefix
            $host = $smtp['host'];
            if ($smtp['encryption'] === 'ssl' && $smtp['port'] == 465) {
                $host = "ssl://{$smtp['host']}";
            }
            
            // Connect to SMTP server
            $socket = fsockopen($host, $smtp['port'], $errno, $errstr, 30);
            if (!$socket) {
                error_log("[EmailHelper] SMTP connection failed: {$errstr} ({$errno})");
                return false;
            }
            
            // Read server greeting
            $response = fgets($socket);
            error_log("[EmailHelper] SMTP greeting: " . trim($response));
            
            // Send EHLO
            fputs($socket, "EHLO {$_SERVER['SERVER_NAME']}\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] EHLO response: " . trim(substr($response, 0, 100)));
            
            // Start TLS if using port 587 (only for TLS, not SSL)
            if ($smtp['port'] == 587 && $smtp['encryption'] === 'tls') {
                fputs($socket, "STARTTLS\r\n");
                $response = fgets($socket);
                if (strpos($response, '220') === false) {
                    error_log("[EmailHelper] STARTTLS failed: {$response}");
                    fclose($socket);
                    return false;
                }
                
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                
                // Send EHLO again after TLS
                fputs($socket, "EHLO {$_SERVER['SERVER_NAME']}\r\n");
                $response = $this->readSMTPResponse($socket);
            }
            
            // Authenticate
            fputs($socket, "AUTH LOGIN\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] AUTH LOGIN response: " . trim($response));
            
            fputs($socket, base64_encode($smtp['username']) . "\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] Username response: " . trim($response));
            
            fputs($socket, base64_encode($smtp['password']) . "\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] Password response: " . trim($response));
            
            // Read the actual auth result (next line after sending password)
            $authResult = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] Auth result: " . trim($authResult));
            
            if (strpos($authResult, '235') === false && strpos($authResult, '250') === false) {
                error_log("[EmailHelper] SMTP auth failed: {$authResult}");
                fclose($socket);
                return false;
            }
            
            error_log("[EmailHelper] SMTP authentication successful");
            
            // Send email
            fputs($socket, "MAIL FROM: <{$this->config['from_email']}>\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] MAIL FROM response: " . trim($response));
            
            fputs($socket, "RCPT TO: <{$to}>\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] RCPT TO response: " . trim($response));
            
            fputs($socket, "DATA\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] DATA response: " . trim($response));
            
            // Build message
            $boundary = "----=_NextPart_" . md5(time());
            $message = "From: {$this->config['from_name']} <{$this->config['from_email']}>\r\n";
            $message .= "To: {$to}\r\n";
            $message .= "Subject: {$subject}\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $message .= "\r\n";
            
            if ($textBody) {
                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $message .= $textBody . "\r\n\r\n";
            }
            
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $htmlBody . "\r\n\r\n";
            $message .= "--{$boundary}--\r\n";
            
            fputs($socket, $message);
            fputs($socket, "\r\n.\r\n");
            $response = $this->readSMTPResponse($socket);
            error_log("[EmailHelper] Final send response: " . trim($response));
            
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            if (strpos($response, '250') !== false) {
                error_log("[EmailHelper] SMTP email sent successfully to {$to}");
                return true;
            } else {
                error_log("[EmailHelper] SMTP send failed: {$response}");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("[EmailHelper] SMTP exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Read full SMTP response (multi-line)
     */
    private function readSMTPResponse($socket) {
        $response = '';
        while ($line = fgets($socket)) {
            $response .= $line;
            // Check if this is the last line (no hyphen after code)
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $response;
    }
    
    /**
     * Send email via PHP mail()
     */
    private function sendViaMail($to, $subject, $htmlBody, $textBody = null) {
        $headers = $this->buildHeaders($textBody !== null);
        
        // Use boundary for multipart if we have both HTML and text
        if ($textBody !== null) {
            $boundary = "----=_NextPart_" . md5(time());
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            
            $body = "This is a multi-part message in MIME format.\r\n\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textBody . "\r\n\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n\r\n";
            $body .= "--{$boundary}--";
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: 8bit\r\n";
            $body = $htmlBody;
        }
        
        // Log email attempt
        error_log("[EmailHelper] Sending via mail() to: {$to}");
        error_log("[EmailHelper] Subject: {$subject}");
        error_log("[EmailHelper] From: {$this->config['from_name']} <{$this->config['from_email']}>");
        
        // Additional parameters for sendmail - critical for nunames.se
        $additionalParams = "-f" . $this->config['from_email'];
        
        // Send using PHP mail() function with additional parameters
        $success = @mail($to, $subject, $body, $headers, $additionalParams);
        
        if (!$success) {
            error_log("[EmailHelper] mail() FAILED to send email to {$to}");
            error_log("[EmailHelper] Check server mail logs for details");
        } else {
            error_log("[EmailHelper] mail() returned success for {$to}");
        }
        
        return $success;
    }
    
    /**
     * Build email headers
     */
    private function buildHeaders($isMultipart = false) {
        $fromEmail = $this->config['from_email'];
        $fromName = $this->config['from_name'];
        
        // Critical headers for deliverability on shared hosting
        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "Return-Path: {$fromEmail}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Priority: 3\r\n";
        $headers .= "Message-ID: <" . time() . "-" . md5($fromEmail . time()) . "@" . $_SERVER['SERVER_NAME'] . ">\r\n";
        
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
