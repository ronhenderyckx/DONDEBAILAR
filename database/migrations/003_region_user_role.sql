-- Allow ordinary users to be assigned to a region.
-- Existing regional assignments remain unchanged.

ALTER TABLE user_region_roles
MODIFY COLUMN role ENUM(
    'user',
    'editor',
    'region_admin'
) NOT NULL;
