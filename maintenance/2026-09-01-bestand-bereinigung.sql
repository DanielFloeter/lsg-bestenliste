-- ------------------------------------------------------------------
-- Bereinigung des Bestands in lsg_best  (erstellt 2026-09-01)
--
-- Grundlage: assets/lsg_best.sql, assets/lsg_athlete.sql, assets/lsg_ak.sql
-- Zweck:     Voraussetzung fuer den Ergebnisimport (plan.md, 6.5.4 / 7.3).
--            Die Pipeline setzt voraus, dass es je Athlet, Distanz und
--            Kalenderjahr genau eine Zeile gibt.
--
-- STAND 2026-09-01: von Hand ausgefuehrt. Offen sind nur noch
--   Abschnitt 1b  Dublettenpruefung wegen des nachgetragenen Datums (id 1649)
--   Abschnitt 3b  die 23 fuehrenden Nullen bei den Zeitlaeufen
-- Alles andere ist erledigt; die Gegenproben in Abschnitt 7 bleiben zur
-- Abnahme stehen.
--
-- VOR DEM AUSFUEHREN
--   1. Datenbank sichern.
--   2. Abschnitt 0 ausfuehren und die Zahlen mit den Kommentaren vergleichen.
--      Weichen sie ab, ist der Dump aelter als die Datenbank -- dann die
--      Liste neu erzeugen lassen, nicht die IDs von Hand anpassen.
--   3. Abschnitte einzeln ausfuehren, nicht die ganze Datei am Stueck.
--
-- Tabellenname ohne Praefix (LSG_BL_USE_WP_PREFIX = false). Bei aktivem
-- WordPress-Praefix ueberall lsg_best -> wp_lsg_best.
-- ------------------------------------------------------------------


-- ==================================================================
-- 0. Bestandsaufnahme (nur SELECT, aendert nichts)
-- ==================================================================

-- erwartet: 5951
SELECT COUNT(*) AS zeilen_gesamt FROM lsg_best;

-- erwartet: 26 Gruppen  (Abschnitt 1)
SELECT athletes_id, distance, YEAR(FROM_UNIXTIME(`date`)) AS jahr, COUNT(*) AS n
  FROM lsg_best
 GROUP BY athletes_id, distance, jahr
HAVING n > 1
 ORDER BY jahr, distance;

-- erwartet: 19 Zeilen  (Abschnitt 2)
SELECT id, distance, `time` FROM lsg_best
 WHERE distance NOT IN ('6h','12h','24h')
   AND `time` NOT REGEXP '^[0-9]{2}:[0-5][0-9]:[0-5][0-9]$';

-- erwartet: 3 Zeilen  (Abschnitt 3) -- plus 23 mit fuehrender Null (3b),
-- die dieses Muster passieren, weil es ein bis drei Vorkommastellen erlaubt
SELECT id, distance, `time` FROM lsg_best
 WHERE distance IN ('6h','12h','24h')
   AND `time` NOT REGEXP '^[0-9]{1,3},[0-9]{3} km$';


-- ==================================================================
-- 1. Doppelzeilen je Athlet / Distanz / Jahr  --  ERLEDIGT am 2026-09-01
--    (26 Zeilen entfallen; die Pruefung wird in 1b wiederholt)
--
-- Regel: die bessere Leistung bleibt stehen. Bei identischer Leistung
-- bleibt die Zeile mit der kanonischen Schreibweise bzw. der kleineren id.
-- Die geloeschte Leistung ist damit endgueltig weg -- lsg_best ist keine
-- Wettkampfhistorie (plan.md 6.8). Wer die zweiten Laeufe behalten will,
-- exportiert dieses Skript vorher als Liste.
-- ==================================================================

-- Schmidt, Erhard (1956) -- Marathon 2006
--   bleibt : id 3666  03:12:44     Kandel
--   entfaellt: id 3667  03:24:09     Karlsruhe

-- Schnurr, Marco (1977) -- Marathon 2007
--   bleibt : id 3755  03:00:25     Frankfurt
--   entfaellt: id 3754  03:19:16     Bühlertal

-- Bischoff, Natascha (1971) -- 15km 2011
--   bleibt : id 1999  01:01:18     Rheinzabern
--   entfaellt: id 1998  01:09:12     Rheinzabern

