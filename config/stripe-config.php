<?php
// Stripe configuration
// For production, use environment variables or a secure config file

return [
    'secret_key' => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_YOUR_SECRET_KEY',
    'publishable_key' => getenv('STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_YOUR_PUBLISHABLE_KEY',
    'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_YOUR_WEBHOOK_SECRET',
    'api_version' => '2023-10-16',
];
