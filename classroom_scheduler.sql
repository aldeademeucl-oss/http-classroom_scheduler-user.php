CREATE DATABASE IF NOT EXISTS classroom_scheduler
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE classroom_scheduler;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    account_type ENUM('admin', 'user') NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    building VARCHAR(120) NOT NULL,
    room_type ENUM('classroom', 'computer_lab') NOT NULL DEFAULT 'classroom',
    capacity SMALLINT UNSIGNED NOT NULL,
    equipment VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rooms_search (is_active, room_type, capacity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    organizer VARCHAR(120) NOT NULL,
    department VARCHAR(120) NOT NULL DEFAULT '',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('pending', 'approved', 'cancelled') NOT NULL DEFAULT 'pending',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reservations_room FOREIGN KEY (room_id)
        REFERENCES rooms (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservations_admin FOREIGN KEY (created_by)
        REFERENCES admins (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_reservations_schedule (room_id, status, starts_at, ends_at),
    INDEX idx_reservations_dates (starts_at, status)
) ENGINE=InnoDB;
