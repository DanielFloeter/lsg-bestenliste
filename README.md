# lsg-bestenliste

Wir erfassen Running-Ergebnisse von unseren Vereinsmitgliedern für eine
Auswertung und Archivierung auf unserer Internetseite.

Das Plugin arbeitet auf den vier gewachsenen Tabellen `lsg_ak`, `lsg_athlete`,
`lsg_best` und `lsg_win` – sie bleiben, wie sie sind. Dazu kommen drei eigene
für den Import: `lsg_athlete_map`, `lsg_import_run` und `lsg_import_log`.

Eine Regel hängt an allem Weiteren: `lsg_best` hält **Jahresbestleistungen**.
Eine Zeile ist die beste Leistung eines Athleten auf einer Distanz in einem
Kalenderjahr – nicht ein einzelnes Wettkampfergebnis.

## Ausgabe

Drei Gutenberg-Blöcke, jeder mit dem Filterformular, das die Seite bisher
schon hatte:

| Block | Zeigt |
|---|---|
| Bestenliste | Ein Jahr, gefiltert nach Geschlecht, Altersklasse und Distanz |
| Gesamtsiege | Die Gesamtsieger eines Jahres |
| Ewige Bestenliste | Alle Jahre, gefiltert nach Geschlecht, Altersklasse und Distanz |

Ein Filterwechsel holt über `lsg/v1` nur die Tabelle nach. Ohne JavaScript
schickt dasselbe Formular ein gewöhnliches GET ab und der Server rendert die
Seite neu – die Parameternamen sind auf beiden Wegen dieselben.

## Erfassung

Im Adminbereich unter „LSG Bestenliste", vier Seiten:

- **Ergebnis-Import** – Adresse einer Ergebnisliste einwerfen, Wettbewerb und
  Liste wählen, Vorschau ansehen, übernehmen.
- **Import-Log** – was wann von wo übernommen wurde, samt der Zeit, die dabei
  überschrieben wurde.
- **Zuordnungen** – welcher Name in der Quelle zu welchem Sportler gehört.
- **Bestenliste** – Ergebnisse von Hand erfassen, ändern, löschen. Für alles,
  was in keiner Onlineliste steht.

Angeschlossen sind zwei Quellen: **my.raceresult.com** und **runtix.com**. Die
Adresse wird nicht geraten, sondern erkannt; welcher Wettbewerb und welche
Liste gemeint sind, fragt die Seite nach und schlägt vor, was sie aus der
Quelle lesen kann – Distanz, Datum, Ort.

Der Import liest die ganze Liste, behält die Vereinsmitglieder, ordnet sie
über Name und Jahrgang einem Sportler zu und legt jede Zeile mit ihrem Status
zur Wahl vor. Was dabei wegfällt, steht als Trichter über der Tabelle:

> 658 gelesen, 1 ohne Zeit → 11 LSG → 10 zugeordnet, 1 ohne Zuordnung →
> 0 neu, 1 schneller, 6 langsamer, 3 gleich

Vorgehakt ist nur, was den Bestand verbessert – `neu` und `schneller`. Alles
andere ist sichtbar, aber abgewählt. Geschrieben wird erst auf Knopfdruck.

Zwölf Distanzen: 5 bis 25 km, Halbmarathon, Marathon, 50 und 100 km – und die
drei Zeitläufe über 6, 12 und 24 Stunden, bei denen nicht die Zeit, sondern
die zurückgelegte Strecke die Leistung ist.

Jeder Schritt läuft ohne Seitenaufbau. Ist JavaScript aus, tut dieselbe Seite
dasselbe über gewöhnliche Formulare.

## Installation

WordPress ab 6.1, PHP ab 7.4. Ordner nach `wp-content/plugins/`, aktivieren.
Tabellen, die noch nicht da sind, legt die Aktivierung an; vorhandene Tabellen
und ihre Daten rührt sie nicht an.

Zwei Schalter für die `wp-config.php`, beide freiwillig:

```php
// Tabellen mit dem WordPress-Präfix ansprechen statt blank als lsg_*.
define( 'LSG_BL_USE_WP_PREFIX', true );

// Wer importieren und von Hand erfassen darf.
// Voreinstellung: 'read' – jeder angemeldete Benutzer.
define( 'LSG_BL_CAP', 'edit_posts' );
```

## Entwicklung

`plan.md` ist die ausführliche Fassung: Datenmodell, Adapter, Parse-Pipeline,
jede getroffene Entscheidung und die Gründe dafür. Wer hier etwas ändert,
liest dort nach.

Die Tests liegen in zwei Lagen, beschrieben in `tests/README.md`:

```bash
composer install
vendor/bin/phpunit --testsuite unit
```

Composer ist Werkzeug der Entwicklung, nicht Bestandteil der Auslieferung:
`vendor/` wird nicht ausgeliefert, und ohne `composer install` funktioniert
das Plugin unverändert.

## Stand

Geplante Phasen:

~~1. Anlegen der vorhandenen Tabellen in der neuen Datenbank~~  
~~2. Übernahme bestehender Daten~~  
~~3. WordPress PlugIn mit Ausgabe wie bisher: Bestenliste, Gesamtsieger und Ewige Bestenliste~~  
~~4. UI zur Eingabe von neuen Sportler, Bestenliste und Gesamtsieger, wie bisher~~  
~~5. Einlesen von Ergebnis-PDFs, wie von RaceResult und Ergebnisse von Mitglieder in die Datenbank übernehmen~~  
~~6. Import und Erfassung ohne Seiten-Reload (REST + progressive enhancement)~~

Alle sechs Phasen sind damit durch – im Adminbereich stehen die sechs
Untermenüs Ergebnis-Import, Import-Log, Zuordnungen, Bestenliste, Sportler
und Gesamtsiege.

Was darüber hinaus offen ist – eine Rückgängig-Funktion für einen
Import-Vorgang, der Gesamtsieg direkt aus der Übernahme heraus, ein
Wartungslauf, der den Bestand nachrechnet – steht in `plan.md` unter 9.2, die
noch zu treffenden Entscheidungen unter 9.3.
