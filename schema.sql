CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_uid VARCHAR(64) NOT NULL UNIQUE,
  payment_id VARCHAR(128) DEFAULT NULL,
  payment_provider VARCHAR(32) DEFAULT NULL,
  robokassa_inv_id BIGINT UNSIGNED DEFAULT NULL,
  payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',

  size_key VARCHAR(32),
  size_title VARCHAR(100),
  size_value VARCHAR(100),
  size_price VARCHAR(50),

  pet_name VARCHAR(100),
  pet_birthday VARCHAR(50),
  pet_breed VARCHAR(100),
  pet_address VARCHAR(255),
  pet_phone VARCHAR(50),
  pet_photo_path VARCHAR(255) DEFAULT NULL,

  customer_name VARCHAR(150) NOT NULL,
  customer_address TEXT NOT NULL,
  customer_phone VARCHAR(50) NOT NULL,

  delivery_type VARCHAR(50),
  delivery_service VARCHAR(100),
  pickup_address TEXT,

  amount DECIMAL(10,2) NOT NULL,
  email_sent TINYINT(1) NOT NULL DEFAULT 0,
  email_sent_at DATETIME DEFAULT NULL,

  raw_payload LONGTEXT DEFAULT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_orders_robokassa_inv_id (robokassa_inv_id),
  KEY idx_orders_payment_provider_status (payment_provider, payment_status)
);
