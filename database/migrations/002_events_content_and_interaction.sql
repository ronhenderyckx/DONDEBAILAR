-- DONDEBAILAR operational schema — MySQL 8.0+
-- Migration 002: organizers, locations, events, media, interaction and regional appearance.
-- Requires migration 001_core_identity_and_regions.sql.
-- Run once after migration 001. Do not run against a database containing unrelated tables.

CREATE TABLE IF NOT EXISTS region_appearance (
    region_id BIGINT UNSIGNED NOT NULL,
    primary_color CHAR(7) NOT NULL DEFAULT '#E23A2C',
    primary_dark CHAR(7) NOT NULL DEFAULT '#8F211B',
    accent_color CHAR(7) NOT NULL DEFAULT '#F6B22E',
    secondary_color CHAR(7) NOT NULL DEFAULT '#1FA898',
    tertiary_color CHAR(7) NOT NULL DEFAULT '#F4802A',
    header_color CHAR(7) NOT NULL DEFAULT '#222222',
    background_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    text_color CHAR(7) NOT NULL DEFAULT '#222222',
    weekday_colors JSON NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (region_id),
    CONSTRAINT fk_region_appearance_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    CONSTRAINT fk_region_appearance_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS region_social_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    region_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(40) NOT NULL,
    label VARCHAR(100) NULL,
    url VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_region_social_links_region (region_id, sort_order),
    CONSTRAINT fk_region_social_links_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    region_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    street VARCHAR(190) NULL,
    house_number VARCHAR(40) NULL,
    postal_code VARCHAR(30) NULL,
    city VARCHAR(120) NOT NULL,
    country_code CHAR(2) NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    google_place_id VARCHAR(190) NULL,
    google_maps_url VARCHAR(700) NULL,
    phone VARCHAR(50) NULL,
    website_url VARCHAR(500) NULL,
    logo_path VARCHAR(500) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_region_place (region_id, google_place_id),
    KEY idx_locations_region_city (region_id, city),
    KEY idx_locations_active (region_id, is_active),
    CONSTRAINT fk_locations_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_locations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS organizers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    region_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    logo_path VARCHAR(500) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    website_url VARCHAR(500) NULL,
    whatsapp VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organizers_region_slug (region_id, slug),
    KEY idx_organizers_region_active (region_id, is_active),
    CONSTRAINT fk_organizers_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS organizer_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organizer_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'manager', 'editor') NOT NULL DEFAULT 'editor',
    assigned_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organizer_user (organizer_id, user_id),
    KEY idx_organizer_users_user (user_id),
    CONSTRAINT fk_organizer_users_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE CASCADE,
    CONSTRAINT fk_organizer_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_organizer_users_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    region_id BIGINT UNSIGNED NULL,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_region_slug (region_id, slug),
    KEY idx_categories_active (region_id, is_active, sort_order),
    CONSTRAINT fk_categories_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    region_id BIGINT UNSIGNED NOT NULL,
    organizer_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    title VARCHAR(220) NOT NULL,
    description MEDIUMTEXT NULL,
    event_language CHAR(2) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    timezone VARCHAR(64) NOT NULL,
    status ENUM('draft', 'published', 'unpublished', 'cancelled') NOT NULL DEFAULT 'draft',
    cover_media_id BIGINT UNSIGNED NULL,
    retrospective_status ENUM('none', 'draft', 'published', 'archived') NOT NULL DEFAULT 'none',
    retrospective_published_at DATETIME NULL,
    retrospective_archived_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_region_feed (region_id, status, starts_at),
    KEY idx_events_organizer (organizer_id, status, starts_at),
    KEY idx_events_location (location_id, starts_at),
    KEY idx_events_retrospective (region_id, retrospective_status, retrospective_published_at),
    CONSTRAINT chk_events_language CHECK (event_language IN ('nl', 'en', 'es', 'fr')),
    CONSTRAINT chk_events_dates CHECK (ends_at IS NULL OR ends_at >= starts_at),
    CONSTRAINT fk_events_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_events_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_events_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS event_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    media_context ENUM('event', 'retrospective') NOT NULL DEFAULT 'event',
    media_type ENUM('image', 'video') NOT NULL,
    file_path VARCHAR(700) NOT NULL,
    thumbnail_path VARCHAR(700) NULL,
    alt_text VARCHAR(250) NULL,
    caption TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_cover TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_event_media_order (event_id, media_context, sort_order),
    CONSTRAINT fk_event_media_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_media_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE events
    ADD CONSTRAINT fk_events_cover_media
    FOREIGN KEY (cover_media_id) REFERENCES event_media(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS event_likes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_like_user (event_id, user_id),
    KEY idx_event_likes_user (user_id),
    CONSTRAINT fk_event_likes_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS event_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    parent_comment_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    status ENUM('visible', 'hidden', 'reported', 'deleted') NOT NULL DEFAULT 'visible',
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_event_comments_thread (event_id, parent_comment_id, created_at),
    KEY idx_event_comments_user (user_id),
    CONSTRAINT fk_event_comments_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_comments_parent FOREIGN KEY (parent_comment_id) REFERENCES event_comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_comments_moderator FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS event_attendances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('interested', 'attending', 'not_attending') NOT NULL DEFAULT 'interested',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_attendance_user (event_id, user_id),
    CONSTRAINT fk_event_attendances_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_attendances_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS email_action_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('verify_email', 'reset_password') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_action_token_hash (token_hash),
    KEY idx_email_action_user_purpose (user_id, purpose, expires_at),
    CONSTRAINT fk_email_action_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    notification_type VARCHAR(60) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body TEXT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    CONSTRAINT fk_notifications_comment FOREIGN KEY (comment_id) REFERENCES event_comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- NOTE: Application logic must enforce that an event's organizer, location and category
-- belong to the same region as the event. It must also authorize every read/write by region
-- and organizer membership. Cross-table region consistency cannot be guaranteed by these
-- simple foreign keys alone.
