-- Voeg de regenannulering toe aan de bestaande evenementstatussen.
-- Bestaande waarden blijven behouden.
ALTER TABLE events
    MODIFY COLUMN status ENUM(
        'draft',
        'published',
        'unpublished',
        'cancelled',
        'cancelled_rain'
    ) NOT NULL DEFAULT 'draft';
