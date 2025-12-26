-- Subscription plans table
CREATE TABLE IF NOT EXISTS sprakapp_subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    num_courses INT NOT NULL,
    price_monthly DECIMAL(8,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    stripe_price_id VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- User subscriptions table
CREATE TABLE IF NOT EXISTS sprakapp_user_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    plan_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    stripe_subscription_id VARCHAR(255),
    status ENUM('active','cancelled','expired') DEFAULT 'active',
    slots_total INT NOT NULL,
    slots_used INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES sprakapp_subscription_plans(id)
);

-- User subscription course choices
CREATE TABLE IF NOT EXISTS sprakapp_user_subscription_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_subscription_id INT NOT NULL,
    course_id VARCHAR(64) NOT NULL,
    granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_subscription_id) REFERENCES sprakapp_user_subscriptions(id)
);
