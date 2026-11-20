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
  progress TINYINT UNSIGNED NULL,
  deleted_at DATETIME NULL,
  KEY user_updated (user_id, updated_at),
  KEY updated (updated_at),
  KEY deleted (deleted_at),
  CONSTRAINT pds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usage counts for the admin page (lib/stats.php); no personal data.
-- period is yyyy-mm-dd for a day or yyyy-mm for a month (unique counts only).
CREATE TABLE IF NOT EXISTS stats (
  period VARCHAR(10) NOT NULL,
  metric VARCHAR(32) NOT NULL,
  n INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (period, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Random salt for the current day and month; deleted afterwards so old hashes cannot be traced.
CREATE TABLE IF NOT EXISTS stats_salt (
  period VARCHAR(10) PRIMARY KEY,
  salt CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Salted hashes already counted this day or month, so each visitor or PDS counts once.
CREATE TABLE IF NOT EXISTS stats_seen (
  period VARCHAR(10) NOT NULL,
  metric VARCHAR(32) NOT NULL,
  h BINARY(32) NOT NULL,
  PRIMARY KEY (period, metric, h)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Earlier versions of each PDS, without images (lib/store.php keeps the last 15, at most one per 10 minutes).
CREATE TABLE IF NOT EXISTS pds_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pds_id CHAR(16) NOT NULL,
  saved_at DATETIME NOT NULL,
  data MEDIUMTEXT NOT NULL,
  KEY pds_saved (pds_id, saved_at),
  CONSTRAINT pds_versions_pds FOREIGN KEY (pds_id) REFERENCES pds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-address request counts for rate limits; k is a hash of action, address and time window.
CREATE TABLE IF NOT EXISTS rate_limit (
  k BINARY(32) PRIMARY KEY,
  n INT UNSIGNED NOT NULL,
  until DATETIME NOT NULL,
  KEY until (until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Consent to the privacy notice (Data Privacy Act): which notice version was agreed to and when, per PDS or account.
CREATE TABLE IF NOT EXISTS consents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pds_id CHAR(16) NULL,
  user_id INT UNSIGNED NULL,
  version VARCHAR(16) NOT NULL,
  agreed_at DATETIME NOT NULL,
  KEY consent_pds (pds_id),
  KEY consent_user (user_id),
  CONSTRAINT consents_pds FOREIGN KEY (pds_id) REFERENCES pds (id) ON DELETE CASCADE,
  CONSTRAINT consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What admins did and looked at, for accountability; kept 365 days.
CREATE TABLE IF NOT EXISTS admin_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at DATETIME NOT NULL,
  admin VARCHAR(255) NOT NULL,
  action VARCHAR(64) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  KEY admin_log_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
