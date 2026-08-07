-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Erstellungszeit: 07. Aug 2026 um 17:25
-- Server-Version: 10.11.14-MariaDB-0ubuntu0.24.04.1-log
-- PHP-Version: 7.4.33-nmm8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `d0475410`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `lsg_ak`
--

CREATE TABLE `lsg_ak` (
  `id` int(10) UNSIGNED NOT NULL,
  `tstamp` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `ak` varchar(8) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

--
-- Daten für Tabelle `lsg_ak`
--

INSERT INTO `lsg_ak` (`id`, `tstamp`, `ak`) VALUES
(3, 1448993836, 'whk'),
(4, 1448993844, 'w30'),
(5, 1448993851, 'w35'),
(6, 1448993891, 'w40'),
(7, 1448993901, 'w45'),
(8, 1448993908, 'w50'),
(9, 1448993916, 'w55'),
(10, 1448993923, 'w60'),
(11, 1448993931, 'w65'),
(12, 1448993938, 'w70'),
(13, 1448993965, 'mhk'),
(14, 1448993974, 'm30'),
(15, 1448993982, 'm35'),
(16, 1448993992, 'm40'),
(17, 1448993999, 'm45'),
(18, 1448994006, 'm50'),
(19, 1448994013, 'm55'),
(20, 1448994021, 'm60'),
(21, 1448994028, 'm65'),
(22, 1448994036, 'm70'),
(23, 1448994044, 'm75');

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `lsg_ak`
--
ALTER TABLE `lsg_ak`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `lsg_ak`
--
ALTER TABLE `lsg_ak`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
