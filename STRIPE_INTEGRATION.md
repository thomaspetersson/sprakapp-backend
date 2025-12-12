# Stripe Integration

## Overview

The Sprak App now supports monthly subscription payments for courses using Stripe. Users can purchase access to restricted courses with a subscription that auto-renews every month.

## Features

- **Monthly Subscriptions**: Users pay a monthly fee for course access
- **Auto-Renewal**: Subscriptions automatically renew each month
- **Stripe Checkout**: Secure payment processing via Stripe
- **Webhook Handling**: Automatic course access management based on payment events
- **Admin Pricing**: Admins can set price and currency for each course

## Database Changes

### New Columns in `sprakapp_courses`

Run `backend/database/add-course-pricing.sql`:

```sql
ALTER TABLE sprakapp_courses
ADD COLUMN price_monthly DECIMAL(10,2) DEFAULT 99.00 AFTER description,
ADD COLUMN stripe_price_id VARCHAR(255) NULL AFTER price_monthly,
ADD COLUMN currency VARCHAR(3) DEFAULT 'SEK' AFTER stripe_price_id;
```

### New Columns in `sprakapp_user_course_access`

Run `backend/database/add-stripe-subscriptions.sql`:

```sql
ALTER TABLE sprakapp_user_course_access
ADD COLUMN stripe_subscription_id VARCHAR(255) NULL AFTER end_date,
ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER stripe_subscription_id,
ADD COLUMN subscription_status ENUM('active', 'cancelled', 'expired', 'none') DEFAULT 'none' AFTER stripe_customer_id;

CREATE INDEX idx_stripe_subscription ON sprakapp_user_course_access(stripe_subscription_id);
CREATE INDEX idx_subscription_status ON sprakapp_user_course_access(subscription_status);
```

## Configuration

### Stripe API Keys

Update `backend/config/stripe-config.php` with your Stripe API keys:

```php
return [
    'secret_key' => getenv('STRIPE_SECRET_KEY') ?: 'sk_test_YOUR_TEST_KEY',
    'publishable_key' => getenv('STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_YOUR_TEST_KEY',
    'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_YOUR_WEBHOOK_SECRET',
    'api_version' => '2023-10-16',
];
```

**Production Setup:**
- Set environment variables on one.com hosting panel
- Use production keys: `sk_live_...` and `pk_live_...`
- Generate webhook secret from Stripe dashboard

### Frontend URL

Set the `FRONTEND_URL` environment variable for redirect URLs:
- Development: `http://localhost:5173`
- Production: `https://d90.se/sprakapp`

## Stripe Dashboard Setup

### 1. Get API Keys

