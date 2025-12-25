# Stripe Configuration Setup

## Problem
Stripe API keys are not configured on the server. The error shows:
```
Invalid API Key provided: sk_test_***********_KEY
```

## Solution Options

### Option 1: Environment Variables (Recommended for Production)

Add these environment variables to your hosting provider:

```bash
STRIPE_SECRET_KEY=sk_test_YOUR_ACTUAL_SECRET_KEY
STRIPE_PUBLISHABLE_KEY=pk_test_YOUR_ACTUAL_PUBLISHABLE_KEY
STRIPE_WEBHOOK_SECRET=whsec_YOUR_ACTUAL_WEBHOOK_SECRET
```

**How to set on different hosts:**

- **cPanel**: PHP Selector → Options → Add environment variables
- **Plesk**: PHP Settings → Environment Variables
- **.htaccess** (if supported):
  ```apache
  SetEnv STRIPE_SECRET_KEY "sk_test_YOUR_ACTUAL_SECRET_KEY"
  SetEnv STRIPE_PUBLISHABLE_KEY "pk_test_YOUR_ACTUAL_PUBLISHABLE_KEY"
  SetEnv STRIPE_WEBHOOK_SECRET "whsec_YOUR_ACTUAL_WEBHOOK_SECRET"
  ```

### Option 2: Local Config File (Quick Fix)

Create `backend/config/stripe-config.local.php`:

```php
<?php
// Local Stripe configuration - DO NOT COMMIT TO GIT!
return [
    'secret_key' => 'sk_test_YOUR_ACTUAL_SECRET_KEY',
    'publishable_key' => 'pk_test_YOUR_ACTUAL_PUBLISHABLE_KEY',
    'webhook_secret' => 'whsec_YOUR_ACTUAL_WEBHOOK_SECRET',
    'api_version' => '2023-10-16',
];
```

Then update `backend/config/stripe-config.php`:

```php
<?php
// Check for local config first
$localConfig = __DIR__ . '/stripe-config.local.php';
if (file_exists($localConfig)) {
    return require $localConfig;
}

// Fallback to environment variables
return [
    'secret_key' => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_YOUR_SECRET_KEY',
    'publishable_key' => getenv('STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_YOUR_PUBLISHABLE_KEY',
    'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_YOUR_WEBHOOK_SECRET',
    'api_version' => '2023-10-16',
];
```

**Important:** Add `stripe-config.local.php` to `.gitignore` to keep keys secure!

## Where to Find Your Stripe Keys

1. Go to [Stripe Dashboard](https://dashboard.stripe.com/)
2. Navigate to **Developers** → **API keys**
3. Copy:
   - **Secret key** (starts with `sk_test_` or `sk_live_`)
   - **Publishable key** (starts with `pk_test_` or `pk_live_`)
4. For webhook secret:
   - Go to **Developers** → **Webhooks**
   - Click on your webhook endpoint
   - Reveal the **Signing secret** (starts with `whsec_`)

## Test Mode vs Live Mode

- **Test keys** (sk_test_*/pk_test_*): For development, no real charges
- **Live keys** (sk_live_*/pk_live_*): For production, real charges

Start with test keys, switch to live keys when ready for production.

## Verify Configuration

After setting up, test by visiting:
```
https://polyverbo.com/api/stripe-checkout.php
```

It should return a proper error (like "course_id required") instead of "Invalid API Key".
