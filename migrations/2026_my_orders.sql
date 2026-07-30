-- Adds passwordless "My orders" access without deleting historical orders.
-- Apply after migrations/2026_robokassa.sql.

DELIMITER //

DROP PROCEDURE IF EXISTS petlio_add_my_orders_column_if_missing//
CREATE PROCEDURE petlio_add_my_orders_column_if_missing(
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

CALL petlio_add_my_orders_column_if_missing('customer_email', 'customer_email VARCHAR(254) DEFAULT NULL AFTER customer_address')//
CALL petlio_add_my_orders_column_if_missing('public_number', 'public_number VARCHAR(32) DEFAULT NULL AFTER order_uid')//
CALL petlio_add_my_orders_column_if_missing('customer_email_sent_at', 'customer_email_sent_at DATETIME DEFAULT NULL AFTER email_sent_at')//

DROP PROCEDURE petlio_add_my_orders_column_if_missing//

DROP PROCEDURE IF EXISTS petlio_add_my_orders_index_if_missing//
CREATE PROCEDURE petlio_add_my_orders_index_if_missing(
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

CALL petlio_add_my_orders_index_if_missing('uq_orders_public_number', 'UNIQUE KEY uq_orders_public_number (public_number)')//
CALL petlio_add_my_orders_index_if_missing('idx_orders_customer_email_created', 'KEY idx_orders_customer_email_created (customer_email, created_at)')//

DROP PROCEDURE petlio_add_my_orders_index_if_missing//

DELIMITER ;

-- Normalize the pre-payment status used by earlier installations.
UPDATE orders
SET payment_status = 'pending_payment'
WHERE payment_status = 'pending';

-- Reuse email already present in historical raw payloads, but never guess it.
UPDATE orders
SET customer_email = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(raw_payload) THEN raw_payload ELSE NULL END,
    '$.customer.email'
))))
WHERE (customer_email IS NULL OR TRIM(customer_email) = '')
  AND raw_payload IS NOT NULL
  AND JSON_VALID(raw_payload)
  AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(raw_payload) THEN raw_payload ELSE NULL END,
      '$.customer.email'
  )) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(raw_payload) THEN raw_payload ELSE NULL END,
      '$.customer.email'
  )) LIKE '%@%';

UPDATE orders
SET customer_email = LOWER(TRIM(customer_email))
WHERE customer_email IS NOT NULL;

-- Existing orders receive a stable public number without exposing their numeric id alone.
UPDATE orders
SET public_number = CONCAT(
    'PET-',
    DATE_FORMAT(COALESCE(created_at, CURRENT_TIMESTAMP), '%Y%m%d'),
    '-',
    LPAD(id, 6, '0')
)
WHERE public_number IS NULL OR TRIM(public_number) = '';

CREATE TABLE IF NOT EXISTS magic_link_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  request_ip_hash CHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_magic_link_tokens_hash (token_hash),
  KEY idx_magic_link_tokens_email_created (email, created_at),
  KEY idx_magic_link_tokens_expires (expires_at)
);
