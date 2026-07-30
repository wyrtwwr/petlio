-- Adds a separately stored delivery price for new orders.
-- Historical orders are intentionally not backfilled because their paid amount
-- may not have included delivery.

DELIMITER //

DROP PROCEDURE IF EXISTS petlio_add_delivery_price_if_missing//
CREATE PROCEDURE petlio_add_delivery_price_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'delivery_price'
    ) THEN
        ALTER TABLE orders
            ADD COLUMN delivery_price DECIMAL(10,2) DEFAULT NULL AFTER pickup_address;
    END IF;
END//

CALL petlio_add_delivery_price_if_missing()//
DROP PROCEDURE petlio_add_delivery_price_if_missing//

DELIMITER ;
