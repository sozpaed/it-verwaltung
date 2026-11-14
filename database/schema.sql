CREATE TABLE app_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    can_manage_handover BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage_users BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage_inventory_settings BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_login_attempts (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('area', 'type', 'group', 'category') NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(12) NULL,
    type_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_inventory_settings_name (kind, name),
    UNIQUE KEY uq_inventory_settings_code (kind, code),
    CONSTRAINT fk_inventory_settings_type FOREIGN KEY (type_id) REFERENCES inventory_settings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO inventory_settings (kind, name, code) VALUES
    ('type', 'Personal Computer', 'PC'), ('type', 'Tablet', 'TB'),
    ('type', 'Beamer', 'BE'), ('type', 'Dokumentenkamera', 'DK'),
    ('type', 'Monitor', 'MO'), ('type', 'Tastatur', 'KB'),
    ('type', 'Maus', 'MA'), ('type', 'Fernseher', 'TV');

INSERT INTO inventory_settings (kind, name, type_id)
SELECT 'category', categories.name, types.id
FROM (
    SELECT 'iPad' AS name, 'TB' AS type_code UNION ALL SELECT 'Laptop', 'PC'
    UNION ALL SELECT 'Rechner', 'PC' UNION ALL SELECT 'iMac', 'PC'
    UNION ALL SELECT 'Dokumentenkamera', 'DK' UNION ALL SELECT 'Beamer', 'BE'
    UNION ALL SELECT 'Fernseher', 'TV' UNION ALL SELECT 'Monitor', 'MO'
    UNION ALL SELECT 'Tastatur', 'KB' UNION ALL SELECT 'Maus', 'MA'
) categories JOIN inventory_settings types ON types.kind = 'type' AND types.code = categories.type_code;

CREATE TABLE devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inventory_number VARCHAR(100) NOT NULL UNIQUE,
    designation VARCHAR(150) NOT NULL DEFAULT '',
    product_name VARCHAR(150) NOT NULL DEFAULT '',
    category VARCHAR(100) NOT NULL,
    inventory_area_id BIGINT UNSIGNED NULL,
    inventory_group_id BIGINT UNSIGNED NULL,
    inventory_category_id BIGINT UNSIGNED NULL,
    model VARCHAR(150) NOT NULL,
    serial_number VARCHAR(150) NOT NULL DEFAULT '',
    manufacturer VARCHAR(150) NOT NULL DEFAULT '',
    location VARCHAR(150) NOT NULL,
    purchase_date DATE NULL,
    purchase_price DECIMAL(12,2) NULL,
    graphics_card VARCHAR(150) NOT NULL DEFAULT '',
    processor VARCHAR(150) NOT NULL DEFAULT '',
    storage_capacity VARCHAR(150) NOT NULL DEFAULT '',
    ram VARCHAR(150) NOT NULL DEFAULT '',
    operating_system VARCHAR(150) NOT NULL DEFAULT '',
    device_condition VARCHAR(100) NOT NULL,
    notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_devices_area FOREIGN KEY (inventory_area_id) REFERENCES inventory_settings(id),
    CONSTRAINT fk_devices_group FOREIGN KEY (inventory_group_id) REFERENCES inventory_settings(id),
    CONSTRAINT fk_devices_category FOREIGN KEY (inventory_category_id) REFERENCES inventory_settings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE handovers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    recipient_id VARCHAR(100) NOT NULL,
    recipient_class VARCHAR(50) NOT NULL,
    kind ENUM('loan', 'permanent') NOT NULL,
    handed_over_on DATE NOT NULL,
    due_on DATE NULL,
    returned_on DATE NULL,
    return_condition VARCHAR(100) NULL,
    active_device_id BIGINT UNSIGNED GENERATED ALWAYS AS
        (CASE WHEN returned_on IS NULL THEN device_id ELSE NULL END) STORED,
    UNIQUE KEY uq_active_handover (active_device_id),
    INDEX ix_handovers_device (device_id, handed_over_on),
    CONSTRAINT fk_handovers_device FOREIGN KEY (device_id) REFERENCES devices(id),
    CONSTRAINT ck_handover_dates CHECK (
        (due_on IS NULL OR (kind = 'loan' AND due_on >= handed_over_on))
        AND (returned_on IS NULL OR returned_on >= handed_over_on)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
