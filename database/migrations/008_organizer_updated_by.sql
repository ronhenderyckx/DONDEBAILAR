-- DONDEBAILAR — Migration 008
-- Track which user created or last changed each organizer.
-- Existing rows remain valid and receive NULL audit values.

ALTER TABLE organizers
    ADD COLUMN created_by BIGINT UNSIGNED NULL,
    ADD COLUMN updated_by BIGINT UNSIGNED NULL,
    ADD KEY idx_organizers_created_by (created_by),
    ADD KEY idx_organizers_updated_by (updated_by);
