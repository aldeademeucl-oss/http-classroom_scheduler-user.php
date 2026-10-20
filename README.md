# PUCL Campus Space classroom and laboratory scheduler

A PHP, HTML, and CSS scheduling application backed by MySQL. It is designed to run under XAMPP and works with MySQL databases managed through MySQL Workbench 8.0.

## Features

- Classroom and computer-laboratory directory with capacity, equipment, and activation controls
- Daily reservation schedule and upcoming reservation dashboard
- Conflict detection for overlapping pending and approved bookings
- Availability search by date, time range, room type, and minimum capacity
- Administrator approval/cancellation workflow
- User registration and sign-in using an account role in the existing `admins` table (no separate user table)
- Monthly approved-booking and utilization reports
- PDO prepared statements, password hashing, administrator-only pages, and CSRF checks

## Install on XAMPP

1. Copy the `classroom_scheduler` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel. Apache uses port 80 unless your XAMPP configuration specifies otherwise.
3. In MySQL Workbench 8.0, connect to the local MySQL server and open/run `classroom_scheduler.sql`. The script creates the `classroom_scheduler` database and tables.
4. If your local MySQL account is not `root` with an empty password, update the defaults in `config.php` or set `SCHEDULER_DB_HOST`, `SCHEDULER_DB_NAME`, `SCHEDULER_DB_USER`, and `SCHEDULER_DB_PASSWORD` in the Apache/PHP environment.
5. Visit `http://localhost/classroom_scheduler/`. The first visit opens the one-time administrator setup; create a password of at least 12 characters.
6. Sign in as the administrator to add rooms and labs. Normal users can register and request spaces at `http://localhost/classroom_scheduler/user.php`; their requests appear as pending until an administrator approves them.

If this database already exists from an earlier version, run `user_accounts_migration.sql` once in MySQL Workbench before using the user portal. It adds an `account_type` column to the existing `admins` table; registered users and administrators share that account table, with separate role checks. No users table is needed. Do not run the migration a second time.

The setup page redirects after an administrator exists. Keep the database password out of public source control. This application uses your local XAMPP/MySQL server; MySQL Workbench is the database administration client, not the PHP runtime.

## Scheduling behavior

User reservations are submitted in `pending` status and linked to the requesting account through the existing `created_by` relationship. Users can view and cancel their own future requests, but cannot view other users' requests or approve bookings. Both pending and approved bookings block overlapping time ranges for the same room. The application checks overlaps when a reservation is submitted and again when it is approved. Time ranges use a start-inclusive/end-exclusive rule, so consecutive bookings (for example, 09:00–10:00 and 10:00–11:00) do not conflict. Cancelling a reservation releases the room.
