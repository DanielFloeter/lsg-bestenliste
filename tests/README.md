# Tests

Zwei Lagen, wie in `plan.md`, Abschnitt 8 (Verifikation) festgelegt.

| Lage | Braucht | Prüft |
|---|---|---|
| `tests/unit/` | nur PHPUnit | Adapter, Namenssplitter, Zeit- und Distanz-Normalisierung, Feld-Mapping über `DataFields`, Datumserkennung, AK-Berechnung, URL-Erkennung, P2, P3, P4, Trichter, Zustände |
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
| `fixtures/runtix-3152-21-total.html` | Antwort von `/sts/10050/3152/21/total` | ❌ fehlt |
| `fixtures/runtix-10020-2026.html` | Veranstaltungsübersicht mit Datum je Lauf | ❌ fehlt |
| `fixtures/runtix-10021-3152.html` | Veranstaltungsseite, Datum im Ausschreibungstext | ❌ fehlt |

Die drei Runtix-Fixtures werden erst für **M4** gebraucht. Zu beschaffen mit

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
