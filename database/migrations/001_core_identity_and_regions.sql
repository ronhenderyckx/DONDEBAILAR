-- DONDEBAILAR core schema — MySQL 8.0+
-- Run once against the empty DONDEBAILAR database.
-- Database credentials and production data must never be committed.

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    preferred_language CHAR(2) NOT NULL DEFAULT 'en',
    avatar_path VARCHAR(500) NULL,
    platform_role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    status ENUM('pending_email', 'active', 'suspended') NOT NULL DEFAULT 'pending_email',
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status),
    CONSTRAINT chk_users_language CHECK (preferred_language IN ('nl', 'en', 'es', 'fr'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS regions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    subdomain VARCHAR(100) NOT NULL,
    country_code CHAR(2) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Madrid',
    default_language CHAR(2) NOT NULL DEFAULT 'es',
    description TEXT NULL,
    welcome_text TEXT NULL,
    contact_email VARCHAR(190) NULL,
    website_url VARCHAR(500) NULL,
    logo_path VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_regions_slug (slug),
    UNIQUE KEY uq_regions_subdomain (subdomain),
    KEY idx_regions_active (is_active),
    CONSTRAINT chk_regions_language CHECK (default_language IN ('nl', 'en', 'es', 'fr'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Assignment is controlled exclusively by platform admins in application logic.
-- One user may administer several regions; each region may have several admins.
CREATE TABLE IF NOT EXISTS user_region_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    region_id BIGINT UNSIGNED NOT NULL,
    role ENUM('region_admin', 'editor') NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_region_role (user_id, region_id, role),
    KEY idx_region_roles_region (region_id, role),
    CONSTRAINT fk_user_region_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_region_roles_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_region_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
