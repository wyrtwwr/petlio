-- Adds private storage metadata for the optional second pet photo.

DELIMITER //

DROP PROCEDURE IF EXISTS petlio_add_secondary_pet_photo_if_missing//
CREATE PROCEDURE petlio_add_secondary_pet_photo_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'pet_secondary_photo_path'
    ) THEN
        ALTER TABLE orders
            ADD COLUMN pet_secondary_photo_path VARCHAR(255) DEFAULT NULL AFTER pet_photo_path;
    END IF;
END//

CALL petlio_add_secondary_pet_photo_if_missing()//
DROP PROCEDURE petlio_add_secondary_pet_photo_if_missing//

DELIMITER ;
