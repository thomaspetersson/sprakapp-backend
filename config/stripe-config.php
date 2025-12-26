<?php
// Stripe configuration
// For production, create stripe-config.local.php with your actual keys (will be gitignored)
// Or use environment variables

// Check for local config in public_html/config (not committed to git)
$localConfig = dirname(__DIR__, 2) . '/config/stripe-config.local.php';
if (file_exists($localConfig)) {
    return require $localConfig;
}

// Fallback to environment variables or defaults
return [
    'secret_key' => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_YOUR_SECRET_KEY',
    'publishable_key' => getenv('STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_YOUR_PUBLISHABLE_KEY',
    'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_YOUR_WEBHOOK_SECRET',
    'api_version' => '2023-10-16',
];
