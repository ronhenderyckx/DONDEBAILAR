-- DONDEBAILAR — Migration 006
-- Allow public Facebook and Instagram posts/reels to be linked to events.
-- Run once after migration 005.

ALTER TABLE event_media
    MODIFY COLUMN media_type ENUM('image', 'video', 'reel', 'social_post') NOT NULL,
    MODIFY COLUMN file_path VARCHAR(700) NULL,
    ADD COLUMN media_source ENUM('upload', 'facebook', 'instagram') NOT NULL DEFAULT 'upload' AFTER media_type,
    ADD COLUMN external_url VARCHAR(1000) NULL AFTER media_source;
