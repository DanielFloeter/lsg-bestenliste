# Tests

Zwei Lagen, wie in `plan.md`, Abschnitt 8 (Verifikation) festgelegt.

| Lage | Braucht | Prüft |
|---|---|---|
| `tests/unit/` | nur PHPUnit | Adapter, Namenssplitter, Zeit- und Distanz-Normalisierung, Feld-Mapping über `DataFields`, Datumserkennung, AK-Berechnung, URL-Erkennung, P2, P3, P4, Trichter, Zustände, Leistungsfeld und Jahresbestzeit-Prüfung (7.3), Regelkollisionen |
| `tests/integration/` | WordPress-Testsuite + MySQL | `dbDelta()`-Schema, P3/P4 gegen echte Tabellen, REST-Routen, Capability, SSRF-Allowlist |

## Unit-Lage laufen lassen

```bash
composer install          # nur require-dev; vendor/ wird nicht ausgeliefert
vendor/bin/phpunit --testsuite unit
```

Läuft in Sekunden, ohne Datenbank und ohne Netz. Möglich ist das, weil der
Abruf nicht im Parser steckt: die Adapter bekommen ihren HTTP-Getter im
Konstruktor übergeben, und die Parser sind statische Methoden, die einen
String entgegennehmen (`plan.md`, Abschnitt 5).

**PHPUnit ^9.6, nicht 10 oder 11.** Zwei Gründe, beide hart: der
Plugin-Header nennt `Requires PHP: 7.4`, und PHPUnit 10+ verlangt PHP 8.1.
Unabhängig davon unterstützt die WordPress-Testsuite PHPUnit 10+ nicht – sie
erwartet die `yoast/phpunit-polyfills`.

### Was in der Unit-Lage steht

| Datei | Prüft |
|---|---|
| `zeit-test.php` | die vier Zeitschreibweisen und ihre Fallstricke |
| `namen-test.php` | Namenssplitter (inkl. `GEIßLER`), Vereinsfilter, Normalisierung |
| `distanz-test.php` | Wettbewerbsname → Distanzcode, geschlossenes Select |
| `datum-ak-test.php` | Datumserkennung, Geschlecht aus dem Klassen-Code, AK-Berechnung |
| `raceresult-adapter-test.php` | Erkennung, `config`, Feld-Mapping über `DataFields`, 658 Zeilen |
| `pipeline-test.php` | P2, Trichter samt Phasen, Vorbelegung, Zustände, Fingerabdruck |
| `p3-p4-test.php` | Zuordnungsstufen, die drei Startregeln, Statusbildung, Doppelzeilen |
| `runtix-adapter-test.php` | URL-Zerlegung, Contest `w`, DOM-Parser nach Klasse, Umlaute, Datumsauflösung |
| `adapter-contract-test.php` | beide Adapter → identisches Zielschema, Allowlist-Form, `datum()`-Struktur |
| `leistung-test.php` | Leistungsfeld je Distanztyp, Streckenprüfung, Jahresbestzeit-Prüfung (7.3), Formularvalidierung, Regelkollisionen |

## Integrationslage

Noch nicht eingerichtet. Dafür fehlt `install-wp-tests.sh` aus dem
WordPress-Develop-Repository (dieselbe Datei, die `wp scaffold plugin-tests`
erzeugt).

⚠ **Was dort als Erstes hingehört**, weil es heute nur außerhalb des
Repositories geprüft ist:

- ein Import landet in `lsg_best`, zweimal ausgeführt ändert nichts
- die Übernahme schreibt `lsg_import_run` und `lsg_import_log` vollständig,
  auch die Nicht-Aktionen
- der von außen geänderte Bestand wird als `konflikt` gemeldet und nicht
  überschrieben
- eine Doppelzeile im Bestand: beste als Bezug, nur dorthin schreiben
- `dbDelta()` zweimal hintereinander erzeugt beim zweiten Mal kein
  `ALTER TABLE`
- REST-Routen ohne Login oder ohne Nonce → 401/403

Und aus **M5** (Abschnitt 7):

- das Formular legt eine Zeile in `lsg_best` an und schreibt genau einen
  Vorgang mit `adapter = 'manuell'`
- eine zweite Zeile für Athlet/Distanz/Jahr entsteht auf keinem Weg – auch
  nicht über das Bearbeiten
- Löschen protokolliert den vollständigen Datensatz, **bevor** die Zeile
  weg ist
- ein Speichern, das nichts ändert, erzeugt keine Log-Zeile
- die Listensortierung: Jahr absteigend, Distanz in der Reihenfolge von
  `lsg_bl_distance_map()`, Leistung – und bei den Zeitläufen die weiteste
  Strecke zuerst. ⚠ Das ist der Teil, der außerhalb von MySQL **nicht**
  geprüft ist: die Sortierung steckt in SQL (`CASE` plus
  `CAST(… AS DECIMAL(12,3))`), und die SQLite-Kulisse des Wegwerf-Harness
  rechnet dort anders
