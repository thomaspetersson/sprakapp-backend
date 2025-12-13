# Email Configuration Guide

## Overview
The password reset system sends emails using PHP's `mail()` function by default, which should work on most shared hosting providers like one.com.

## Files
- `backend/config/email-config.php` - Email configuration settings
- `backend/api/password-reset.php` - Password reset with email sending

## Configuration

### Using PHP mail() (Default - Recommended for one.com)

The default configuration uses PHP's built-in `mail()` function:

```php
'method' => 'mail',
'from_email' => 'noreply@d90.se',
'from_name' => 'SpråkApp',
'app_url' => 'https://d90.se',
```

### Environment Variables (Optional)

You can override settings using environment variables:

```bash
EMAIL_FROM=noreply@d90.se
EMAIL_FROM_NAME=SpråkApp
APP_URL=https://d90.se
EMAIL_METHOD=mail
```

## Email Content

The password reset email includes:
- **Subject**: "Återställ ditt lösenord - SpråkApp"
- **HTML version**: Styled email with button and link
- **Plain text version**: For email clients without HTML support
- **Reset link**: Valid for 1 hour, one-time use
- **Security notice**: Warns users to ignore if they didn't request reset

## Testing

### Test Password Reset Flow

1. **Request reset:**
   ```
   POST /api/password-reset.php?action=request
   Body: {"email": "test@example.com"}
   ```

2. **Check email inbox** for reset link

3. **Use reset link:**
   ```
   https://d90.se/sprakapp/reset-password?token=XXXXX
   ```

4. **Set new password:**
   ```
   POST /api/password-reset.php?action=reset
   Body: {"token": "XXXXX", "password": "newpassword123"}
   ```

### Troubleshooting Email Delivery

If emails are not being sent:

1. **Check PHP mail() is enabled:**
   ```php
   <?php
   echo function_exists('mail') ? 'mail() enabled' : 'mail() disabled';
   ?>
   ```

2. **Check email logs:**
   - Look in your hosting control panel for email delivery logs
   - Check server error logs: `/var/log/mail.log` (if accessible)

3. **Test basic mail() function:**
   ```php
   <?php
   $result = mail('your@email.com', 'Test', 'This is a test email');
   echo $result ? 'Email sent' : 'Email failed';
   ?>
   ```

4. **Check spam folder** - automated emails often end up in spam

5. **Verify sender email domain:**
   - Use an email address from your domain (noreply@d90.se)
   - Avoid using gmail.com, yahoo.com, etc. as sender

## one.com Specific Notes

### Email Sending on one.com

one.com supports PHP `mail()` function, but:
- Emails must be sent from a valid email address on your domain
- SPF records should be configured for your domain
- DKIM signing helps prevent spam filtering

### Creating Email Account

1. Go to one.com control panel
2. Navigate to "Email" section
3. Create email account: `noreply@d90.se`
4. Update `email-config.php` with this address

### SPF Record

Add SPF record to your DNS to improve deliverability:
```
Type: TXT
Name: @
Value: v=spf1 include:spf.one.com ~all
```

## Advanced: SMTP Configuration (Optional)

If PHP mail() doesn't work, you can configure SMTP:

1. **Update email-config.php:**
   ```php
   'method' => 'smtp',
   'smtp' => [
       'host' => 'smtp.one.com',
       'port' => 587,
       'encryption' => 'tls',
       'username' => 'noreply@d90.se',
       'password' => 'your-email-password',
   ],
   ```

2. **Install PHPMailer** (requires Composer, not available on basic one.com):
   ```bash
   composer require phpmailer/phpmailer
   ```

3. **Update password-reset.php** to use PHPMailer for SMTP method

## Security Best Practices

1. **Rate limiting**: Consider adding rate limiting to prevent abuse
2. **Token expiration**: Tokens expire after 1 hour
3. **One-time use**: Tokens are deleted after successful reset
4. **No email confirmation**: Don't reveal if email exists in system
5. **Secure URLs**: Always use HTTPS for reset links
6. **Token length**: 64-character random tokens (bin2hex(random_bytes(32)))

## Email Template Customization

Edit the `sendPasswordResetEmail()` function in `password-reset.php` to customize:
- Email subject
- HTML template styling
- Text content
- Button colors (currently #4F46E5 - indigo)

## Monitoring

To monitor email sending:
- Check error logs for failed `mail()` calls
- Add custom logging in `sendPasswordResetEmail()` function
- Use email delivery monitoring services (e.g., SendGrid, Mailgun)
