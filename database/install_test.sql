-- DONDEBAILAR clean installation for an EMPTY MySQL 8.0+ database.
-- Import this file once through phpMyAdmin. Do not run against production or an existing database.
-- All user references use BIGINT UNSIGNED. User email remains unique.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_translations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    language CHAR(2) NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_translation_language (category_id, language),
    CONSTRAINT chk_category_translation_language CHECK (language IN ('nl', 'en', 'es', 'fr')),
    CONSTRAINT fk_category_translations_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: Application logic must enforce that an event's organizer, location and category
-- belong to the same region as the event. It must also authorize every read/write by region
-- and organizer membership. Cross-table region consistency cannot be guaranteed by these
-- simple foreign keys alone.


-- Regional role support and global organizers (migrations 003 and 004).
ALTER TABLE user_region_roles
    MODIFY COLUMN role ENUM('user','editor','region_admin') NOT NULL;

ALTER TABLE organizers
    DROP FOREIGN KEY fk_organizers_region,
    DROP INDEX uq_organizers_region_slug,
    DROP INDEX idx_organizers_region_active,
    DROP COLUMN region_id,
    DROP COLUMN slug,
    DROP COLUMN description,
    DROP COLUMN email,
    DROP COLUMN whatsapp,
    CHANGE COLUMN website_url website VARCHAR(500) NULL,
    ADD COLUMN notes TEXT NULL AFTER logo_path,
    ADD KEY idx_organizers_name (name),
    ADD KEY idx_organizers_active_name (is_active, name);

DROP TABLE IF EXISTS organizer_users;

-- Event media formats (migrations 005 and 006).
ALTER TABLE event_media
    MODIFY COLUMN media_type ENUM('image','video','reel','social_post') NOT NULL,
    MODIFY COLUMN file_path VARCHAR(700) NULL,
    ADD COLUMN media_source ENUM('upload','facebook','instagram') NOT NULL DEFAULT 'upload' AFTER media_type,
    ADD COLUMN external_url VARCHAR(1000) NULL AFTER media_source;

-- Current event fields added by the updater.
ALTER TABLE events
    MODIFY COLUMN organizer_id BIGINT UNSIGNED NULL,
    ADD COLUMN hashtags VARCHAR(500) NULL AFTER description,
    ADD COLUMN extra_info MEDIUMTEXT NULL AFTER description,
    ADD COLUMN recurrence_series_id BIGINT UNSIGNED NULL,
    ADD COLUMN recurrence_number INT UNSIGNED NULL,
    ADD KEY idx_events_recurrence_series (recurrence_series_id, recurrence_number);

CREATE TABLE event_recurrence_series (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    frequency ENUM('weekly','monthly') NOT NULL,
    ends_on DATE NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_event_recurrence_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
    ADD CONSTRAINT fk_events_recurrence_series
    FOREIGN KEY (recurrence_series_id) REFERENCES event_recurrence_series(id)
    ON DELETE SET NULL;

-- Extended regional appearance settings.
ALTER TABLE region_appearance
    ADD COLUMN menu_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN organizer_color CHAR(7) NOT NULL DEFAULT '#E23A2C',
    ADD COLUMN filter_hover_color CHAR(7) NOT NULL DEFAULT '#E23A2C',
    ADD COLUMN filter_bar_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN filter_text_color CHAR(7) NOT NULL DEFAULT '#222222',
    ADD COLUMN search_button_color CHAR(7) NOT NULL DEFAULT '#E23A2C',
    ADD COLUMN back_top_color CHAR(7) NOT NULL DEFAULT '#E23A2C',
    ADD COLUMN search_field_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN search_text_color CHAR(7) NOT NULL DEFAULT '#222222',
    ADD COLUMN footer_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN logo_path VARCHAR(500) NULL;

CREATE TABLE event_guest_attendances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_guest_attendance (event_id, visitor_hash),
    KEY idx_event_guest_attendance_event (event_id),
    CONSTRAINT fk_event_guest_attendance_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    message TEXT NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_event_messages_event (event_id, created_at),
    KEY idx_event_messages_parent (parent_id),
    KEY idx_event_messages_user (user_id),
    CONSTRAINT fk_event_messages_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(40) NOT NULL DEFAULT 'mention',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_notification_message (user_id, message_id),
    KEY idx_user_notifications_unread (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_agenda_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    region_id BIGINT UNSIGNED NULL,
    active_filter ENUM('organizer','city') NOT NULL DEFAULT 'organizer',
    organizer_id BIGINT UNSIGNED NULL,
    city VARCHAR(120) NULL,
    sound_muted TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_user_agenda_preferences_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_email_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_email_verification_hash (token_hash),
    KEY idx_user_email_verification_user (user_id),
    KEY idx_user_email_verification_expiry (expires_at),
    CONSTRAINT fk_user_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_email_created (email, created_at),
    KEY idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
