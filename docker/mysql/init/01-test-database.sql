-- Osobna baza dla testów. Testy czyszczą ją przy każdym uruchomieniu,
-- dlatego nigdy nie wskazują bazy deweloperskiej `udzio`.
CREATE DATABASE IF NOT EXISTS `udzio_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `udzio_test`.* TO 'udzio'@'%';
FLUSH PRIVILEGES;

-- Odczyt performance_schema (data_lock_waits, data_locks) w testach współbieżności:
-- potwierdzenie, że procesy robocze czekają na blokadę. Tylko środowisko lokalne.
GRANT SELECT ON performance_schema.* TO 'udzio'@'%';
FLUSH PRIVILEGES;
