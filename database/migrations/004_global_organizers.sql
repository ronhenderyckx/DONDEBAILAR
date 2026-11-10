-- DONDEBAILAR — Migration 004
-- Convert organizers from region-bound to global organizations.
-- Events keep their organizer_id foreign key.

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
