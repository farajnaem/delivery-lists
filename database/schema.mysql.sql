CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'coordinator',
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    parcel_name VARCHAR(255) NOT NULL,
    parcel_code VARCHAR(50) NOT NULL DEFAULT 'SOCI',
    parcel_code_suffix VARCHAR(50) NOT NULL DEFAULT '',
    delivery_start VARCHAR(20) NOT NULL,
    delivery_end VARCHAR(20) NOT NULL,
    warehouse_name VARCHAR(255) NOT NULL,
    warehouse_location VARCHAR(1000) NOT NULL,
    num_days INT NOT NULL,
    work_start VARCHAR(10) NOT NULL DEFAULT '09:00',
    work_end VARCHAR(10) NOT NULL DEFAULT '15:00',
    per_window_capacity INT NOT NULL DEFAULT 500,
    num_windows INT NOT NULL DEFAULT 4,
    opening_quantity INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_at DATETIME NULL,
    CONSTRAINT fk_campaigns_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS beneficiaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    national_id VARCHAR(50) NOT NULL,
    mobile VARCHAR(30) NOT NULL,
    receipt_status VARCHAR(30) NOT NULL DEFAULT 'قيد التسليم',
    disbursement_code VARCHAR(50) NULL,
    delivery_date VARCHAR(20) NULL,
    window_num INT NULL,
    time_from VARCHAR(10) NULL,
    time_to VARCHAR(10) NULL,
    message_text TEXT NULL,
    day_index INT NULL,
    sort_order INT NULL,
    delivered_at DATETIME NULL,
    delivered_by INT NULL,
    delivery_type VARCHAR(20) NULL,
    actual_delivery_date VARCHAR(20) NULL,
    INDEX idx_beneficiaries_campaign (campaign_id),
    INDEX idx_beneficiaries_day_window (campaign_id, day_index, window_num),
    INDEX idx_beneficiaries_code (campaign_id, disbursement_code),
    INDEX idx_beneficiaries_national_id (campaign_id, national_id),
    INDEX idx_beneficiaries_status (campaign_id, receipt_status),
    CONSTRAINT fk_beneficiaries_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_beneficiaries_deliverer FOREIGN KEY (delivered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id INT NOT NULL,
    campaign_id INT NOT NULL,
    action VARCHAR(20) NOT NULL DEFAULT 'delivered',
    delivery_type VARCHAR(20) NULL,
    delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_by INT NULL,
    client_id VARCHAR(64) NULL,
    UNIQUE KEY idx_delivery_events_client (client_id),
    CONSTRAINT fk_events_beneficiary FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_deliverer FOREIGN KEY (delivered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    beneficiary_id INT NOT NULL,
    mobile VARCHAR(30) NOT NULL,
    message_text TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sms_outbox_campaign (campaign_id, status),
    CONSTRAINT fk_sms_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_sms_beneficiary FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