-- Krauß, Harald (1966) -- Marathon 2011
--   bleibt : id 3903  03:47:54     Kandel
--   entfaellt: id 3904  03:53:35     Bühlertal

-- Klotz, Berthold (1959) -- 10km 2012
--   bleibt : id 865   00:38:42     Rheinzabern
--   entfaellt: id 866   00:38:42     Rheinzabern

-- Anders, Peter (1947) -- Marathon 2012
--   bleibt : id 3914  04:14:18     Kandel
--   entfaellt: id 3915  04:16:41     Karlsruhe

-- Krüger, Michael (1966) -- 50km 2014
--   bleibt : id 4033  03:51:20     Rodgau
--   entfaellt: id 4032  3:51:20      Rodgau

-- Ziefle, Herbert (1968) -- 100km 2014
--   bleibt : id 4141  09:55:34     Leipzig
--   entfaellt: id 4142  09:55:34     Leipzig

-- Renz, Uwe (1957) -- HM 2019
--   bleibt : id 5629  01:32:29     Karlsruhe
--   entfaellt: id 5563  01:39:11     Ettlingen

-- Szulerski, Robin (1981) -- HM 2019
--   bleibt : id 5565  01:42:32     Ettlingen
--   entfaellt: id 5567  01:53:56     Ettlingen

-- Schlippe-Schrieber, Gudrun (1955) -- HM 2021
--   bleibt : id 5827  02:06:51     Kandel
--   entfaellt: id 5804  02:07:18     Karlsruhe

-- Seith, Marius (1989) -- Marathon 2022
--   bleibt : id 5980  02:45:49     Köln
--   entfaellt: id 5957  03:13:20     Karlsruhe

-- Creutzmann, Jürgen (1979) -- 10km 2023
--   bleibt : id 6092  00:49:40     Weingarten
--   entfaellt: id 6100  01:05:05     Wössinger

-- Hoeltz, Ulrike (1961) -- 10km 2023
--   bleibt : id 6137  00:53:14     St. Leon-Rot
--   entfaellt: id 6180  00:55:26     Rüppurr

-- Schmiederer, Bernd (1971) -- 15km 2024
--   bleibt : id 6276  01:06:50     Rüppurr
--   entfaellt: id 6214  01:07:47     Rheinzabern

-- Bräutigam, Janine (1985) -- HM 2024
--   bleibt : id 6271  01:39:02     Kandel
--   entfaellt: id 6385  02:00:50     Mallorca

-- Siebert, Fridtjof (1971) -- HM 2024
--   bleibt : id 6272  01:47:07     Kandel
--   entfaellt: id 6337  01:56:15     Ettlingen

-- Bohrer, Rolf (1960) -- HM 2024
--   bleibt : id 6312  01:45:26     Tromsö/Norwegen
--   entfaellt: id 6300  01:53:19     Schliersee

-- Deger, Manfred (1965) -- HM 2024
--   bleibt : id 6352  01:31:50     Karlsruhe
--   entfaellt: id 6331  01:38:26     Ettlingen

-- Spranck, Matthias (1968) -- HM 2024
--   bleibt : id 6351  01:31:27     Karlsruhe
--   entfaellt: id 6332  01:38:52     Ettlingen

-- Irnich, Norbert (1962) -- HM 2024
--   bleibt : id 6350  01:30:21     Karlsruhe
--   entfaellt: id 6334  01:39:03     Ettlingen

-- Harrer, Jürgen (1966) -- HM 2024
--   bleibt : id 6353  01:37:27     Karlsruhe
--   entfaellt: id 6336  01:56:13     Ettlingen

-- Siebert, Fridtjof (1971) -- HM 2025
--   bleibt : id 6445  01:50:18     Kandel
--   entfaellt: id 6543  02:06:08     Karlsruhe

-- Knabe, Andreas (1962) -- HM 2025
--   bleibt : id 6542  01:59:03     Karlsruhe
--   entfaellt: id 6449  02:13:08     Ettlingen

-- Rechlitz, Robert (1963) -- 15km 2026
--   bleibt : id 6653  01:08:06     Karlsruhe
--   entfaellt: id 6597  01:10:45     Rheinzabern

-- Wedlich, Oliver (1969) -- HM 2026
--   bleibt : id 6662  01:31:33     Bockenheim
--   entfaellt: id 6695  01:38:23     Ettlingen

