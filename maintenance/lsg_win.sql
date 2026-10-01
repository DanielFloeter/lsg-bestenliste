-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Erstellungszeit: 07. Aug 2026 um 17:27
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
-- Tabellenstruktur für Tabelle `lsg_win`
--

CREATE TABLE `lsg_win` (
  `id` int(10) UNSIGNED NOT NULL,
  `tstamp` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `date` int(10) UNSIGNED DEFAULT NULL,
  `town` varchar(30) NOT NULL DEFAULT '',
  `event` varchar(40) NOT NULL DEFAULT '',
  `distance` varchar(20) NOT NULL DEFAULT '',
  `athletes_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `time` varchar(15) NOT NULL DEFAULT '00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

--
-- Daten für Tabelle `lsg_win`
--

INSERT INTO `lsg_win` (`id`, `tstamp`, `date`, `town`, `event`, `distance`, `athletes_id`, `time`) VALUES
(8, 1459693930, 1459634400, 'Freiburg', '13. Freiburg Marathon', 'Marathon', 241, '03:02:05'),
(9, 1460818483, 1460239200, 'Stutensee-Blankenl.', '11. Stutenseer Stadtlauf', '5 km', 297, '00:18:13'),
(10, 1462202386, 1462053600, 'Hoppstädten-Weiersbach', '9. Bärenfelslauf am 1. Mai', '48 km', 301, '04:03:15'),
(11, 1462467633, 1462399200, 'Wössingen', '26. Wössinger Himmelfahrtslauf', '5 km', 322, '00:20:27'),
(12, 1462467682, 1462399200, 'Wössingen', '26. Wössinger Himmelfahrtslauf', '10 km', 333, '00:33:46'),
(13, 1465148083, 1465077600, 'Eggenstein-Leopoldshafen', 'Eggensteiner Jubiläumsfestlauf', '12,5 km', 22, '00:52:34'),
(14, 1465148174, 1465077600, 'Eggenstein-Leopoldshafen', 'Eggensteiner Jubiläumsfestlauf', '6,25 km', 322, '00:26:49'),
(15, 1466404670, 1466287200, 'Leimersheim', '36. Leimersheimer Volkslauf', '5 km', 9, '00:17:51'),
(16, 1466404748, 1466114400, 'Ettlingen', '13. Volksbank Ettlingen Altstadtlauf', '10 km', 22, '00:40:44'),
(17, 1466408872, 1466287200, 'F-Molsheim', 'Marathon du Vignoble D\'Alsace', 'Marathon', 120, '03:18:21'),
(18, 1466408924, 1466200800, 'Spirkelbach', 'Brunnenlauf', '5 km', 328, '00:20:43'),
(19, 1468245365, 1468101600, 'Stutensee-Büchig', '31. Büchiger Volkslauf', '5,2 km', 328, '00:24:12'),
(20, 1468155877, 1468101600, 'Stutensee-Büchig', '31. Büchiger Volkslauf', '10 km', 324, '00:44:18'),
(21, 1469354056, 1469052000, 'Karlrsuhe', '2. Knielinger Rennerei', '5 km', 320, '00:21:25'),
(22, 1469429913, 1469311200, 'Hundseck/Bühlertal', '44. Hornisgrinde-Marathon', 'Marathon', 120, '03:19:46'),
(23, 1469962140, 1469916000, 'Weiher', '14. Weiherer Hardtseelauf', '10 km', 335, '00:36:50'),
(24, 1472995880, 1472853600, 'Waldbronn', '12. Kurparklauf', '5 km', 272, '00:19:12'),
(25, 1476085311, 1475964000, 'Karlsruhe', '31. PSD Bank Hardtwaldlauf', '10 km', 17, '00:43:54'),
(26, 1478445929, 1478300400, 'Ötigheim', '41. Ötigheimer Herbstlauf', '5 km', 9, '00:17:06'),
(28, 1479647650, 1479510000, 'Bönnigheim', 'Stromberglauf', '10 km', 333, '00:34:29'),
(30, 1480930262, 1480806000, 'Bad Schönborn', 'Nikolauslauf', '5 km', 297, '00:17:38'),
(31, 1489933458, 1489878000, 'KA-Rüppurr', '14. Rissnertlauf', '15 km', 120, '01:01:57'),
(32, 1490029184, 1489791600, 'Nürnberg', '21. Sri Chinmoy', '6-Stunden-Lauf', 339, '80,475 km'),
(33, 1491760068, 1491602400, 'Maximiliansau', '40. Rhein-Volkslauf', '5 km', 22, '00:20:14'),
(34, 1491760115, 1491602400, 'Maximiliansau', '40. Rhein-Volkslauf', '10 km', 120, '00:40:32'),
(35, 1491760159, 1491602400, 'Maximiliansau', '40. Rhein-Volkslauf', '10 km', 9, '00:35:56'),
(36, 1492366406, 1492207200, 'Hemsbach', '8. Hemsbacher Ostermarathon', 'Marathon', 120, '03:53:17'),
(37, 1495989264, 1495663200, 'Wössingen', '27. Wössinger Himmelfahrtslauf', '5 km', 9, '00:16:51'),
(38, 1495989311, 1495663200, 'Wössingen', '27. Wössinger Himmelfahrtslauf', '5 km', 120, '00:19:28'),
(39, 1495989367, 1495663200, 'Wössingen', '27. Wössinger Himmelfahrtslauf', '10 km', 333, '00:35:09'),
(40, 1497789882, 1497736800, 'Leimersheim', '37. Leimersheimer Polderlauf', 'Halbmarathon', 324, '01:35:31'),
(41, 1498377167, 1498255200, 'Karlsruhe', '39. Fidelitas Nachtlauf', '80 km', 120, '07:43:43'),
(42, 1499009105, 1498946400, 'Graben', '29. Asparaguslauf', '5 km', 322, '00:19:54'),
(43, 1499621209, 1499551200, 'Stutensee-Büchig', '32. Büchiger Volkslauf', '10 km', 322, '00:42:03'),
(44, 1500223207, 1500156000, 'Waldbronn', '2. Waldbronner Freibadlauf', '7,64 km', 214, '00:29:26'),
(45, 1500224205, 1500069600, 'Bretten', 'Night 52', '52 km', 120, '04:31:56'),
(47, 1500912221, 1500674400, 'Illingen', '6Std.-Lauf LLG Wustweiler', '66,71 km', 120, '06:00:00'),
(48, 1506264738, 1506204000, 'Sinsheim-Rohrbach', '19. Kraichgau-Lauf', '50km', 120, '04:11:11'),
(49, 1509889300, 1509750000, 'Ötigheim', '42. Ötigheimer Herbstlauf', '5km', 120, '00:19:42'),
(50, 1522529194, 1520895600, 'Münster', 'Volkslauf in Münster', '10 km', 333, '00:34:35'),
(51, 1522529306, 1522447200, 'Rheinzabern', '45. Rheinzaberner Osterlauf', 'Halbmarathon', 333, '01:15:02'),
(52, 1524031059, 1523656800, 'Partille/Schweden', 'Partille 6 Timmars 2018', '6 Std', 389, '70,159 km'),
(53, 1526157250, 1526076000, 'Mannheim', 'Dämmermarathon', 'Halbmarathon', 316, '01:27:51'),
(54, 1529251660, 1528495200, 'Würmersheim', '4. Würmersheimer Speckkälbleslauf', '5,1 km', 296, '00:17:58'),
(55, 1529320019, 1529186400, 'Leimersheim', '38. Leimersheimer Polderlauf', '10 km', 395, '00:41:24'),
(56, 1531680801, 1531519200, 'Bretten', '15. Sparkasse Kraichgau CityCup', '5 km', 333, '00:17:12'),
(57, 1531680909, 1531519200, 'Bretten', 'Night 52', '52 km', 61, '04:15:50'),
(58, 1532279303, 1532210400, 'Weiher', '16. Weiherer Hardtseelauf', '10 km', 372, '00:37:10'),
(59, 1532757999, 1532642400, 'Grünwettersbach', '21. Wettersbacher Funkturmlauf', '11,11 km', 26, '00:54:30'),
(60, 1535986130, 1535752800, 'Waldbronn', 'Waldbronner Kurparklauf', '5 km', 296, '00:19:48'),
(61, 1537098891, 1536962400, 'Wössingen', '3. Zementwerkslauf', '5 km', 69, '00:20:29'),
(62, 1537803994, 1537653600, 'Sinsheim-Rohrbach', '20. Kraichgaulauf', '50 km', 120, '04:09:19 h'),
(63, 1540812843, 1540677600, 'Stutensee-Friedrichstal', '1. Friedrichstaler Waldlauf', '5 km', 347, '00:23:36'),
(64, 1541944087, 1541890800, 'Langensteinbach', '30. Langensteinbacher Volkslauf', '5 km', 399, '00:20:27'),
(65, 1551646341, 1551567600, 'Hördt', '34.Int. Hördter Auwald-Lauf', '10 km', 322, '00:40:46'),
(66, 1552290972, 1552172400, 'Rastatt', '29. Rund um das Mercedes-Benz Werk', '10 km', 316, '00:38:49'),
(67, 1554053302, 1553900400, 'Grünheide', '100 km von Grünheide', '100 km', 389, '08:18:22'),
(68, 1560178417, 1560031200, 'Philippsburg', '29. Philippsburger Festungslauf', '5,7', 21, '00:27:32'),
(69, 1562500527, 1562450400, 'Graben', '31. Asparaguslauf', '5 km', 335, '00:17:17'),
(70, 1563121580, 1562968800, 'Bretten', 'Kraichgau CityCup', '5 km', 333, '00:17:16'),
(71, 1566732123, 1566597600, 'Bottrop', '4. BUF', '6 Stundenlauf', 176, '67,892 km'),
(72, 1566797679, 1566597600, 'Birkweiler', 'Hohenbergtrail', 'Halbmarathon', 26, '02:07:49'),
(73, 1568630717, 1567807200, 'Waldbronn', 'Kurparklauf', '5 km', 296, '00:19:20'),
(74, 1570267079, 1570053600, 'Östringen', '11. Östringer Fitnesslauf', '10 km', 372, '00:37:43'),
(75, 1573396684, 1573340400, 'Karlsbad-Langensteinbach', '31. Langensteinbacher Volkslauf', '5 km', 399, '00:19:53'),
(76, 1573396772, 1573254000, 'Ötigheim', '44. Ötigheimer Herbstlauf', '5 km', 120, '00:19:12'),
(79, 1573472910, 1572127200, 'Stutensee-Friedrichstal', '2. Friedrichstaler Waldlauf', '5 km', 296, '00:20:03'),
(80, 1573473711, 1572127200, 'Stutensee', '2. Stutensee-Cup', '3 x 10 km', 372, '01:53:25'),
(81, 1573473610, 1570917600, 'Karlsruhe', '1. Regio-Cup', 'div. Distanzen', 19, '1985 Pkt.'),
(83, 1577698073, 1577574000, 'Rastatt-Ried', 'Vorsilvesterlauf', 'Halbmarathon', 120, '01:28:14'),
(84, 1577698288, 1560636000, 'F - Molsheim', 'Marathon du Vignoble d\'Alsace', 'Marathon', 120, '03:23:24'),
(85, 1583079575, 1579820400, 'Speyer', '5. Flugplatzlaufserie', '6,7 km', 372, '00:24:20'),
(87, 1583079200, 1582844400, 'Speyer', '5. Flugplatzlaufserie', '6,7 km', 372, '00:24:46'),
(90, 1583081985, 1582930800, 'Marburg', '28. Marburger Lahntallauf', '50 km', 120, '04:04:54'),
(91, 1626617331, 1626559200, 'Bretten', 'Night 52', '52 km', 333, '03:33:57'),
(92, 1633248640, 1633212000, 'Rettert', 'Schinder-Trail Backyard Ultra', '187,796 km/28 Loops', 342, '27:34:59'),
(94, 1634547638, 1634421600, 'Karlsruhe', 'Hardtwaldlauf', '10km', 429, '00:35:13'),
(95, 1634547645, 1634421600, 'Karlsruhe', 'Hardtwaldlauf', '10km', 399, '00:41:36'),
(96, 1639335198, 1639263600, 'Ettlingen', 'Knecht-Ruprecht Nikolauf', '10km', 316, '00:43:21'),
(97, 1646212743, 1645225200, 'Heidelberg', 'Jokertrail', '52 km', 419, '05:32:00'),
(98, 1652851929, 1652565600, 'Bottrop', '6h Lauf', '71,618 km', 389, '06:00:00'),
(99, 1653682899, 1653516000, 'Wössingen', 'Himmelfahrtslauf', '10km', 333, '00:34:48'),
(100, 1687208053, 1686434400, 'Spöck', 'Spechaa-Lauf', '5 km', 429, '00:17:17'),
(101, 1687208186, 1687039200, 'Durlach', 'Turmberglauf, Berglauf', '1,8 km', 395, '00:07:47'),
(102, 1695045030, 1694728800, 'Kandel', 'Bienwald Backyard Ultra', '228 km', 429, '32:00:00'),
(103, 1779226292, 1713564000, 'Konstantinovy Lázne / CZE', '6-Tage-Lauf', '6d', 455, '767,834 km'),
(104, 1743359845, 1743289200, 'Blankenloch', 'Stutenseer Stadtlauf', '5 km', 424, '00:17:50'),
(105, 1745236200, 1743890400, 'Griesheim', 'Straßenlauf', '5 km', 395, '00:19:22'),
(107, 1754301228, 1750975200, 'Hohenwettersbach', 'Bergdorfmeile', '8,89 km', 395, '00:37:39'),
(108, 1752229332, 1752098400, 'Karlsruhe', 'RaumFabrikLauf 2025, 3-er Staffel', '90 Minuten', 333, '48 Runden'),
(109, 1752229371, 1752098400, 'Karlsruhe', 'RaumFabrikLauf 2025, 3-er Staffel', '90 Minuten', 460, '48 Runden'),
(110, 1752229422, 1752098400, 'Karlsruhe', 'RaumFabrikLauf 2025, 3-er Staffel', '90 Minuten', 429, '48 Runden'),
(111, 1754301169, 1754085600, 'Ettlingen', 'Ettlinger Halbmarathon', 'Halbmarathon', 429, '01:19:51'),
(112, 1757877545, 1757628000, 'Kandel', 'Bienwald Backyard Ultra', '328,57 km', 429, '44:21:00'),
(113, 1762703078, 1762470000, 'Ubstadt-Weiher', '24h-Jubiläumslauf HaWei24', '24h', 429, '241,621 km'),
(114, 1762716705, 1464213600, 'Westweg', 'Freundschaftswettkampf Westweg', 'Pforzheim nach Basel', 342, '35:18:07');

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `lsg_win`
--
ALTER TABLE `lsg_win`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `lsg_win`
--
ALTER TABLE `lsg_win`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
