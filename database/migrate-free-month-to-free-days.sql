-- Migrering: Uppdatera free_month till free_days
-- Kör denna fil separat för att fixa befintliga data

-- Visa nuvarande status
SELECT 'FÖRE MIGRERING:' as status;
SELECT reward_type, COUNT(*) as antal 
FROM sprakapp_referral_config 
GROUP BY reward_type;

SELECT reward_type, COUNT(*) as antal 
FROM sprakapp_referral_rewards 
GROUP BY reward_type;

-- Steg 1: Lägg till free_days som tillåtet värde (behåll free_month tillfälligt)
ALTER TABLE sprakapp_referral_config 
MODIFY COLUMN reward_type ENUM('free_month', 'free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_rewards 
MODIFY COLUMN reward_type ENUM('free_month', 'free_days', 'credits') NOT NULL;

-- Steg 2: Uppdatera alla poster från free_month till free_days
UPDATE sprakapp_referral_config 
SET reward_type = 'free_days' 
WHERE reward_type = 'free_month';

UPDATE sprakapp_referral_rewards 
SET reward_type = 'free_days' 
WHERE reward_type = 'free_month';

-- Steg 3: Ta bort free_month från enum
ALTER TABLE sprakapp_referral_config 
MODIFY COLUMN reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_rewards 
MODIFY COLUMN reward_type ENUM('free_days', 'credits') NOT NULL;

-- Visa resultat
SELECT 'EFTER MIGRERING:' as status;
SELECT reward_type, COUNT(*) as antal 
FROM sprakapp_referral_config 
GROUP BY reward_type;

SELECT reward_type, COUNT(*) as antal 
FROM sprakapp_referral_rewards 
GROUP BY reward_type;

SELECT 'MIGRERING KLAR!' as status;