DELETE FROM lsg_best WHERE id IN (
  866, 1998, 3667, 3754, 3904, 3915, 4032, 4142,
  5563, 5567, 5804, 5957, 6100, 6180, 6214, 6300,
  6331, 6332, 6334, 6336, 6337, 6385, 6449, 6543,
  6597, 6695
);
-- erwartet: 26 rows affected


-- ==================================================================
-- 1b. Dublettenpruefung wiederholen  --  wegen id 1649
--
-- Die Liste in Abschnitt 1 wurde erzeugt, als id 1649 noch date = 0 hatte
-- und damit ins Jahr 1970 fiel. Das Datum ist inzwischen nachgetragen
-- (Abschnitt 4) -- die Zeile kann jetzt eine 27. Doppelzeile sein.
--
-- Athlet 288 (Scholz, Steffen, 1970), 10 km, id 1649 = 00:38:57
-- Kraichtal-Oberacker. Vorhandene 10-km-Jahresbestzeiten:
--
--   Jahr  id     Leistung    Ort                    Folge
--   2013  1646   00:36:37    Rheinzabern            Bestand schneller -> 1649 entfaellt
--   2014  1647   00:37:42    Dillenburg             Bestand schneller -> 1649 entfaellt
--   2015  1648   00:40:56    Stutensee-Blankenl.    1649 schneller -> 1648 entfaellt
--   2016  4419   00:44:03    Stutensee-Buechig      1649 schneller -> 4419 entfaellt
--   2017  4745   00:39:44    Stutensee-Blankenloch  1649 schneller -> 4745 entfaellt
--
-- Jedes andere Jahr: keine Dublette, id 1649 bleibt stehen.
-- ==================================================================

-- Erst nachsehen, in welches Jahr id 1649 gefallen ist:
SELECT id, distance, `time`, town,
       FROM_UNIXTIME(`date`, '%Y-%m-%d') AS veranstaltung
  FROM lsg_best
 WHERE athletes_id = 288 AND distance = '10km'
 ORDER BY `date`;

-- Und die allgemeine Pruefung ueber den ganzen Bestand -- erwartet:
-- leere Ergebnismenge, sonst nennt sie die betroffene Gruppe:
SELECT athletes_id, distance, YEAR(FROM_UNIXTIME(`date`)) AS jahr,
       COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id)   AS ids,
       GROUP_CONCAT(`time` ORDER BY id)              AS leistungen
  FROM lsg_best
 GROUP BY athletes_id, distance, jahr
HAVING n > 1
 ORDER BY jahr, distance;

-- Findet sie eine Gruppe: die schlechtere Leistung nach der Regel aus
-- Abschnitt 1 loeschen (bessere bleibt), die id von Hand einsetzen.
-- DELETE FROM lsg_best WHERE id = ...;