- `WP_List_Table` mit der echten Klasse statt mit dem Stub des Harness

⚠ Sie legt eine eigene Testdatenbank an und leert sie bei jedem Lauf –
**niemals auf die Datenbank der Installation zeigen**, in der die 6 000
Zeilen Vereinsgeschichte liegen.

```bash
bin/install-wp-tests.sh lsg_bl_tests <dbuser> <dbpass> localhost latest
LSG_BL_SUITE=integration WP_TESTS_DIR=/tmp/wordpress-tests-lib \
  vendor/bin/phpunit --testsuite integration
```

## Fixtures

| Datei | Inhalt | Stand |
|---|---|---|
| `fixtures/raceresult-375768-config.json` | Antwort von `/results/config`, Ettlingen 375768 | ✅ 2026-09-01 |
| `fixtures/raceresult-375768-contest2.json` | Antwort von `/results/list?r=all&l=0`, Hauptlauf 21,1km, **658 Datensätze** | ✅ 2026-09-01 |
| `fixtures/runtix-3152-21-total.html` | `/sts/10050/3152/21/total`, **22 ausgewählte Zeilen** von 234 | ⚠ nachgebaut, geprüft 2026-09-02 |
| `fixtures/runtix-10020-2026.html` | Veranstaltungsübersicht, **5 Zeilen** von 157 | ⚠ nachgebaut, geprüft 2026-09-02 |
| `fixtures/runtix-10021-3152.html` | Veranstaltungsseite mit dem Datum im Ausschreibungstext | ⚠ nachgebaut, geprüft 2026-09-02 |

### ⚠ Warum die Runtix-Fixtures nachgebaut sind

Die race-result-Fixtures sind Byte-Kopien der API-Antworten. Die drei
Runtix-Dateien sind es **nicht**: sie wurden aus live gelesenen DOM-Daten
neu geschrieben. Der Grund ist die Umgebung, in der sie entstanden – dort
war runtix.com nur über einen Browser erreichbar, und der gab rohes Markup
nicht heraus. Extrahiert wurden Klassennamen und Textinhalte; daraus ist
die Datei gebaut.

