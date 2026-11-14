CREATE TABLE app_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    can_manage_handover BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage_users BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_login_attempts (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inventory_number VARCHAR(100) NOT NULL UNIQUE,
    category VARCHAR(100) NOT NULL,
    model VARCHAR(150) NOT NULL,
    serial_number VARCHAR(150) NOT NULL DEFAULT '',
    location VARCHAR(150) NOT NULL,
    device_condition VARCHAR(100) NOT NULL,
    notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
