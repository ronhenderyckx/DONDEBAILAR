-- DONDEBAILAR — Migration 008
-- Add the user responsible for the latest organizer change.
-- Existing organizers remain valid; their updated_by value is NULL.

ALTER TABLE organizers
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD KEY idx_organizers_updated_by (updated_by);