-- ==================================================================
-- 2. Zeitschreibweisen vereinheitlichen auf HH:MM:SS  --  ERLEDIGT 2026-09-01
--    (18 UPDATEs; id 4032 war in Abschnitt 1 entfallen)
--
-- Historische Tippfehler: Punkt statt Doppelpunkt, fehlende Stunden-
-- angabe, fehlende fuehrende Null, ein angehaengter Punkt.
-- lsg_bl_parse_performance() faengt sie fuer die Sortierung bereits ab,
-- ausgegeben werden sie aber roh -- und der Import soll nach 7.2 keine
-- neuen dieser Art zulassen.
-- ==================================================================
UPDATE lsg_best SET `time` = '00:38:57' WHERE id = 1649 AND `time` = '38:57';  -- Scholz, Steffen (1970), 10km 0
UPDATE lsg_best SET `time` = '00:59:24' WHERE id = 2092 AND `time` = '59:24';  -- Schmidt, Lena (1986), 15km 2012
UPDATE lsg_best SET `time` = '01:33:38' WHERE id = 2506 AND `time` = '01:33.38';  -- Leppert, Valerie (1973), HM 2013
UPDATE lsg_best SET `time` = '01:23:36' WHERE id = 2626 AND `time` = '01:23.36';  -- Gericke, Uwe (1968), HM 2012
UPDATE lsg_best SET `time` = '01:33:42' WHERE id = 3069 AND `time` = '01:33.42';  -- Leppert-Saumer, Lothar (1957), HM 2013
UPDATE lsg_best SET `time` = '01:34:23' WHERE id = 3128 AND `time` = '01:34.23';  -- Völlinger, Michael (1984), HM 2013
UPDATE lsg_best SET `time` = '02:04:12' WHERE id = 3397 AND `time` = '02.04:12';  -- Zwecker, Michaela (1965), 25km 1996
UPDATE lsg_best SET `time` = '03:21:36' WHERE id = 3786 AND `time` = '03:21.36';  -- Siefert, Jutta (1969), Marathon 2013
UPDATE lsg_best SET `time` = '04:02:19' WHERE id = 3867 AND `time` = '4:02:19';  -- Möck, Wolfgang (1957), Marathon 2002
UPDATE lsg_best SET `time` = '03:28:27' WHERE id = 3919 AND `time` = '03:28.27';  -- Jobs, Udo (1964), Marathon 2013
UPDATE lsg_best SET `time` = '04:16:45' WHERE id = 3929 AND `time` = '04:16.45';  -- Schiff, Michaela (1988), Marathon 2013
UPDATE lsg_best SET `time` = '04:16:46' WHERE id = 3940 AND `time` = '04:16.46';  -- Makain, Michael (1982), Marathon 2013
-- id 4032  entfaellt bereits in Abschnitt 1 (3:51:20)
UPDATE lsg_best SET `time` = '09:52:20' WHERE id = 4106 AND `time` = '9:52:20';  -- Feikert, Wolfgang (1953), 100km 2003
UPDATE lsg_best SET `time` = '01:32:35' WHERE id = 4565 AND `time` = '01:32:35.';  -- Zöller, Michael (1969), HM 2014
UPDATE lsg_best SET `time` = '00:44:18' WHERE id = 5985 AND `time` = '00:44.18';  -- Gericke, Uwe (1968), 10km 2022
UPDATE lsg_best SET `time` = '01:20:24' WHERE id = 6408 AND `time` = '01:20.24';  -- Löffler, Philipp (1981), 20km 2025
UPDATE lsg_best SET `time` = '01:22:21' WHERE id = 6409 AND `time` = '01:22.21';  -- Wedlich, Oliver (1969), 20km 2025
UPDATE lsg_best SET `time` = '01:22:29' WHERE id = 6410 AND `time` = '01:22.29';  -- Hauptmann, Ralf (1979), 20km 2025


-- ==================================================================
-- 3. Streckenschreibweisen  --  ERLEDIGT am 2026-09-01  (3 Zeilen)
--
-- Regel (plan.md 7.2): Kilometerzahl mit drei Nachkommastellen, Komma als
-- Dezimaltrenner, Leerzeichen, 'km'  ->  '96,723 km', '112,737 km'.
--
-- KEINE fuehrende Null. Von 199 Zeitlauf-Zeilen entsprechen 173 dieser Form,
-- 23 tragen eine fuehrende Null -- die kommen in Abschnitt 3b weg.
--
-- Hier zunaechst drei echte Abweichungen: zwei fuehrende Leerzeichen und
-- eine Zeile mit zwei statt drei Nachkommastellen.
-- ==================================================================

UPDATE lsg_best SET `time` = '72,543 km' WHERE id = 4194 AND `time` = ' 72,543 km';  -- Kappes, Gerhard, 24h 2013
UPDATE lsg_best SET `time` = '84,448 km' WHERE id = 4242 AND `time` = ' 84,448 km';  -- Ziefle, Herbert, 24h 2013
UPDATE lsg_best SET `time` = '64,160 km' WHERE id = 6296 AND `time` = '64,16 km';    -- Henzler, Dominic, 6h 2023
-- erwartet: je 1 row affected


-- ------------------------------------------------------------------
-- 3b. Die 23 fuehrenden Nullen entfernen  --  entschieden 2026-09-01
--
-- Bei Zeitlaeufen gibt es keine fuehrenden Nullen (plan.md 7.2).
-- '096,723 km' wird zu '96,723 km'. Rueckwaerts ist es ausdruecklich
-- NICHT gewollt.
-- ------------------------------------------------------------------

