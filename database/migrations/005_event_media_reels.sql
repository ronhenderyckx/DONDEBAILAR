-- DONDEBAILAR — Migration 005
-- Distinguish short-form reels from regular event videos.
-- Run once after migration 004.

ALTER TABLE event_media
    MODIFY COLUMN media_type ENUM('image', 'video', 'reel') NOT NULL;
