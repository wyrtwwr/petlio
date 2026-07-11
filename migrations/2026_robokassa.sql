-- Safe, non-destructive migration for existing Petlio installations.
-- Compatible with MySQL/MariaDB versions that support stored procedures.

DELIMITER //

DROP PROCEDURE IF EXISTS petlio_add_column_if_missing//
CREATE PROCEDURE petlio_add_column_if_missing(
    IN column_name_value VARCHAR(64),
    IN column_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE orders ADD COLUMN ', column_definition_value);
        PREPARE statement FROM @ddl;
        EXECUTE statement;
        DEALLOCATE PREPARE statement;
    END IF;
END//

CALL petlio_add_column_if_missing('payment_provider', 'payment_provider VARCHAR(32) DEFAULT NULL AFTER payment_id')//
CALL petlio_add_column_if_missing('robokassa_inv_id', 'robokassa_inv_id BIGINT UNSIGNED DEFAULT NULL AFTER payment_provider')//
CALL petlio_add_column_if_missing('email_sent_at', 'email_sent_at DATETIME DEFAULT NULL AFTER email_sent')//
CALL petlio_add_column_if_missing('updated_at', 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER paid_at')//

DROP PROCEDURE petlio_add_column_if_missing//

DROP PROCEDURE IF EXISTS petlio_add_index_if_missing//
CREATE PROCEDURE petlio_add_index_if_missing(
    IN index_name_value VARCHAR(64),
    IN index_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE orders ADD ', index_definition_value);
        PREPARE statement FROM @ddl;
        EXECUTE statement;
        DEALLOCATE PREPARE statement;
    END IF;
END//

CALL petlio_add_index_if_missing('uq_orders_robokassa_inv_id', 'UNIQUE KEY uq_orders_robokassa_inv_id (robokassa_inv_id)')//
CALL petlio_add_index_if_missing('idx_orders_payment_provider_status', 'KEY idx_orders_payment_provider_status (payment_provider, payment_status)')//

DROP PROCEDURE petlio_add_index_if_missing//

DELIMITER ;

-- Preserve historical provider references without changing their payment ids.
UPDATE orders
SET payment_provider = 'yookassa'
WHERE payment_provider IS NULL
  AND payment_id IS NOT NULL;
