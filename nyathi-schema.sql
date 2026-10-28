-- Select the HostAfrica database in phpMyAdmin before importing this file.
-- Use InnoDB for transactions and utf8mb4 for guest names and trip text.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','cto') NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trips (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  tag VARCHAR(100) NOT NULL DEFAULT '',
  summary VARCHAR(500) NOT NULL,
  duration VARCHAR(80) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  price_text VARCHAR(120) NOT NULL DEFAULT '',
  start_date DATE NULL,
  end_date DATE NULL,
  capacity SMALLINT UNSIGNED NULL,
  pickup VARCHAR(500) NOT NULL DEFAULT '',
  detail TEXT NULL,
  image_url VARCHAR(500) NOT NULL DEFAULT '',
  package_options JSON NULL,
  visibility ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX trips_public_dates (visibility,start_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_number VARCHAR(40) NOT NULL UNIQUE,
  trip_id VARCHAR(64) NOT NULL,
  package_label VARCHAR(180) NOT NULL DEFAULT '',
  requested_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  requested_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
  departure_date DATE NOT NULL,
  seats TINYINT UNSIGNED NOT NULL,
  client_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(190) NULL,
  pickup VARCHAR(180) NOT NULL DEFAULT '',
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  status ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
  accepted_price DECIMAL(10,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  CONSTRAINT booking_trip_fk FOREIGN KEY (trip_id) REFERENCES trips(id),
  INDEX booking_capacity (trip_id,departure_date,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(48) NOT NULL UNIQUE,
  booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
  total DECIMAL(10,2) NOT NULL,
  paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_required DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','part_paid','paid','void') NOT NULL DEFAULT 'unpaid',
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT invoice_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gallery_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  media_type ENUM('image','video') NOT NULL,
  media_url VARCHAR(500) NOT NULL,
  caption VARCHAR(500) NOT NULL DEFAULT '',
  position INT NOT NULL DEFAULT 0,
  visibility ENUM('published','hidden') NOT NULL DEFAULT 'published',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id VARCHAR(64) NULL,
  guest_name VARCHAR(90) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  body VARCHAR(1000) NOT NULL,
  status ENUM('pending','approved','removed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT review_trip_fk FOREIGN KEY (trip_id) REFERENCES trips(id),
  CONSTRAINT review_rating_check CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  provider_reference VARCHAR(190) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'ZAR',
  status ENUM('created','pending','paid','failed','refunded') NOT NULL DEFAULT 'created',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT payment_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  INDEX payment_provider_ref (provider,provider_reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reminder_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  reminder_type ENUM('booking_accepted','invoice_due','trip_soon') NOT NULL,
  scheduled_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  status ENUM('queued','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT reminder_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings(id),
  INDEX reminder_due (status,scheduled_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_key CHAR(64) NOT NULL PRIMARY KEY,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NOT NULL
) ENGINE=InnoDB;

INSERT INTO site_settings (setting_key,setting_value) VALUES
  ('social_links','{"facebook":"","instagram":""}')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