-- Vorher ansehen -- erwartet: 23 Zeilen
SELECT id, distance, `time` FROM lsg_best
 WHERE distance IN ('6h','12h','24h')
   AND `time` REGEXP '^0[0-9]{2},[0-9]{3} km$'
 ORDER BY id;

UPDATE lsg_best
   SET `time` = TRIM(LEADING '0' FROM `time`)
 WHERE distance IN ('6h','12h','24h')
   AND `time` REGEXP '^0[0-9]{2},[0-9]{3} km$';
-- erwartet: 23 rows affected

-- Gegenprobe -- erwartet: leere Ergebnismenge
SELECT id, distance, `time` FROM lsg_best
 WHERE distance IN ('6h','12h','24h')
   AND `time` REGEXP '^0';
-- ------------------------------------------------------------------


-- ==================================================================
-- 4. Zeile ohne Veranstaltungsdatum  --  ERLEDIGT am 2026-09-01
--    (von Hand in der Datenbank nachgetragen)
--
-- id 1649  Scholz, Steffen (1970)  10km  '38:57'  Kraichtal-Oberacker
--          date = 0  ->  YEAR(FROM_UNIXTIME(0)) = 1970
--
-- Das Datum ist nachgetragen. Nur noch zur Gegenprobe -- erwartet: ein
-- date > 0, dessen Jahr zum Lauf passt:
--
SELECT id, distance, `time`, town,
       FROM_UNIXTIME(`date`, '%Y-%m-%d %H:%i') AS veranstaltung
  FROM lsg_best WHERE id = 1649;
--
-- Die Schreibweise der Zeit ('38:57' -> '00:38:57') erledigt Abschnitt 2,
-- falls sie noch nicht mitkorrigiert wurde.
-- ==================================================================


-- ==================================================================
-- 5. Leerzeile  --  ERLEDIGT am 2026-09-01
--
-- id 6556  distance = '', time = '00:00:00', ak = ''
-- Kein auswertbarer Inhalt, geloescht.
-- ==================================================================

-- Gegenprobe -- erwartet: leere Ergebnismenge
SELECT * FROM lsg_best WHERE id = 6556;


-- ==================================================================
-- 6. Fehlende Altersklassen in lsg_ak  --  ERLEDIGT am 2026-09-01
--
-- lsg_ak kennt mhk/whk, m30-m75 und w30-w70. In lsg_best stehen aber
-- bereits 32 Zeilen mit Codes, die dort fehlen:
--   m80  6 Zeilen      w75  8 Zeilen      w80 11 Zeilen
--   w85  6 Zeilen      w90  1 Zeile
--
-- Folge heute: lsg_bl_ak_list_for_gender() baut das AK-Dropdown aus
-- lsg_ak, diese Athleten sind ueber den Filter also nicht erreichbar.
-- Folge fuer den Import: die Pruefung "AK nicht in lsg_ak -> Warnung"
-- (plan.md 6.5.3 / 7.2) wuerde bei ihnen dauerhaft anschlagen, obwohl
-- die Codes richtig sind. lsg_ak ist unvollstaendig, nicht die Quelle
-- der Wahrheit.
-- ==================================================================

INSERT INTO lsg_ak (tstamp, ak) VALUES
  (UNIX_TIMESTAMP(), 'm80'),
  (UNIX_TIMESTAMP(), 'w75'),
  (UNIX_TIMESTAMP(), 'w80'),
  (UNIX_TIMESTAMP(), 'w85'),
  (UNIX_TIMESTAMP(), 'w90');

-- m85 und m90 fehlen ebenfalls, werden aber noch nicht gebraucht.
-- Sinnvoller waere, lsg_ak einmal bis m95/w95 durchzuschreiben, dann
-- laeuft die Tabelle dem Bestand nicht wieder hinterher.


