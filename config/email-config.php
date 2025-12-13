<?php
/**
 * Email Configuration
 * 
 * Configure email settings for the application.
 * For one.com hosting, the default mail() function should work.
 * If you need SMTP, uncomment and configure the SMTP settings below.
 */

return [
    // From email and name
    'from_email' => getenv('EMAIL_FROM') ?: 'noreply@d90.se',
    'from_name' => getenv('EMAIL_FROM_NAME') ?: 'SpråkApp',
    
    // Application URL (used in email links)
    'app_url' => getenv('APP_URL') ?: 'https://d90.se',
    'app_name' => 'SpråkApp',
    
    // Email method: 'mail' or 'smtp'
    'method' => getenv('EMAIL_METHOD') ?: 'mail',
    
    // SMTP Settings (only used if method is 'smtp')
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: 'smtp.one.com',
        'port' => getenv('SMTP_PORT') ?: 587,
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', // 'tls' or 'ssl'
        'username' => getenv('SMTP_USERNAME') ?: '',
        'password' => getenv('SMTP_PASSWORD') ?: '',
    ],
];
