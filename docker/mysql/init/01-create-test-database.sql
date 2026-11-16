-- Runs once, when the mysql volume is created. The test suite uses its own database,
-- so running the tests never touches the application's data.
CREATE DATABASE IF NOT EXISTS notifications_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON notifications_test.* TO 'app'@'%';
FLUSH PRIVILEGES;