**Geprüft und übernommen** (2026-09-02, Event 3152 „19. Hambrücker
Lußhardtlauf"):

- die elf Spaltenklassen in ihrer Reihenfolge, einschließlich `col-time `
  **mit Leerzeichen am Ende**
- die Kopftexte `Pl., m/w, AK, Nr., Teilnehmer, Team / Verein, Jahrg.,
  Altersklasse, Nat., Zeit`
- alle Werte der 22 aufgenommenen Zeilen, Zeichen für Zeichen
- die Optionen von `select[name=contest]` samt `w` für den Walk und von
  `select[name=rlt]`
- der Aufbau von `div#competitions > div.row.competition` samt beider
  Link-Formen
- die Fußzeile `Copyright © CODERESEARCH 2001 - 2026`

**Nicht darin und deshalb ungeprüft:** Zeilenumbrüche und Einrückung des
Originals, `<script>`- und `<style>`-Blöcke, Tracking-Markup, sowie
Sonderzustände, die an dem Tag nicht auf der Seite standen (eine
DNF-Zeile in einer Runtix-Liste zum Beispiel).

⚠ Wer die Dateien einmal wirklich frisch ziehen kann, sollte es tun – und
dann prüfen, ob die Tests weiter durchlaufen:

```bash
curl -A 'LSG-Bestenliste/1.0 (+https://www.lsg-ka.de/)' \
  -o tests/fixtures/runtix-3152-21-total.html \
  'https://runtix.com/sts/10050/3152/21/total'      # ⚠ ohne trailing slash!
curl -A 'LSG-Bestenliste/1.0 (+https://www.lsg-ka.de/)' \
  -o tests/fixtures/runtix-10020-2026.html \
  'https://runtix.com/sts/10020/2026'
curl -A 'LSG-Bestenliste/1.0 (+https://www.lsg-ka.de/)' \
  -o tests/fixtures/runtix-10021-3152.html \
  'https://runtix.com/sts/10021/3152'
```

Danach zählen die Tests andere Zeilenzahlen – die Erwartungswerte 22 und
`gelesen === 22` in `runtix-adapter-test.php` sind dann anzuheben.

### Was in den Runtix-Fixtures steckt

Auch hier keine Beispieldaten, sondern die Fallstricke:

- **`col-ageclass` neben `col-place-ageclass`.** Zwei Spalten, deren Namen
  ineinander enthalten sind. Ein `contains(@class,'col-ageclass')` trifft
  beide und liest den AK-Platz als Klassencode – und findet dann kein
  Geschlecht mehr.
- **`class="col-time "`** mit Leerzeichen. Ein Vergleich auf Gleichheit
  des rohen Attributs findet die Spalte nicht, und dann gilt jede Zeile
  als „ohne verwertbare Zeit".
- **Contest `w`** für den Walk. Jedes `(int)`-Cast macht daraus 0.
- **`Michalewski,, Patrick`** – doppeltes Komma. Ohne Bereinigung heißt
  der Vorname `, Patrick` und passt auf keinen Athleten.
- **`LSG Weiher` (4) neben `LSG Karlsruhe` (1)**, dazu `Karlsruhe`,
  `Karlsruher Lemminge` und `Karlsruher Lemminge e.V.` – derselbe Beleg
  wie bei race result, in einer zweiten Quelle.
- **Platz 163 zweimal** – ein echter Zeitgleichstand. Wer nach Platz
  indexiert, verliert eine Zeile.
- **`GEIßLER`-Verwandtschaft:** `Geißler`, `KRÜGER`, `SEIDER, FRANK`,
  `weschenfelder, andreas`, `Nees, Dr. Corinna` – fünf Schreibweisen, an
  denen der Namenssplitter und die Zeichenkodierung zugleich hängen.
- **Alle Zeiten als `HH:MM:SS.t`** – der Zehntel-Rundungsfall, in dieser
  Quelle nicht die Ausnahme, sondern die Regel.
- **Kein Datum auf der Ergebnisseite.** Kein einziges `TT.MM.JJJJ` im
  ganzen Seitentext. Deshalb die Zweistufen-Auflösung, und deshalb die
  beiden anderen Fixtures.
- **Drei falsche Daten auf der Veranstaltungsseite:** Meldeschluss
  15.08., Lastschrifteinzug 19.08., Stand der Ausschreibung 12.03. – der
  Lauftag ist der 16.08.
- **58 von 157 Übersichtszeilen ohne Ergebnisse**, deren Name auf
  `/sts/10021/` zeigt statt auf `/sts/10050/`. Zeile 3190 in der Fixture
  ist so eine.

## Was außerhalb dieser Suite geprüft ist

⚠ Zwei Dinge laufen heute **nicht** in `tests/unit/`, weil sie WordPress
oder eine Datenbank brauchen – geprüft sind sie trotzdem, nur mit
Wegwerf-Skripten außerhalb des Repositories. Wer diese Suite für
vollständig hält, hält sie für mehr, als sie ist:

- **Die Admin-Seiten** (`page-import.php`, `page-log.php`, `page-best.php`,
  `page-map.php`) werden gegen WordPress-Stubs und eine SQLite-Kulisse
  gerendert – jeder Zustand einmal, plus die Schreibwege. Das fängt
  PHP-Fehler und fehlende Ausgaben, nicht die Eigenheiten von MySQL.
- **`WP_List_Table`** gibt es dort nur als Stub. Er ruft dieselben Methoden
  auf, die WordPress aufruft (`get_columns()`, `column_athlet()`,
  `column_default()`), damit der eigene Code wirklich läuft; das Markup,
  das WordPress selbst um die Zellen legt, ist damit nicht geprüft.

Beides gehört in die Integrationslage, sobald sie steht.

### Was in den race-result-Fixtures steckt

Nicht nur Beispieldaten – die Liste enthält drei Fälle, die genau die
Fallstricke aus dem Plan treffen:

- **`LSG Weiher` neben `LSG Karlsruhe`** (22 gegen 11 Zeilen). Der Beleg
  dafür, dass der Vereinsfilter `LSG` *und* `Karlsruhe` verlangen muss.
- **`(Karlsruhe)` als Vereinsfeld.** Bei fehlendem Verein setzt race result
  den Wohnort in Klammern – ein bloßes „Karlsruhe" darf nicht treffen.
- **`GEIßLER`, `STÖßER`** – GROSSGESCHRIEBENE Nachnamen mit ß. Ohne die
  ß→SS-Auflösung im Namenssplitter fallen sie in den Rateweg.
- Eine **DNS-Zeile** am Listenende, die verworfen und gezählt wird.
- **8 Labels gegen 9 Werte**: die zwei zusätzlichen führenden Felder
  (`BIB`, `ID`) vor dem Platz. Wer nach Spaltenposition rät, liest alles um
  zwei verschoben – und merkt es nicht, weil Platz und Startnummer beide
  Zahlen sind.

⚠ Die Fixtures gehören **nicht** ins Auslieferungs-ZIP: sie sind fremde
Ergebnislisten. Der Ausschluss steht in `.distignore`.
