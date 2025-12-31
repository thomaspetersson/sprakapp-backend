-- DIREKT FIX: Byt ENUM-kolumner helt
-- Denna metod fungerar garanterat

-- Steg 1: Lägg till temporära kolumner
ALTER TABLE sprakapp_referral_config 
ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_rewards 
ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_reward_tiers
ADD COLUMN reward_type_new ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

-- Steg 2: Kopiera data och konvertera
UPDATE sprakapp_referral_config 
SET reward_type_new = CASE 
    WHEN reward_type = 'free_month' THEN 'free_days'
    WHEN reward_type = 'credits' THEN 'credits'
    ELSE 'free_days'
END;

UPDATE sprakapp_referral_rewards 
SET reward_type_new = CASE 
    WHEN reward_type = 'free_month' THEN 'free_days' 
    WHEN reward_type = 'credits' THEN 'credits'
    ELSE 'free_days'
END;

UPDATE sprakapp_referral_reward_tiers
SET reward_type_new = CASE 
    WHEN reward_type = 'free_month' THEN 'free_days' 
    WHEN reward_type = 'credits' THEN 'credits'
    ELSE 'free_days'
END;

-- Steg 3: Ta bort gamla kolumner
ALTER TABLE sprakapp_referral_config DROP COLUMN reward_type;
ALTER TABLE sprakapp_referral_rewards DROP COLUMN reward_type;
ALTER TABLE sprakapp_referral_reward_tiers DROP COLUMN reward_type;

-- Steg 4: Byt namn på nya kolumner
ALTER TABLE sprakapp_referral_config 
CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_rewards 
CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_reward_tiers
CHANGE COLUMN reward_type_new reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

-- Kontrollera resultat
SELECT 'KLAR! Alla reward_type är nu free_days eller credits' as STATUS;
SELECT reward_type, COUNT(*) FROM sprakapp_referral_config GROUP BY reward_type;
SELECT reward_type, COUNT(*) FROM sprakapp_referral_rewards GROUP BY reward_type;
SELECT reward_type, COUNT(*) FROM sprakapp_referral_reward_tiers GROUP BY reward_type;