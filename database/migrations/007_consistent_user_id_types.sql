-- DONDEBAILAR — Migration 007
-- Normalize references to users.id. The canonical type is BIGINT UNSIGNED.
-- Safe to run after migrations 001–006 and the feature-table upgrades.

ALTER TABLE event_messages
    MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN deleted_by BIGINT UNSIGNED NULL;

ALTER TABLE user_notifications
    MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN actor_user_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE event_recurrence_series
    MODIFY COLUMN created_by BIGINT UNSIGNED NULL;
