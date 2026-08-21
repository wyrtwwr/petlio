CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_uid VARCHAR(64) NOT NULL UNIQUE,
  public_number VARCHAR(32) DEFAULT NULL,
  checkout_request_id VARCHAR(64) NOT NULL,
  payment_id VARCHAR(128) DEFAULT NULL,
  payment_provider VARCHAR(32) DEFAULT NULL,
  robokassa_inv_id BIGINT UNSIGNED DEFAULT NULL,
  payment_status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',

  size_key VARCHAR(32) NOT NULL,
  size_title VARCHAR(100) NOT NULL,
  size_value VARCHAR(100) NOT NULL,
  size_price VARCHAR(50) NOT NULL,

  pet_name VARCHAR(100) NOT NULL,
  pet_birthday VARCHAR(50) NOT NULL,
  pet_breed VARCHAR(100) NOT NULL,
  pet_address VARCHAR(255) NOT NULL,
  pet_phone VARCHAR(50) NOT NULL,
  pet_photo_path VARCHAR(255) NOT NULL,
  pet_secondary_photo_path VARCHAR(255) DEFAULT NULL,

  customer_name VARCHAR(150) NOT NULL,
  customer_address TEXT NOT NULL,
  customer_email VARCHAR(254) NOT NULL,

  delivery_type VARCHAR(50),
  delivery_service VARCHAR(100),
  pickup_address TEXT,
  delivery_price DECIMAL(10,2) DEFAULT NULL,

  amount DECIMAL(10,2) NOT NULL,
  email_sent TINYINT(1) NOT NULL DEFAULT 0,
  email_sent_at DATETIME DEFAULT NULL,
  customer_email_sent_at DATETIME DEFAULT NULL,

  raw_payload LONGTEXT DEFAULT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_orders_public_number (public_number),
  UNIQUE KEY uq_orders_checkout_request_id (checkout_request_id),
  UNIQUE KEY uq_orders_robokassa_inv_id (robokassa_inv_id),
  KEY idx_orders_payment_provider_status (payment_provider, payment_status),
  KEY idx_orders_customer_email_created (customer_email, created_at)
);

CREATE TABLE magic_link_tokens (
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
