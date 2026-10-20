USE classroom_scheduler;

ALTER TABLE admins
    ADD COLUMN account_type ENUM('admin', 'user') NOT NULL DEFAULT 'admin';
