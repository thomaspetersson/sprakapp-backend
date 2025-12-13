# Password Management Guide

## Features Implemented

### 1. Forgot Password
Users can request a password reset if they forget their login credentials.

**User Flow:**
1. Click "Forgot password?" link on login page
2. Enter email address
3. Receive reset link (in production, this would be sent via email)
4. Click reset link to set new password

**Pages:**
- `/forgot-password` - Request password reset
- `/reset-password?token=XXX` - Reset password with token

### 2. Change Password
Logged-in users can change their password from account settings.

**User Flow:**
1. Click Settings icon (gear) in navigation
2. Enter current password
3. Enter new password (min 6 characters)
4. Confirm new password
5. Submit to change password

**Page:**
- `/account` - Account settings with password change form

## Backend Endpoints

### Password Reset API (`backend/api/password-reset.php`)

**Request Password Reset**
```
POST /password-reset.php?action=request
Body: { "email": "user@example.com" }
```
- Generates reset token valid for 1 hour
- Stores token in `sprakapp_password_resets` table
- Returns success message (doesn't reveal if email exists)
- In DEV: Returns debug_reset_url for testing without email

**Reset Password**
```
POST /password-reset.php?action=reset
Body: { "token": "...", "password": "newpassword123" }
```
- Verifies token is valid and not expired
- Updates user password
- Deletes used token

**Change Password**
```
POST /password-reset.php?action=change
Body: { "currentPassword": "old", "newPassword": "new" }
```
- Requires authenticated session
- Verifies current password is correct
- Updates to new password

## Database Schema

### Password Resets Table
Created by: `backend/database/add-password-resets.sql`

```sql
CREATE TABLE sprakapp_password_resets (
    user_id VARCHAR(32) PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
)
```

**Migration Steps:**
1. Connect to database via one.com phpMyAdmin
2. Select `c31654db_sprakapp` database
3. Run the SQL from `add-password-resets.sql`

## Frontend Services

### Password Reset Service (`src/services/php-api.ts`)

**phpPasswordReset.requestReset(email)**
- Requests password reset link
- Returns message + debug URL (dev only)

**phpPasswordReset.resetPassword(token, password)**
- Resets password with token
- Returns success message

**phpPasswordReset.changePassword(currentPassword, newPassword)**
- Changes password for authenticated user
- Returns success message

## Email Configuration (Production)

**IMPORTANT:** The current implementation includes a `debug_reset_url` in the response for testing without email. This MUST be removed in production.

To enable email sending in production:
1. Configure SMTP settings in `backend/config/config.php` or environment variables
2. Create email sending function (example below)
3. Replace debug response with actual email sending

**Example Email Function:**
```php
function sendPasswordResetEmail($email, $token) {
    $resetUrl = getenv('APP_URL') . '/reset-password?token=' . $token;
    
    $subject = 'Password Reset Request';
    $message = "Click the following link to reset your password:\n\n$resetUrl\n\nThis link will expire in 1 hour.";
    
    // Using PHP mail() function
    $headers = 'From: noreply@d90.se' . "\r\n";
    mail($email, $subject, $message, $headers);
    
    // OR use a library like PHPMailer for better SMTP support
}
```

## Security Notes

1. **Token Expiration:** Reset tokens expire after 1 hour
2. **One-Time Use:** Tokens are deleted after successful password reset
3. **Email Privacy:** System doesn't reveal if an email exists in the database
4. **Password Requirements:** Minimum 6 characters (enforced in frontend and backend)
5. **Session-Based Auth:** Change password requires valid session

## Testing Without Email

For development/testing without email configuration:

1. Request password reset for an email
2. Check browser console for `debug_reset_url`
3. Copy the URL and paste in browser
4. Set new password

**Example console output:**
```
Reset URL (DEV ONLY): http://localhost:5173/sprakapp/reset-password?token=abc123...
```

## Deployment Checklist

- [ ] Run database migration (`add-password-resets.sql`)
- [ ] Upload `backend/api/password-reset.php`
- [ ] Upload updated `dist/` folder
- [ ] Configure email settings (production)
- [ ] Remove `debug_reset_url` from response (production)
- [ ] Set `APP_URL` environment variable (e.g., `https://d90.se`)
- [ ] Test forgot password flow
- [ ] Test change password flow

## Translations

All user-facing text supports Swedish and English:
- `forgotPassword` - "Glömt lösenord?" / "Forgot password?"
- `resetPassword` - "Återställ lösenord" / "Reset Password"
- `changePassword` - "Ändra lösenord" / "Change Password"
- `accountSettings` - "Kontoinställningar" / "Account Settings"

See `src/locales/en.json` and `src/locales/sv.json` for full list.