1. Go to [Stripe Dashboard](https://dashboard.stripe.com/)
2. Navigate to **Developers > API keys**
3. Copy the **Publishable key** and **Secret key**
4. For test mode, use keys starting with `pk_test_` and `sk_test_`

### 2. Configure Webhook

1. Go to **Developers > Webhooks**
2. Click **Add endpoint**
3. Enter webhook URL: `https://d90.se/sprakapp/api/stripe-webhook.php`
4. Select events to listen for:
   - `checkout.session.completed`
   - `customer.subscription.deleted`
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
5. Copy the **Webhook signing secret** (starts with `whsec_`)

## API Endpoints

### Create Checkout Session

**Endpoint:** `POST /stripe-checkout.php`

**Request:**
```json
{
  "course_id": "123"
}
```

**Response:**
```json
{
  "checkout_url": "https://checkout.stripe.com/...",
  "session_id": "cs_test_..."
}
```

**Flow:**
1. User clicks "Lås upp" on a restricted course
2. Frontend calls this endpoint
3. Backend creates Stripe checkout session
4. User is redirected to Stripe payment page

### Webhook Handler

**Endpoint:** `POST /stripe-webhook.php`

**Events Handled:**

1. **checkout.session.completed**: Payment successful
   - Grants course access to user
   - Sets `subscription_status = 'active'`
   - Sets `end_date` to 1 month from now
   - Stores `stripe_subscription_id` and `stripe_customer_id`

2. **customer.subscription.deleted**: Subscription cancelled
   - Updates `subscription_status = 'cancelled'`
   - Sets `end_date` to current date (immediate revocation)

3. **invoice.payment_succeeded**: Monthly payment successful
   - Extends `end_date` by 1 month
   - Updates `subscription_status = 'active'`

4. **invoice.payment_failed**: Monthly payment failed
   - Logs error (future: could suspend access)

## User Flow

### Purchasing a Course

1. **Browse Courses**: User sees all courses on `/courses` page
2. **Restricted Course**: Courses without access show:
   - Price (e.g., "99 SEK / månad")
   - "Lås upp" button (enabled)
3. **Click Purchase**: User clicks "Lås upp"
4. **Stripe Checkout**: Redirected to Stripe payment page
5. **Payment**: User enters card details
6. **Success**: Redirected back to `/courses?payment=success`
7. **Webhook**: Stripe sends webhook to grant access
8. **Access**: User can now view the course

### Managing Subscription

Users can manage their subscription (cancel, update payment method) via Stripe Customer Portal:
1. Login to Stripe
2. Navigate to billing portal
3. Cancel or modify subscription

When a subscription is cancelled:
- Webhook receives `customer.subscription.deleted` event
- Course access is immediately revoked
- User sees "Lås upp" button again

## Testing

### Test Mode

1. Use Stripe test keys (`pk_test_...` and `sk_test_...`)
2. Use test card numbers:
   - Success: `4242 4242 4242 4242`
   - Decline: `4000 0000 0000 0002`
   - Requires authentication: `4000 0025 0000 3155`
3. Use any future expiry date and any CVC

### Test Webhooks

Use Stripe CLI to test webhooks locally:

```bash
stripe listen --forward-to localhost:8000/api/stripe-webhook.php
stripe trigger checkout.session.completed
stripe trigger customer.subscription.deleted
```

## Admin Features

### Setting Course Prices

1. Go to **Admin Dashboard > Kurser**
2. Click **Redigera** on a course
3. Set **Månadspris** (e.g., 99)
4. Set **Valuta** (e.g., SEK)
5. Click **Spara**

### Viewing Subscriptions

Currently, subscription info is stored in `sprakapp_user_course_access`:
- `stripe_subscription_id`: Stripe subscription ID
- `stripe_customer_id`: Stripe customer ID  
- `subscription_status`: active, cancelled, expired, or none

Query active subscriptions:
```sql
SELECT u.email, c.title, uca.start_date, uca.end_date, uca.subscription_status
FROM sprakapp_user_course_access uca
JOIN sprakapp_users u ON uca.user_id = u.id
JOIN sprakapp_courses c ON uca.course_id = c.id
WHERE uca.subscription_status = 'active';
```

## Security

### Webhook Signature Verification

The webhook endpoint verifies that requests come from Stripe by:
1. Checking the `Stripe-Signature` header
2. Computing HMAC SHA256 of payload with webhook secret
3. Comparing computed signature with provided signature
4. Rejecting requests with invalid signatures

### HTTPS Required

Stripe webhooks require HTTPS in production. Make sure your server has SSL enabled.

## Troubleshooting

### Webhook Not Received

1. Check Stripe Dashboard > Developers > Webhooks > Events
2. Verify webhook URL is correct
3. Check server logs for PHP errors
4. Ensure webhook secret is correct

### Payment Succeeded but No Access

1. Check webhook event in Stripe Dashboard
2. Look for PHP errors in server logs
3. Verify course_id and user_id in webhook metadata
4. Check database for subscription record

### Subscription Not Cancelling

1. Verify webhook is configured for `customer.subscription.deleted`
2. Check webhook signature verification
3. Look for errors in webhook processing

## Future Enhancements

- [ ] Customer portal integration for self-service subscription management
- [ ] Email notifications on payment success/failure
- [ ] Grace period for failed payments before access revocation
- [ ] Annual subscription option with discount
- [ ] Coupon/promo code support
- [ ] Multiple pricing tiers (basic, premium, etc.)
- [ ] Trial period support (7 days free, then charge)
