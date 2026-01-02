<?php
/**
 * Email Configuration
 * 
 * Configure email settings for the application.
 * For nunames.se hosting, the default mail() function should work.
 * If you need SMTP, uncomment and configure the SMTP settings below.
 */

return [
    // From email and name
    'from_email' => getenv('EMAIL_FROM') ?: 'polyverb@polyverbo.com',
    'from_name' => getenv('EMAIL_FROM_NAME') ?: 'PolyVerbo',
    
    // Application URL (used in email links)
    'app_url' => getenv('APP_URL') ?: 'https://polyverbo.com',
    'app_name' => 'PolyVerbo',
    
    // Email method: 'mail' or 'smtp'
    'method' => getenv('EMAIL_METHOD') ?: 'smtp',
    
    // SMTP Settings (nunames.se / polyverbo.com)
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: 'mail.polyverbo.com',
        'port' => getenv('SMTP_PORT') ?: 465,
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'ssl', // Use SSL for port 465
        'username' => getenv('SMTP_USERNAME') ?: '_mainaccount@polyverbo.com',
        'password' => getenv('SMTP_PASSWORD') ?: 'Knutte4711', // Set via environment variable or here
    ],
];
