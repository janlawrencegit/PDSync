-- PDSync tables; import once in phpMyAdmin (MySQL 5.7+ or MariaDB 10.2+).
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  google_sub VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  last_login DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pds (
  id CHAR(16) PRIMARY KEY,
  key_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NULL,
  form VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  data MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY user_updated (user_id, updated_at),
  KEY updated (updated_at),
  CONSTRAINT pds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
