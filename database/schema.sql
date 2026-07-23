CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'coordinator',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    pipeline_name TEXT NOT NULL DEFAULT '',
    parcel_name TEXT NOT NULL,
    parcel_code TEXT NOT NULL DEFAULT 'SOCI',
    parcel_code_suffix TEXT NOT NULL DEFAULT '',
    delivery_start TEXT NOT NULL,
    delivery_end TEXT NOT NULL,
    warehouse_name TEXT NOT NULL,
    warehouse_location TEXT NOT NULL,
    num_days INTEGER NOT NULL,
    work_start TEXT NOT NULL DEFAULT '09:00',
    work_end TEXT NOT NULL DEFAULT '15:00',
    per_window_capacity INTEGER NOT NULL DEFAULT 500,
    num_windows INTEGER NOT NULL DEFAULT 4,
    opening_quantity INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'draft',
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    generated_at TEXT,
    delivery_closed_at TEXT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS beneficiaries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    national_id TEXT NOT NULL,
    mobile TEXT NOT NULL,
    receipt_status TEXT NOT NULL DEFAULT 'قيد التسليم',
    disbursement_code TEXT,
    delivery_date TEXT,
    window_num INTEGER,
    time_from TEXT,
    time_to TEXT,
    message_text TEXT,
    day_index INTEGER,
    sort_order INTEGER,
    delivered_at TEXT,
    delivered_by INTEGER,
    delivery_type TEXT,
    actual_delivery_date TEXT,
    updated_at TEXT,
    delivery_batch_id INTEGER,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (delivered_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_beneficiaries_campaign ON beneficiaries(campaign_id);
CREATE INDEX IF NOT EXISTS idx_beneficiaries_day_window ON beneficiaries(campaign_id, day_index, window_num);
CREATE UNIQUE INDEX IF NOT EXISTS idx_beneficiaries_code ON beneficiaries(campaign_id, disbursement_code);
CREATE INDEX IF NOT EXISTS idx_beneficiaries_national_id ON beneficiaries(campaign_id, national_id);
CREATE INDEX IF NOT EXISTS idx_beneficiaries_status ON beneficiaries(campaign_id, receipt_status);
CREATE INDEX IF NOT EXISTS idx_beneficiaries_updated ON beneficiaries(campaign_id, updated_at);

CREATE TABLE IF NOT EXISTS mobile_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mobile_tokens_user ON mobile_tokens(user_id);

CREATE TABLE IF NOT EXISTS delivery_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    beneficiary_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    action TEXT NOT NULL DEFAULT 'delivered',
    delivery_type TEXT,
    delivered_at TEXT NOT NULL DEFAULT (datetime('now')),
    delivered_by INTEGER,
    client_id TEXT,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (delivered_by) REFERENCES users(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_delivery_events_client ON delivery_events(client_id) WHERE client_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS delivery_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    delivered_count INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    undone_at TEXT,
    undone_by INTEGER,
    undo_reason TEXT,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (undone_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_delivery_batches_campaign ON delivery_batches(campaign_id);

CREATE TABLE IF NOT EXISTS sms_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    beneficiary_id INTEGER NOT NULL,
    mobile TEXT NOT NULL,
    message_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sms_outbox_campaign ON sms_outbox(campaign_id, status);
