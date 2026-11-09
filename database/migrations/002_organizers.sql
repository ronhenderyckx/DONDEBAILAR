-- DONDEBAILAR — Global organizers
-- An organizer is an organization, not a user and not region-bound.
-- Events in any region may reference the same organizer record.

CREATE TABLE IF NOT EXISTS organizers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    phone VARCHAR(50) NULL,
    website VARCHAR(500) NULL,
    logo_path VARCHAR(500) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_organizers_name (name),
    KEY idx_organizers_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
