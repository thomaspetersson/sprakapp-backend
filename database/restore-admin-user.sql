-- Återställ admin-användare
-- OBS: Ändra email och lösenord nedan till dina riktiga uppgifter!

-- Sätt in dina uppgifter här:
SET @admin_email = 'din@email.se';  -- ÄNDRA DETTA
SET @admin_password = 'ditt_lösenord';  -- ÄNDRA DETTA till ditt önskade lösenord

-- Generera password hash (kör detta i PHP eller använd bcrypt)
-- I PHP: password_hash('ditt_lösenord', PASSWORD_DEFAULT)

-- Alternativ 1: Om användaren fortfarande finns men inte är admin
UPDATE sprakapp_profiles 
SET role = 'admin' 
WHERE id = (SELECT id FROM sprakapp_users WHERE email = @admin_email);

-- Alternativ 2: Om användaren är helt borttagen, skapa ny admin
-- Generera ett UUID för userId (kan ersättas med ett eget)
SET @userId = UUID();

-- Skapa användare (byt ut hash nedan mot din riktiga password hash)
INSERT INTO sprakapp_users (id, email, password_hash, email_verified, referral_code, trial_expires_at) 
VALUES (
    @userId, 
    @admin_email, 
    '$2y$10$ExampleHashHerePleaseReplaceWithRealOne',  -- ÄNDRA DETTA till riktig hash
    1,  -- email_verified = TRUE
    CONCAT('ADMIN', LPAD(FLOOR(RAND() * 999999), 6, '0')),
    DATE_ADD(NOW(), INTERVAL 365 DAY)
);

-- Skapa profil som admin
INSERT INTO sprakapp_profiles (id, role, first_name, last_name) 
VALUES (@userId, 'admin', 'Admin', 'User');

-- Visa resultat
SELECT u.id, u.email, p.role, u.email_verified
FROM sprakapp_users u
LEFT JOIN sprakapp_profiles p ON u.id = p.id
WHERE u.email = @admin_email;
