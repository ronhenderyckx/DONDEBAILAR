ALTER TABLE events
    ADD COLUMN presale_price DECIMAL(8,2) NULL DEFAULT NULL AFTER hashtags,
    ADD COLUMN door_price DECIMAL(8,2) NULL DEFAULT NULL AFTER presale_price;
