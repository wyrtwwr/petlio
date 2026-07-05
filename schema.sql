CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_uid VARCHAR(64) NOT NULL UNIQUE,
  payment_id VARCHAR(128) DEFAULT NULL,
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

  customer_name VARCHAR(150) NOT NULL,
  customer_address TEXT NOT NULL,
  customer_phone VARCHAR(50) NOT NULL,

  delivery_type VARCHAR(50),
  delivery_service VARCHAR(100),
  pickup_address TEXT,

  amount DECIMAL(10,2) NOT NULL,
  email_sent TINYINT(1) NOT NULL DEFAULT 0,

  raw_payload LONGTEXT DEFAULT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL
);