-- ==================================================================
-- 6b. Laeufe am 1. Januar: 00:00 -> 12:00 Ortszeit  --  ERLEDIGT 2026-09-01
--
-- Der Bestand speichert `date` als 00:00 Ortszeit des Wettkampftags. Bei
-- einem Lauf am 1. Januar liegt der Timestamp damit im UTC-Vorjahr
-- (31.12., 23:00). YEAR(FROM_UNIXTIME(...)) rechnet mit der MySQL-Session-
-- Zeitzone -- steht die auf UTC, wird die Zeile dem Vorjahr zugeordnet.
--
-- Der Code stellt die Jahresabfragen auf eine Zeitspanne um und rechnet das
-- Jahr nicht mehr in SQL (plan.md 6.5.4). Diese sechs Zeilen kommen
-- zusaetzlich auf die Konvention, die 6.5.1 fuer neue Zeilen vorschreibt:
-- 12:00 Uhr Ortszeit. Dann stimmt der Tag in jeder Zeitzone.
--
-- Voraussetzung: die MySQL-Session steht auf der richtigen Zeitzone.
-- Einmal pruefen, sonst schiebt das UPDATE die Zeilen falsch:
-- ==================================================================

SELECT @@session.time_zone AS session_tz, @@global.time_zone AS global_tz,
       NOW() AS mysql_now;
-- Erwartet: eine Zeit, die zur Uhr des Servers passt. Steht dort '+00:00'
-- und der Server laeuft auf Ortszeit, vorher: SET time_zone = 'Europe/Berlin';

-- Kontrolle vor dem UPDATE -- erwartet: 6 Zeilen, jeweils 01.01. 00:00
SELECT id, athletes_id, distance, `time`, town,
       FROM_UNIXTIME(`date`, '%Y-%m-%d %H:%i') AS bisher
  FROM lsg_best
 WHERE id IN (1073, 1532, 1535, 3356, 3396, 3972)
 ORDER BY id;

UPDATE lsg_best
   SET `date` = `date` + 12 * 3600
 WHERE id IN (1073, 1532, 1535, 3356, 3396, 3972)
   AND DATE_FORMAT( FROM_UNIXTIME(`date`), '%m-%d %H:%i' ) = '01-01 00:00';
-- erwartet: 6 rows affected. Weniger heisst, dass eine Zeile nicht mehr auf
-- 01.01. 00:00 stand -- dann einzeln ansehen, nicht die Bedingung lockern.

-- Gegenprobe -- erwartet: 6 Zeilen, jeweils 01.01. 12:00
SELECT id, FROM_UNIXTIME(`date`, '%Y-%m-%d %H:%i') AS jetzt
  FROM lsg_best
 WHERE id IN (1073, 1532, 1535, 3356, 3396, 3972)
 ORDER BY id;

-- Hinweis: lsg_win ist nicht betroffen -- dort gibt es keinen Lauf am
-- 1. Januar (geprueft 2026-09-01). Die Abfrage lsg_bl_get_win_rows() wird
-- trotzdem mit umgestellt, damit die Falle nicht offen bleibt.


-- ==================================================================
-- 7. Gegenprobe nach der Bereinigung
-- ==================================================================

-- erwartet: 5924 -- bzw. 5923, falls Abschnitt 1b eine 27. Doppelzeile findet
SELECT COUNT(*) AS zeilen_gesamt FROM lsg_best;

-- erwartet: leere Ergebnismenge
SELECT athletes_id, distance, YEAR(FROM_UNIXTIME(`date`)) AS jahr, COUNT(*) AS n
  FROM lsg_best
 GROUP BY athletes_id, distance, jahr
HAVING n > 1;

-- erwartet: nur noch id 1649, solange Abschnitt 4 offen ist
SELECT id, distance, `time` FROM lsg_best
 WHERE distance NOT IN ('6h','12h','24h')
   AND `time` NOT REGEXP '^[0-9]{2}:[0-5][0-9]:[0-5][0-9]$';

-- erwartet: leere Ergebnismenge
SELECT id, distance, `time` FROM lsg_best
 WHERE distance IN ('6h','12h','24h')
   AND `time` NOT REGEXP '^[0-9]{1,3},[0-9]{3} km$';

-- erwartet: leere Ergebnismenge
SELECT DISTINCT b.ak FROM lsg_best b
  LEFT JOIN lsg_ak a ON a.ak = b.ak
 WHERE a.id IS NULL AND b.ak <> '';

-- erwartet: leere Ergebnismenge (Abschnitt 3b)
SELECT id, distance, `time` FROM lsg_best
 WHERE distance IN ('6h','12h','24h') AND `time` REGEXP '^0';

-- erwartet: leere Ergebnismenge (Abschnitt 5)
SELECT id FROM lsg_best WHERE distance = '';
