# Plan: WordPress-Plugin "LSG Bestenliste" – Ergebnisimport

> Arbeitsdokument. Abschnitt 8 ist die abarbeitbare Checkliste, Abschnitt 9
> hält die getroffenen Entscheidungen und die vorgemerkten Ausbaustufen fest.
>
> Stand der Recherche: 2026-08-26 (live gegen beide Systeme verifiziert)
>
> Stand der Prüfung gegen den Bestand: 2026-09-01 – Distanzen, Altersklassen
> und die Athleten-IDs der Startregeln sind gegen `assets/*.sql` gegengelesen.
> Dabei sind Datenfehler im Bestand aufgefallen (Doppelzeilen, uneinheitliche
> Schreibweisen, Lücken in `lsg_ak`). Sie sind als Vorarbeiten V1/V2 in
> Abschnitt 8 aufgenommen und im Skript
> `maintenance/2026-09-01-bestand-bereinigung.sql` ausformuliert.
>
> Stand der Umsetzung: 2026-09-02 – **M1, M2 und M3 sind fertig und geprüft.**
> Datenmodell samt Schema-Version, Interface, Value-Objects, Registry,
> HTTP-Torwächter, `RaceResultAdapter`, die gesamte Normalisierung und die
> Unit-Lage der Tests stehen; die Unit-Suite ist grün (148 Tests,
> 968 Assertions, gegen die echte Ettlingen-Antwort mit 658 Datensätzen).
> Zwei Befunde aus der Umsetzung sind unten an ihrer Stelle eingearbeitet:
> die race-result-Listen stehen nicht unter `config.lists` (4.1), und
> `01:20.24` braucht eine eigene Regel im Zeit-Parser (6.5.1).
>
> Mit M2 stehen die Admin-Seite (Schritt 1–3, vollständig ohne JavaScript),
> P1 und P2, die Distanz-/Datums-/Ort-Controls, der Trichter, die Vorschau
> und der Block der nicht übernommenen Vereine. Geschrieben wird noch
> nichts. Die Unit-Suite hat 196 Tests; dazu ist die Seite in allen ohne
> JavaScript erreichbaren Zuständen gegen die Ettlinger Fixture gerendert
> worden (658 gelesen → 1 ohne Zeit → 11 LSG, zwei Abrufe je Import).
>
> Mit M3 schreibt der Import. P3 (Zuordnung über exakt → Regel →
> normalisiert), P4 (Jahresabgleich), die Übernahme-Oberfläche mit Checkboxen
> und Statusspalte, die Schreiblogik in einer Transaktion, das Log und die
> Log-Ansicht stehen. Die Unit-Suite hat 224 Tests; der Schreibweg ist
> zusätzlich gegen eine echte Datenbank geprüft, inklusive des
> Abnahmekriteriums „zweimal ausgeführt ändert nichts", der
> Konflikterkennung, der Doppelzeile im Bestand und des Wegs aus einer
> offenen Zeile heraus.
>
> Stand der Bereinigung: 2026-09-01 – V1 und der Pflichtteil von V2 sind von
> Hand ausgeführt. Die Dumps in `assets/*.sql` bleiben absichtlich auf dem
> Stand **davor**: sie sind der Ausgangszustand, gegen den dieser Plan geprüft
> wurde. Maßgeblich für weitere Auswertungen ist die Datenbank.

---

## 1. Ziel

Ein WordPress-Plugin, das Laufergebnisse aus externen Zeitmessungs-Portalen
importiert, normalisiert, cached und als Bestenliste ausgibt.

Aktuell relevante Quellen:

| Quelle | System | Datenzugang | Beispiel |
|---|---|---|---|
| runtix.com | CodeResearch STS | **nur HTML** | 19. Hambrücker Lußhardtlauf |
| my.raceresult.com | race result | **JSON-API** | 17. SWE Halbmarathon Ettlingen |

---

## 2. Entscheidung: PHP, nicht Python

Begründung, kurz:

- Beide Quellen liefern reines Text/HTML bzw. JSON – kein JS-Rendering nötig,
  also kein Argument für einen Headless-Browser oder eine Python-Toolchain.
- Auf Shared Hosting ist kein Python-Interpreter garantiert; `exec()` /
  `shell_exec()` sind oft deaktiviert. Das Plugin wäre auf vielen
  Installationen nicht funktionsfähig.
- Ein separater Python-Service (FastAPI o.ä.) bedeutet zusätzliches Deployment,
  Auth, Monitoring – unverhältnismäßig für HTML-/JSON-Parsing.
- WP-Cron, Options, Transients, CPTs, Admin-UI, i18n, Update-Mechanismus sind
  PHP. Jeder Sprachwechsel erzwingt eine Brücke.

**Toolchain:** `wp_remote_get()` + `DOMDocument` + `DOMXPath` (beide PHP-Core,
keine Composer-Abhängigkeit → Plugin ist WP-Repo-fähig).

Optional, falls CSS-Selektoren bevorzugt werden: `symfony/dom-crawler`.
Für den aktuellen Umfang nicht erforderlich.

---

## 3. Quelle A: Runtix (HTML-Parsing)

> ⚠ **Nachtrag 2026-09-02, nach dem Bau des Adapters (M4).** Vier Dinge sind
> anders, als dieser Abschnitt sie beschreibt. Alle vier sind live gegen Event
> 3152 geprüft, alle vier stehen als Test fest:
>
> 1. **Die drei Listentypen liefern dieselben elf Spalten.** `total` (234
>    Zeilen), `sex` (73) und `ac` (23) haben je Zeile elf Zellen, keinen
>    colspan, keine Gruppenzeilen. Die Annahme, `ac` lasse den Gesamtplatz und
>    `sex` den AK-Platz weg, war falsch. Gelesen wird trotzdem nach Klasse: es
>    kostet nichts und trägt auch, wenn Runtix die Reihenfolge doch ändert.
> 2. **`sex` und `ac` sind Teillisten** – ein Geschlecht bzw. eine
>    Altersklasse, nicht dieselbe Menge anders sortiert. Wer dort importiert,
>    holt einen Ausschnitt. Deshalb ist `gesamtwertung` nur bei `total` gesetzt.
> 3. **Auf der Ergebnisseite steht kein Datum**, in keiner Form. Und die
>    Veranstaltungsseite `/sts/10021/{id}` nennt gleich **vier** – Lauftag
>    16.08., Meldeschluss 15.08., Lastschrifteinzug 19.08. und „Stand der
>    Ausschreibung 12.03.". Von dort ein Datum zu greifen heißt raten;
>    maßgeblich ist die Jahresübersicht.
> 4. **Der Eintrag in der Übersicht wird über den Datums-Link gefunden**, nicht
>    über den Ergebnis-Link: 58 der 157 Zeilen des Jahres 2026 haben keine
>    Ergebnisse und verlinken auch mit dem Namen auf `/sts/10021/`.
>
> Die Einzelheiten stehen an den Haken in Abschnitt 8 und im Kopf von
> `includes/adapters/class-runtix-adapter.php`.

### 3.1 Befund

- Beim Seitenaufruf: 29 Netzwerk-Requests, **0 davon XHR/fetch**.
- `app.js`, `ui.js`, `template.js`, `util.js` durchsucht nach
  `ajax` / `getJSON` / `dataType` / `.json` → **0 Treffer**.
- Kein CSV-/JSON-/Excel-Export. Nur clientseitiges Drucken
  (`printR()` gesamte Liste, `printC(contest, startnr)` einzeln).
- → **Es gibt keine API. HTML-Parsing ist der einzige Weg.**

### 3.2 Fallstrick: trailing slash

```
https://runtix.com/sts/10050/3152/21/total/   → 404 (nginx)
https://runtix.com/sts/10050/3152/21/total    → 200
```

Der Slash MUSS weg. URL-Builder entsprechend implementieren (`rtrim($url,'/')`).

### 3.3 URL-Schema

```
/sts/{view}/{eventId}/{contest}/{rlt}

view      10021  Veranstaltung
          10040  Teilnehmer
          10050  Ergebnislisten     ← relevant
          10080  Statistiken
          10051  Einzelergebnis  →  /sts/10051/{eventId}/{contest}/{startnummer}

eventId   z.B. 3152  (Hambrücker Lußhardtlauf)

contest   ACHTUNG: nicht rein numerisch!
          "21" = 21 KM Sparkasse Kraichgau-Lauf (21.1km)
          "10" = 10 KM Linhardt-Lauf (10km)
          "5"  = 5 KM HUK-Coburg-Lauf (5km)
          "w"  = 5 KM Interstick-Walk (5km)
          → als String behandeln, niemals (int) casten

rlt       "total"  Gesamt
          "sex"    Geschlecht
          "ac"     Altersklasse
```

Zusätzliche Formularfelder (GET-Form auf `/sts/index.php`):
`view`, `id`, `contest`, `rlt`, `rltv` (AK-Wert), `query` (Freitextsuche).

Hinweis: Ein direkter Aufruf von `index.php?view=10050&id=3152&contest=21&rlt=total`
liefert die News-Seite – das Mapping der Query-Parameter weicht ab.
**Die "pretty URLs" verwenden**, die funktionieren zuverlässig.

### 3.4 Tabellenstruktur – nach CSS-Klasse parsen

Es gibt genau ein `<table class="results">`. Jede Zelle trägt eine semantische
Klasse. Das ist der Schlüssel für einen robusten Parser:

| CSS-Klasse | Inhalt | Beispiel |
|---|---|---|
| `col-place-total` | Gesamtplatz | `1` |
| `col-place-sex` | Platz m/w | `1` |
| `col-place-ageclass` | Platz AK | `1` |
| `col-number` | Startnummer | `1126` |
| `col-competitor` | Name | `Körner, Holger` |
| `col-team` | Team / Verein | `LG Region Karlsruhe` |
| `col-birth` | Jahrgang | `1993` |
| `col-ageclass` | Altersklasse | `M 30` |
| `col-nationality` | Nation | `GER` |
| `col-time ` | Zeit ⚠ **Klasse endet auf Leerzeichen** | `01:11:54.9` |
| `col-buttons` | (Aktionen, leer) | |

**Regel: nach Klasse selektieren, nicht nach Spaltenposition.** Dann bricht der
Parser nicht, wenn ein anderer Veranstalter Spalten weglässt oder umsortiert.

```php
$res  = wp_remote_get( $url, [
    'timeout'    => 20,
    'user-agent' => 'LSG-Bestenliste/1.0 (+https://…)',
] );
if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) { /* … */ }
$html = wp_remote_retrieve_body( $res );

$doc = new DOMDocument();
libxml_use_internal_errors( true );          // Runtix liefert kein valides XHTML
$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
libxml_clear_errors();
$xp = new DOMXPath( $doc );

$rows = $xp->query( '//table[contains(@class,"results")]//tr[td]' );
foreach ( $rows as $tr ) {
    $get = function ( $cls ) use ( $xp, $tr ) {
        return trim( $xp->evaluate( "string(td[contains(@class,'$cls')])", $tr ) );
    };
    $row = [
        'platz'        => $get( 'col-place-total' ),
        'startnummer'  => $get( 'col-number' ),
        'name'         => $get( 'col-competitor' ),
        'verein'       => $get( 'col-team' ),
        'jahrgang'     => $get( 'col-birth' ),
        'ak'           => $get( 'col-ageclass' ),
        'nation'       => $get( 'col-nationality' ),
        'zeit'         => $get( 'col-time' ),
    ];
}
```

⚠ `contains(@class,'col-place-total')` matcht auch nichts anderes hier, aber
bei `col-place-sex` / `col-place-ageclass` auf Präfix-Kollisionen achten –
`col-place-total` ist kein Präfix von den anderen, passt also.
Bei `col-ageclass` vs. `col-place-ageclass`: **`contains` matcht beide!**
→ Für diese beiden Spalten exakteren Ausdruck verwenden, z.B.
`normalize-space(@class)='col-ageclass'`.

### 3.5 robots.txt

```
User-agent: GPTBot          Disallow: /
User-agent: Google-Extended Disallow: /
```

Nur AI-Trainings-Crawler gesperrt, **kein generelles Disallow**. Ein höflicher
Abruf (eigener User-Agent, nur auf Anstoß eines angemeldeten Benutzers) ist
damit nicht ausgeschlossen.

**Entschieden:** keine Anfrage bei Runtix/CodeResearch. Die Ergebnislisten sind
öffentlich, die Bestenliste des Vereins ist es auch, und es bleibt beim
bisherigen Vorgehen. Die technischen Rücksichtsmaßnahmen bleiben trotzdem
verbindlich, weil sie den Abruf überhaupt erst unauffällig machen:

- eigener User-Agent mit Kontakt-URL, keine Browser-Tarnung
- **Abruf nur, wenn jemand aktiv importiert** – kein Hintergrundabruf, kein
  WP-Cron, und nichts, was bei einem Seitenaufruf im Frontend passiert (5.2)
- Rate-Limit von 30 Abrufen / 10 min pro Benutzer (6.10)
- innerhalb eines Vorgangs Transients, damit das Durchklicken der Auswahl
  keine weiteren Requests erzeugt (6.4, 6.5)

Damit erzeugt das Plugin pro Import ein bis zwei Requests – weniger als ein
einzelner Besucher, der sich die Liste im Browser ansieht. Das Frontend liest
ausschließlich aus der Datenbank und berührt die Quelle nie (5.2).

---

## 4. Quelle B: race result (JSON-API)

**Verifiziert:** 658 Datensätze abgerufen – identisch zur `anzahl` in der
früheren `zieleinlauf.json`. Die API ist der klar bessere Weg.

### 4.1 Zwei Schritte

```
1) GET https://my.raceresult.com/{eventId}/results/config
        ?lang=de&noVisitor=1&sanitize=true

   → { key, server, contests{}, lists[ {ID, Name, ShowAs, Contest, Live} ], … }

2) GET https://{server}/{eventId}/results/list
        ?key={key}
        &listname={Name}          (URL-encoded, enthält "|")
        &page=results
        &contest={contestId}
        &r=all&l=0

   → { list:{Fields:[…]}, data:[[…],[…]], DataFields, LiveUpdateInterval, … }
```

⚠ **Korrektur, geprüft 2026-09-01 (Event 375768):** Die Ergebnislisten
stehen **nicht** unter `config.lists` – diesen Schlüssel gibt es gar nicht.
Sie liegen unter **`config.Tab.Config.Lists`**, genau in der oben notierten
Form, und ein zweites Mal unter `config.TabConfig.Lists` (dort mit
`Details: "details0"` statt `""`). Der Adapter liest der Reihe nach
`Tab.Config.Lists`, `TabConfig.Lists`, `lists` – damit trägt er auch, wenn
race result das Feld wieder umhängt.

Bei Ettlingen sind es 16 Listen; `Contest` ist dort ein **String** (`"2"`),
nicht die Zahl.

### 4.2 Fallstricke

- **`key` rotiert** → bei jedem Import zuerst `config` holen, nie cachen.
- **`server` ist nicht `my.raceresult.com`**, sondern z.B. `my4.raceresult.com`.
  Wert zwingend aus `config.server` nehmen.
- **Der alte Pfad `/RRPublish/data/list` gibt 404.** Aktuell: `/results/list`.
  (Viele Tutorials im Netz sind veraltet.)
- `r=all&l=0` liefert die komplette Liste. Die Web-UI holt initial nur
  `r=leaders&l=10`.
- `data` ist ein **Array von Arrays**, keine Objekte.
- Die Datenzeilen haben **zwei zusätzliche führende Felder** vor dem Platz:
  ```
  ["396", "400", "1.", "BORGHARDT Lukas", "TV Bad Säckingen", "1991", "1. M35", "1. M", "1:13:08"]
    ^bib   ^id    ^Platz
  ```
  Die Labels in `list.Fields` sind: `Platz, Stn, Name, Verein, Jg, AK-Pl., MW-Pl., Zeit`
  → 8 Labels vs. 9 Werte. **Mapping über `DataFields` auflösen, nicht per Index raten.**
- `listname` enthält ein Pipe-Zeichen, z.B. `01.1_Ergebnisse|Zieleinlauf_Brutto`
  → `urlencode()` nicht vergessen.
- Event-URL-Fragment (`#2_B45FAB`) = `{contest}_{listId}`. Die `listId` aus
  `config.lists[].ID` ändert sich bei Neu-Publish – nicht hart verdrahten,
  über `Name` oder `ShowAs` auflösen.

---

## 5. Architektur

Adapter hinter einem Interface, über eine Registry auffindbar. Quelle
austauschbar, Zielformat stabil, Zahl der Quellen offen.

```php
interface ErgebnisQuelle {
    public static function key(): string;                  // 'raceresult'
    public static function label(): string;                // 'race result'
    public static function erkennt( string $url ): int;    // 0..100

    public function eventLesen( string $url ): EventRef;
    public function wettbewerbe( EventRef $ref ): array;                   // Wettbewerb[]
    public function listen( EventRef $ref, string $contestId ): array;     // Liste[]

    /** @return Ergebnis[] normalisierte Zeilen */
    public function laden( EventRef $ref, string $contestId, ?string $listId ): array;
    public function quelleUrl( EventRef $ref, string $contestId, ?string $listId ): string;
}

final class RaceResultAdapter implements ErgebnisQuelle { /* JSON, bevorzugt */ }
final class RuntixAdapter     implements ErgebnisQuelle { /* DOMXPath, Fallback */ }
```

Das Interface trennt bewusst **Discovery** (`erkennt`, `eventLesen`,
`wettbewerbe`, `listen`) von **Datenabruf** (`laden`). Die Admin-Seite in
Abschnitt 6 arbeitet ausschließlich gegen die Discovery-Methoden und muss
deshalb keinen einzigen Adapter namentlich kennen.

Dateiablage – eine Datei je Klasse, wie bei den Admin-Seiten (6.2):

```
includes/adapters/interface-ergebnis-quelle.php
includes/adapters/class-event-ref.php          EventRef, Wettbewerb, Liste, Ergebnis
includes/adapters/class-raceresult-adapter.php
includes/adapters/class-runtix-adapter.php
includes/class-lsg-normalize.php               Normalisierung, ohne WordPress
includes/class-lsg-pipeline.php                P2, P3/P4-Logik, Trichter, Zustände – ohne WordPress
includes/class-lsg-athlete.php                 Athleten, Regeln, Bestandszeilen
includes/class-lsg-log.php                     lsg_import_run + lsg_import_log
includes/class-lsg-import.php                  Discovery, Caches, Parse-Orchestrierung
includes/class-lsg-http.php                    Torwächter, Allowlist, Rate-Limit
includes/class-lsg-adapters.php                Registry
includes/class-lsg-schema.php                  die drei neuen Tabellen
includes/admin/page-import.php                 Abschnitt 6
includes/admin/page-log.php                    6.8
includes/admin/page-log.php                    6.8
includes/admin/page-map.php                    6.5.3
includes/admin/page-best.php                   Abschnitt 7
```

⚠ **Der Abruf gehört nicht in den Parser.** Die Adapter bekommen den fertigen
HTML- bzw. JSON-String und geben normalisierte Zeilen zurück; `wp_safe_remote_get()`
steht davor, in einer eigenen Funktion. Nur so lassen sich die Adapter später
gegen eine Fixture prüfen, ohne WordPress und ohne Netz (Abschnitt 8,
Verifikation).

### 5.1 Zielformat (Normalisierung)

Angelehnt an die bereits verwendete `zieleinlauf.json` – als kanonisches
Schema beibehalten:

```
platz, startnummer, name, verein, jahrgang, ak_platz, mw_platz, zeit
```

Plus Metadaten pro Import:

```
event { id, name, datum }, contest { id, name },
liste, zeitmessung (brutto|netto), quelle (URL), abgerufen (Datum), anzahl
```

### 5.2 Kein Dauerabruf – der Import ist ein einmaliger Vorgang

Ein früherer Entwurf sah WP-Cron, eine Event-Liste und regelmäßige Abrufe vor.
Das ist mit den Festlegungen aus 9.1 hinfällig, und zwar aus einem
einzigen Grund: **Läufe werden immer über die URL übergeben.** Es gibt keine
gespeicherte Liste von Events, also gibt es auch nichts, was ein Cron-Job
regelmäßig abrufen könnte.

Der Ablauf ist stattdessen:

```
Mensch gibt URL ein  →  einmal abrufen  →  parsen  →  in lsg_best schreiben
                                                       ↓
Frontend rendert ausschließlich aus der Datenbank  ←────┘
```

Daraus folgt:

- **Kein WP-Cron-Job, kein Hintergrundabruf, kein stale-while-error.** Die
  Quelle wird nur berührt, wenn jemand aktiv importiert.
- **Keine Rohdatenhaltung.** Nach dem Import ist die Quelle irrelevant; was
  bleibt, sind `lsg_best` und das Log (6.8).
- **Die drei Frontend-Blöcke lesen wie bisher direkt aus der Datenbank.** Am
  Rendering ändert sich durch den Import nichts – kein Transient, kein
  zusätzlicher Cache-Layer.
- **„Live"-Listen** (`Live:1` bei race result) spielen keine Rolle: importiert
  wird, wenn das Ergebnis endgültig ist. Wurde zu früh importiert, korrigiert
  ein zweiter Import die Zeit – P4 erkennt „schneller" bzw. „langsamer".

Gecacht wird nur innerhalb eines Import-Vorgangs, damit das Durchklicken der
Auswahl keine Requests erzeugt: Wettbewerbe/Listen 15 min (6.4), das
Parse-Ergebnis 1 h (6.5). Beides sind Transients mit kurzer Lebensdauer, keine
Datenhaltung.

---

## 6. Backend-Oberfläche: Admin-Seite „Ergebnis-Import"

Der Import bekommt eine **eigene Seite im WordPress-Backend** – keinen Block
und keine Oberfläche im Block-Editor. Der Redakteur öffnet die Seite, gibt eine
URL an und arbeitet sich durch drei Schritte:

```
Schritt 1   URL eingeben          →  Adapter automatisch erkennen
Schritt 2   Wettbewerb wählen     →  ggf. Ergebnisliste wählen
Schritt 3   Button „Parsen"       →  P1 lesen  →  P2 LSG filtern
                                  →  P3 Athleten zuordnen
                                  →  P4 gegen lsg_best abgleichen
            Auswahl per Checkbox  →  Button „Übernehmen"  →  Datenbank + Log
```

Der Ablauf ist ein Assistent mit *sichtbarem* Zwischenstand: nach jedem Schritt
steht auf der Seite, was erkannt bzw. geladen wurde. Kein „Blackbox-Button".

### 6.1 Warum eigene Seite und nicht Block-Editor

- **Der Import erzeugt keinen Seiteninhalt, sondern Datenbankinhalt.** Das
  Ergebnis landet in den `lsg_*`-Tabellen und ist danach für *alle* Seiten
  verfügbar. Es an einen einzelnen Beitrag zu binden, wäre die falsche
  Lebensdauer: Beitrag löschen dürfte die importierten Ergebnisse nicht
  betreffen – bei einem Block wäre genau das die naheliegende Erwartung.
- Die Ausgabe machen bereits die drei bestehenden Blöcke (Bestenliste,
  Gesamtsiege, Ewige Bestenliste). Ein vierter Block, der nur schreibt und
  nichts rendert, wäre ein Fremdkörper im Editor.
- Ein Import ist ein wiederkehrender Verwaltungsvorgang, oft für mehrere
  Wettbewerbe hintereinander – dafür will man keine Seite anlegen und speichern.
- Nebeneffekt: kein `ServerSideRender`, kein Block-Attribut-Schema, keine
  Editor-Skript-Registrierung. Deutlich weniger bewegliche Teile.

Das passt auch zu Phase 4 der README („UI zur Eingabe von neuen Sportlern,
Bestenliste und Gesamtsieger"): dort entsteht ohnehin ein Backend-Bereich, in
den sich der Import als weiterer Menüpunkt einfügt.

### 6.2 Menü-Einhängung

Ein Top-Level-Menü, das später die weiteren Pflege-Oberflächen aufnimmt:

```php
add_action( 'admin_menu', function () {
    add_menu_page(
        __( 'LSG Bestenliste', 'lsg-bestenliste' ),   // Seitentitel
        __( 'LSG Bestenliste', 'lsg-bestenliste' ),   // Menütitel
        LSG_BL_CAP,                                   // Capability
        'lsg-bestenliste',                            // Slug
        'lsg_bl_admin_import_page',                   // erste Seite = Import
        'dashicons-chart-line',
        58                                            // unter „Werkzeuge"
    );
    add_submenu_page(
        'lsg-bestenliste',
        __( 'Ergebnis-Import', 'lsg-bestenliste' ),
        __( 'Ergebnis-Import', 'lsg-bestenliste' ),
        LSG_BL_CAP,
        'lsg-bestenliste',                            // gleicher Slug: kein Doppeleintrag
        'lsg_bl_admin_import_page'
    );
    // Weitere Untermenüs, s.u.
} );
```

**Entschieden: das Menü bekommt weitere Untermenüs.** Geplante Reihenfolge –
die ersten vier gehören zu diesem Plan, der Rest zu Phase 4 der README:

| Untermenü | Slug | Inhalt | Wann |
|---|---|---|---|
| Ergebnis-Import | `lsg-bestenliste` | Abschnitt 6 | jetzt |
| Import-Log | `lsg-bestenliste-log` | Abschnitt 6.8 | jetzt |
| Zuordnungen | `lsg-bestenliste-map` | Regeln aus `lsg_athlete_map` (6.5.3) | jetzt |
| Bestenliste | `lsg-bestenliste-best` | `lsg_best` von Hand erfassen und korrigieren – Abschnitt 7 | jetzt |
| Sportler | `lsg-bestenliste-athleten` | `lsg_athlete` pflegen | Phase 4 |
| Gesamtsiege | `lsg-bestenliste-win` | `lsg_win` pflegen, später Ziel von 6.5.5 | Phase 4 |

Zwei Dinge, die dabei jetzt schon festgelegt sein sollten, weil sie später
teuer nachzurüsten sind:

- **Ein Callback pro Seite, eine Datei pro Callback**
  (`includes/admin/page-import.php`, `page-log.php`, …). Nicht alles in eine
  wachsende `admin.php`.
- **Eine Capability, nicht mehrere.** Import (Abschnitt 6) und manuelle
  Erfassung (Abschnitt 7) laufen beide über `LSG_BL_CAP`: derselbe Kreis von
  Leuten, dieselbe Konstante. Ob die Athletenpflege aus Phase 4 eine eigene,
  engere Konstante braucht, wird dann entschieden – vorgesehen ist sie nicht
  mehr, weil zwei Konstanten für dieselbe Personengruppe nur die Frage
  aufwerfen, welche denn nun gilt.

**Entschieden: jeder angemeldete WordPress-Benutzer darf importieren.** Die
passende Capability dafür ist `read` – sie hat jede Rolle bis hinunter zum
Abonnenten, und sie greift in `add_menu_page()`, `current_user_can()` und im
`permission_callback` gleichermaßen:

```php
if ( ! defined( 'LSG_BL_CAP' ) ) {
    define( 'LSG_BL_CAP', 'read' );   // jeder angemeldete Benutzer
}
```

Als Konstante, nicht hart verdrahtet: falls der Kreis später doch enger werden
soll, genügt eine Zeile in der `wp-config.php` (`edit_posts` für Redakteure
aufwärts, `manage_options` nur für Administratoren).

⚠ **`LSG_BL_CAP` ist die einzige Stelle, an der die Capability steht.** In
`add_menu_page()`, in jedem `current_user_can()` und in jedem
`permission_callback` steht die Konstante, nie `'read'` und nie `'edit_posts'`
ausgeschrieben. Sonst hängt die Zugriffsregel an drei Orten, und die Zeile in
der `wp-config.php` verschiebt nur einen davon.

⚠ Zwei Dinge, die aus dieser Entscheidung folgen und im Code stehen müssen:

- **`read` ist nicht „egal".** Nicht angemeldete Besucher haben diese
  Capability nicht – die Prüfung muss trotzdem in jedem Handler stehen, sonst
  ist der Import ein offener Endpunkt, über den Fremde Requests an
  Drittserver auslösen können.
- **Nachvollziehbarkeit ersetzt die Zugriffsbeschränkung.** Wenn viele Leute
  schreiben dürfen, muss ablesbar sein, wer was getan hat: `user_id` steht
  in `lsg_import_run`, das Log (6.8) hält jede Aktion fest, und
  `lsg_best`-Einträge lassen sich darüber zurückverfolgen.

⚠ **Diese Entscheidung hängt an der Installation, nicht am Code.** `read` hat
jedes Konto, auch eines aus einer offenen Registrierung. Sie trägt, solange
Konten von Hand angelegt werden – bei dieser Installation ist das so. Wird die
Registrierung je geöffnet, ist die eine Zeile in der `wp-config.php` die
Antwort, und sie greift für Import *und* manuelle Erfassung gleichzeitig. Genau
dafür gibt es nur eine Konstante.

### 6.3 Schritt 1: URL → Adapter-Erkennung

Ein Textfeld nimmt die URL entgegen. Serverseitig entscheidet **nicht** eine
`if/else`-Kette über den Host, sondern eine Registry: jeder Adapter beantwortet
selbst, ob er eine URL bedienen kann.

```php
interface ErgebnisQuelle {
    /** Eindeutiger Schlüssel, z.B. 'raceresult'. */
    public static function key(): string;

    /** Anzeigename für die UI, z.B. 'race result'. */
    public static function label(): string;

    /**
     * Kann dieser Adapter die URL bedienen?
     * Höherer Rückgabewert = sicherere Erkennung. 0 = nein.
     * @return int 0..100
     */
    public static function erkennt( string $url ): int;

    /** Schritt 1→2: Event-Kontext aus der URL lösen. */
    public function eventLesen( string $url ): EventRef;

    /** Schritt 2: verfügbare Wettbewerbe. */
    public function wettbewerbe( EventRef $ref ): array;   // Wettbewerb[]

    /** Schritt 2b: Ergebnislisten eines Wettbewerbs. Leeres Array = nur eine. */
    public function listen( EventRef $ref, string $contestId ): array;  // Liste[]

    /** Schritt 3: die eigentlichen Daten. */
    public function laden( EventRef $ref, string $contestId, ?string $listId ): array;

    public function quelleUrl( EventRef $ref, string $contestId, ?string $listId ): string;
}
```

Registry mit Filter-Hook, damit später weitere Adapter dazukommen können –
auch aus einem anderen Plugin, ohne dieses hier anzufassen:

```php
function lsg_bl_adapter_registry(): array {
    $adapter = array(
        RaceResultAdapter::class,
        RuntixAdapter::class,
    );
    /**
     * Filter: weitere Adapter registrieren.
     * Erwartet Klassennamen, die ErgebnisQuelle implementieren.
     */
    return (array) apply_filters( 'lsg_bl_ergebnis_adapter', $adapter );
}

function lsg_bl_adapter_fuer_url( string $url ): ?string {
    $best = null; $score = 0;
    foreach ( lsg_bl_adapter_registry() as $cls ) {
        $s = $cls::erkennt( $url );
        if ( $s > $score ) { $score = $s; $best = $cls; }
    }
    return $score > 0 ? $best : null;
}
```

Erkennungsregeln der beiden vorhandenen Adapter:

| Adapter | Trifft auf | Score |
|---|---|---|
| `RaceResultAdapter` | Host `*.raceresult.com` **und** numerische Event-ID im Pfad | 90 |
| `RaceResultAdapter` | Host `*.raceresult.com` ohne erkennbare ID | 40 |
| `RuntixAdapter` | Host `runtix.com` mit `/sts/`-Pfad | 90 |
| `RuntixAdapter` | Host `runtix.com` | 40 |

Aus der URL wird gleich mitgelesen, was schon drinsteht – so ist der Assistent
bei einer tief verlinkten URL nach Schritt 1 bereits fertig vorbelegt:

```
https://my.raceresult.com/375768/#2_B45FAB   → eventId 375768, contest 2, listId B45FAB
https://runtix.com/sts/10050/3152/21/total   → eventId 3152, contest "21", rlt "total"
```

⚠ Das URL-Fragment (`#2_B45FAB`) erreicht den Server **nicht**, wenn der
Browser die Seite lädt – hier ist es unkritisch, weil die URL als Formularwert
übertragen wird. Beim Einfügen aus der Adresszeile ist das Fragment enthalten.

**Fehlerfälle in Schritt 1** (alle mit Klartext-Meldung als `notice notice-error`,
kein stiller Abbruch):

- Kein Adapter erkennt die URL → „Für diese Adresse gibt es noch keinen Adapter."
  plus Auflistung der unterstützten Portale.
- URL nicht erreichbar / HTTP ≠ 200 → Statuscode anzeigen.
- Adapter erkannt, aber Event-ID nicht extrahierbar → Hinweis auf das erwartete
  URL-Format mit Beispiel.

Zusätzlich ein Select „Adapter" mit Option **„automatisch (erkannt: …)"** und
manueller Übersteuerung. Nötig, wenn ein Portal seine URL-Struktur ändert oder
ein neuer Adapter dieselbe Domain bedient.

### 6.4 Schritt 2: Wettbewerbe und Ergebnislisten wählen

Nach erfolgreicher Erkennung füllt die Seite ein `<select name="contest">`.

**race result** – aus `config`:

```
config.contests  →  { "1": "Halbmarathon", "2": "10 km", … }
config.lists[]   →  [ { ID, Name, ShowAs, Contest, Live }, … ]
```

`lists` ist nach `Contest` zu filtern. Achtung: Einträge mit `Contest: 0`
(bzw. fehlendem Feld) gelten **für alle** Wettbewerbe und müssen jedem
Wettbewerb zugeschlagen werden.

**Runtix** – die Wettbewerbe stehen im `<select name="contest">` der Seite
`/sts/10050/{eventId}` (ohne trailing slash!). Value = Contest-Key als String
(`"21"`, `"10"`, `"5"`, `"w"`), Label = Anzeigetext
(„21 KM Sparkasse Kraichgau-Lauf"). Die Ergebnislisten sind bei Runtix fest:

```
total  Gesamtwertung
sex    Geschlechterwertung
ac     Altersklassenwertung
```

Trotzdem als Select anbieten – dieselbe UI für beide Quellen, und `rlt`
bestimmt die Sortierung der gelieferten Zeilen.

Verhalten des zweiten Selects:

- **Genau eine Liste** → Feld ausblenden, Wert implizit setzen. Die Auswahl
  wird nur eingeblendet, wenn es wirklich etwas zu wählen gibt.
- **Mehrere Listen** → Feld zeigen, erste Liste vorauswählen.
- Wechsel des Wettbewerbs → Listen neu laden, alte Auswahl verwerfen
  (bei race result hängen die Listen am Contest, ein Beibehalten wäre falsch).

Beide Listen (Wettbewerbe, Ergebnislisten) werden für **15 Minuten** in einem
Transient gecacht, Schlüssel `lsg_bl_disc_` + `md5(adapter|eventId)`.
Grund: der Redakteur klickt sich mehrfach durch die Auswahl, das darf nicht
jedes Mal einen Fremdserver treffen. Ein „Neu laden"-Link leert den Transient.

⚠ Der race-result-`key` ist von diesem Cache **ausgenommen** – er rotiert
(siehe 4.2). Gecacht werden nur die abgeleiteten Wettbewerbs-/Listennamen;
der `key` wird bei jedem Datenabruf frisch aus `config` geholt.

### 6.5 Schritt 3: Parsen – vier Teilschritte

„Parsen" ist kein einzelner Vorgang, sondern eine Pipeline aus vier Stufen.
Jede Stufe hat ein definiertes Zwischenergebnis, und jede Stufe kann
fehlschlagen, ohne die vorherige zu entwerten:

```
P1  Alle Ergebnisse lesen        →  Rohzeilen, normalisiert
P2  Auf LSG Karlsruhe filtern    →  nur Vereinsmitglieder
P3  Athleten zuordnen            →  lsg_athlete.id je Zeile
P4  Gegen lsg_best abgleichen    →  Status je Zeile: neu / schneller / langsamer
```

Erst nach P4 entsteht die Übernahme-Oberfläche (6.6). Bis dahin wird **nichts**
geschrieben; das Zwischenergebnis liegt im **Parse-Transient** (1 h, 6.10) –
nicht im Discovery-Cache aus 6.4, der nur die Wettbewerbs- und Listennamen hält.
Zwei getrennte Caches mit zwei Lebensdauern, nicht einer.

An zwei Stellen braucht der Import eine Übersetzung zwischen dem, was die
Quelle schreibt, und dem, was die Datenbank kennt:

| | Von | Nach | Wo |
|---|---|---|---|
| **Mapping 1** | Wettbewerbsbezeichnung („21 KM …") | Distanzcode (`HM`) | 6.5.1, im Code |
| **Mapping 2** | Teilnehmerzeile (Name + Jahrgang) | `lsg_athlete.id` | 6.5.3, Tabelle `lsg_athlete_map` |

Mapping 1 steht im Code, weil die Zielcodes feststehen (9.1: keine neuen
Distanzen). Mapping 2 gehört in die Datenbank, weil dort laufend Fälle
dazukommen, die niemand vorhersehen kann.

**Ein Vorgang betrachtet genau eine Ergebnisliste.** Keine Mehrfachauswahl,
keine Sammelverarbeitung mehrerer Wettbewerbe eines Events. Wer den 10er und
den Halbmarathon desselben Laufs importieren will, macht den Ablauf zweimal –
mit zwei Einträgen in `lsg_import_run`.

Das ist Absicht, nicht Sparsamkeit: Distanz, Ort und Datum (6.5.1) gelten für
die *ganze* Liste. Sobald ein Vorgang zwei Wettbewerbe umfasst, gelten sie
nicht mehr, und aus drei einfachen Feldern würde eine Matrix. Ein Vorgang, eine
Liste, eine Distanz – dann bleibt auch das Log eindeutig lesbar.

Die Stufenzahlen der Oberfläche zeigen den Trichter:
`428 gelesen → 9 LSG → 8 zugeordnet, 1 ohne Zuordnung → 5 neu, 2 schneller, 1 langsamer, 1 offen`.
Das ist die wichtigste Kontrollanzeige der ganzen Seite: springt „LSG" auf 0,
stimmt der Vereinsfilter nicht, und man sieht es sofort.

#### 6.5.1 P1 – Alle Ergebnisse lesen

Der Adapter liefert pro Zeile diese Felder. Was die Quelle nicht hergibt,
bleibt leer – nicht geraten:

| Feld | Pflicht | Herkunft race result | Herkunft Runtix |
|---|---|---|---|
| `nachname` | ja | aus `Name` gesplittet | `col-competitor` gesplittet |
| `vorname` | ja | aus `Name` gesplittet | `col-competitor` gesplittet |
| `teilnehmer` | nein | `Name` roh, ungesplittet | `col-competitor` roh |
| `geschlecht` | ja | aus `MW-Pl.` / `AK-Pl.` | aus `col-ageclass` |
| `jahrgang` | ja | `Jg` | `col-birth` |
| `verein` | ja | `Verein` | `col-team` |
| `zeit` | ja | Netto, sonst Brutto | `col-time` |
| `zeit_typ` | ja | `netto` \| `brutto` | i.d.R. `brutto` |
| `platz` | nein | `Platz` | `col-place-total` |
| `startnummer` | nein | `Stn` | `col-number` |

`platz` wird nur für die Gesamtsieg-Erkennung gebraucht (6.5.5) und sonst
nirgends ausgewertet – die Bestenliste sortiert nach Zeit, nicht nach Platz.

**Namen splitten** – der Fallstrick dieser Stufe. Die beiden Quellen schreiben
Namen unterschiedlich:

```
Runtix        „Körner, Holger"        →  Komma trennt: Nachname, Vorname
race result   „BORGHARDT Lukas"       →  Großschreibung markiert den Nachnamen
race result   „von Hoff Anna-Maria"   →  Namenspartikel, mehrteiliger Vorname
```

Regel, in dieser Reihenfolge:

**Vorgeschaltet, ergänzt am 2026-09-02:** doppelte Kommas werden zu einem
zusammengezogen, danach fallen Kommas an den Rändern weg. Der Anlass ist echt –
in der Runtix-Liste zu Event 3152 steht `Michalewski,, Patrick`. Ohne diesen
Schritt liefert Regel 1 den Vornamen `, Patrick`, mit führendem Komma. Der
passt dann auf keinen Athleten in der Datenbank, und zwar **ohne** dass
irgendwo ein Fehler auftaucht: die Zeile bliebe still als „nicht zugeordnet"
liegen, und man sucht den Grund in P3, wo er nicht ist.

1. Enthält der String ein Komma → `Nachname, Vorname`. Eindeutig, fertig.
2. Sonst: zusammenhängender Block aus komplett großgeschriebenen Wörtern am
   Anfang = Nachname, Rest = Vorname. Deckt `VON HOFF Anna-Maria` mit ab.
3. Sonst: **letztes** Wort = Vorname, alles davor = Nachname – und die Zeile
   wird als `namen_unsicher` markiert.

`namen_unsicher` ist kein Fehler, sondern eine Anzeige: die Oberfläche hebt
diese Zeilen hervor, die Zuordnung in P3 läuft trotzdem. Das rohe
`teilnehmer`-Feld wird immer mitgeführt, damit im Zweifel (und im Log)
nachvollziehbar ist, was die Quelle wirklich geliefert hat.

**Geschlecht** steht in keiner Quelle als eigenes Feld, aber in der
Altersklasse: Runtix `col-ageclass` = `M 30` / `W 45`, race result
`AK-Pl.` = `1. M35` bzw. `MW-Pl.` = `1. M`. Erstes Zeichen des
Klassen-Codes → `m` / `w`, gemappt auf die Plugin-Konvention `m` / `f`
(`lsg_athlete.cat`). Fehlt die Klasse: leer lassen, in P3 aus dem gefundenen
Athletendatensatz übernehmen.

⚠ Für die Altersklasse ist dieses Feld **nicht** maßgeblich – die rechnet sich
in jedem Fall aus `lsg_athlete.cat` (6.5.3). Einen Zweck hat es trotzdem:
Weicht das Geschlecht der Quelle vom zugeordneten Athleten ab, ist das ein
starker Hinweis auf eine Fehlzuordnung – „die Quelle sagt W, der zugeordnete
Athlet ist m" trifft man selten zufällig. Die Zeile wird deshalb nicht
abgelehnt, aber in der Vorschau markiert.

**Zeit** – Nettozeit hat Vorrang. race result führt sie je nach Liste als
eigenes Feld (`Netto`, `Nettozeit`, `Net`, `Chip`); der Adapter sucht diese
Labels in `DataFields`, und erst wenn keines existiert, nimmt er die
Bruttozeit. Runtix liefert nur eine Zeit. Welche es war, wird in `zeit_typ`
mitgeführt und im Log festgehalten – sonst vergleicht man später Netto gegen
Brutto, ohne es zu merken.

Normalisierung auf das Format von `lsg_best.time` (`varchar(15)`, `HH:MM:SS`).
Die Quellen liefern **vier** Schreibweisen, nicht zwei – die Stundenangabe
fehlt bei kurzen Distanzen, und Zehntel können an beiden Formen hängen:

```
1:13:08      →  01:13:08     HH:MM:SS, führende Null ergänzen
01:11:54.9   →  01:11:55     HH:MM:SS.t, Zehntel aufgerundet
38:57        →  00:38:57     MM:SS
18:57,3      →  00:18:58     MM:SS.t – Komma als Dezimaltrenner kommt vor
DNF/DSQ/DNS  →  Zeile verwerfen, in P1-Warnungen zählen
```

⚠ **`MM:SS.t` ist der Fall, der leicht durchrutscht.** Ein 5-km-Lauf wird oft
ohne Stundenangabe, aber mit Zehntel ausgegeben. Wer nur `HH:MM:SS.t` und
`MM:SS` getrennt behandelt, verliert diese Zeit oder speichert sie ungerundet.
Deshalb: **eine** Funktion, alle vier Formen, und die Zehntel werden
abgetrennt, *bevor* über HH:MM:SS oder MM:SS entschieden wird.

**Entschieden:** Zehntel werden nach der World-Athletics-Regel **aufgerundet**
(`01:11:54.1` → `01:11:55`), nicht abgeschnitten. In `lsg_best.time` landet
immer ein sekundengenauer Wert `HH:MM:SS`, passend zum Bestand. Die
Originalzeit inklusive Zehntel bleibt im Log (`roh_zeit`) erhalten, falls
später doch einmal jemand nachrechnen will.

```php
/**
 * Roh-Zeit einer Quelle → 'HH:MM:SS', oder '' wenn nicht verwertbar.
 * Dieselbe Funktion benutzt das Formular aus Abschnitt 7 (7.2).
 */
function lsg_bl_zeit_normalisieren( string $raw ): string {
    $raw = trim( $raw );

    // DNF / DSQ / DNS / leer → keine Zeit. Der Aufrufer zählt sie.
    if ( '' === $raw || preg_match( '/^(dnf|dsq|dns|--?)$/i', $raw ) ) {
        return '';
    }

    // 1. Zehntel abtrennen – vor jeder weiteren Entscheidung.
    $auf = 0;
    if ( preg_match( '/^(.*?)[.,](\d+)$/', $raw, $m ) ) {
        $raw = $m[1];
        if ( '' !== ltrim( $m[2], '0' ) ) {   // jede Stelle > 0 rundet auf
            $auf = 1;
        }
    }

    // 2. HH:MM:SS oder MM:SS – beide Formen, danach identische Rechnung.
    if ( preg_match( '/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $raw, $m ) ) {
        $sek = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    } elseif ( preg_match( '/^(\d{1,3}):([0-5]\d)$/', $raw, $m ) ) {
        $sek = (int) $m[1] * 60 + (int) $m[2];
    } else {
        return '';
    }

    $sek += $auf;

    return sprintf( '%02d:%02d:%02d',
        intdiv( $sek, 3600 ), intdiv( $sek % 3600, 60 ), $sek % 60 );
}
```

⚠ `ceil()` auf einem Float wäre hier falsch: `(float) '54.9'` ist nicht exakt
darstellbar, und bei `.0` würde ein Rundungsfehler eine Sekunde erfinden.
Deshalb der Vergleich auf dem Nachkommastring – und deshalb prüft
`ltrim( $m[2], '0' )` auf den String, nicht `(int) $m[2] > 0`: bei `.000`
ist beides gleich, bei einer Quelle mit Millisekunden (`.004`) nicht.

⚠ Das Aufrunden über die Minuten- und Stundengrenze fällt durch die Rechnung
in Sekunden von selbst richtig aus: `01:11:59.9` wird zu `01:12:00`, nicht zu
`01:11:60`. Ein Zusammensetzen aus den Einzelgruppen täte das nicht.

⚠ **Ergänzt bei der Umsetzung: `MM:SS` mit zwei Nachkommastellen wird
verworfen.** Zwei Schreibweisen sehen gleich aus und sind es nicht:

```
18:57.3    MM:SS mit Zehntel        →  00:18:58
01:20.24   Tippfehler für 01:20:24 →  ''        (verworfen)
```

Beide sind „MM:SS + Nachkommastellen". Unterscheidbar sind sie nur an der
Stellenzahl: eine echte Zehntelangabe hat *eine* Stelle, eine verrutschte
Sekundenangabe zwei. Ohne diese Regel liefert `01:20.24` die Zeit `00:01:21` –
eine Minute für einen Halbmarathon, und niemand sieht es. Nach einer
vollständigen `HH:MM:SS`-Form bleibt dagegen jede Stellenzahl erlaubt
(`.0`, `.9`, `.004`); dort ist nichts mehrdeutig.

⚠ **Kein Rückfall auf den Zahlen-Fallback.** Was diese Funktion nicht erkennt,
liefert `''` und die Zeile wird verworfen und gezählt – sie wird nicht
irgendwie interpretiert. Der tolerante Zweig in `lsg_bl_parse_performance()`
ist für die historischen Tippfehler im Bestand da (und nach Vorarbeit V1,
Abschnitt 8, auch dort nicht mehr nötig); über den Import darf kein neuer
dazukommen.

**Distanz, Datum und Ort** kommen *nicht* aus der Zeile, sondern aus dem
Wettbewerb. Sie gelten für den gesamten Import (ein Vorgang = eine Liste) und
werden über der Tabelle als Felder angeboten – jedes davon vorbelegt, wenn es
sich ermitteln lässt, und in jedem Fall änderbar:

- `distanz` – Select mit den kanonischen Codes aus `lsg_bl_distance_map()`
  (`5km`, `10km`, `HM`, `Marathon`, …), vorbelegt durch eine Heuristik auf
  den Wettbewerbsnamen: `21 KM Sparkasse Kraichgau-Lauf` → `HM`,
  `10 KM Linhardt-Lauf` → `10km`. Die Heuristik darf danebenliegen, deshalb
  ist das Feld **immer sichtbar und änderbar**, nie versteckt.

  **Entschieden: die Liste der Distanzen bleibt geschlossen.** Es kommen keine
  Distanzen dazu, die nicht schon in der Datenbank stehen. Der Import kann
  deshalb gar keine neue Distanz erzeugen – ein Select über die bekannten
  Codes, kein Freitextfeld.

  ⚠ **Entschieden: Zeitläufe (`6h`, `12h`, `24h`) sind vom Import
  ausgenommen.** Das Select bietet nur die neun Streckendistanzen an
  (`5km`, `10km`, `15km`, `20km`, `25km`, `HM`, `Marathon`, `50km`, `100km`).

  Der Grund steht in der Datenbank: Bei den Zeitläufen hält `lsg_best.time`
  **keine Zeit, sondern eine Strecke** – im Bestand stehen dort Werte wie
  `112,737 km` (6h 63, 12h 33, 24h 103 Zeilen). Genau dafür kennt
  `lsg_bl_distance_map()` den Typ `distance`, und `lsg_bl_parse_performance()`
  liefert `better => 'higher'`.

  Die Parse-Pipeline erzeugt aber ausschließlich Zeiten: P1 normalisiert jede
  Zeile auf `HH:MM:SS` (6.5.1). Stünde `6h` im Select, würde eine Zeit in ein
  Streckenfeld geschrieben und P4 vergliche sie anschließend als Zahl gegen
  `112,737` – ein stiller Fehler ohne Fehlermeldung.

  Beide Portale kennen diese Wettbewerbsform ohnehin nicht, deshalb kostet der
  Ausschluss nichts. Die drei Codes bleiben in `lsg_bl_distance_map()` und in
  der Frontend-Ausgabe unverändert; sie werden weiterhin von Hand gepflegt.
  Wählt jemand einen Zeitlauf-Wettbewerb, sagt die Meldung: *„Zeitläufe werden
  nicht importiert – dort steht eine Strecke, keine Zeit. Bitte unter
  ‚Bestenliste' von Hand erfassen."* Diese Seite gibt es (Abschnitt 7), der
  Hinweis führt also irgendwohin und ist keine Sackgasse.

**Mapping 1 von 2: Wettbewerbsbezeichnung → Distanzcode.** Die Quellen nennen
die Strecke, wie es der Veranstalter geschrieben hat; die Datenbank kennt nur
die Codes aus `lsg_bl_distance_map()`. Dazwischen steht eine Übersetzungsliste
– bewusst als Code, nicht als Datenbanktabelle, weil sich die Zielcodes nie
ändern:

```php
/**
 * Schreibweisen aus den Quellen → kanonischer Distanzcode.
 * Schlüssel sind bereits normalisiert (klein, ohne Leer-/Sonderzeichen).
 */
function lsg_bl_distance_aliases(): array {
    return array(
        // Halbmarathon
        '21'       => 'HM',
        '21km'     => 'HM',
        '211km'    => 'HM',      // "21,1 km"
        '210975km' => 'HM',      // "21,0975 km"
        'halbmarathon' => 'HM',
        'hm'       => 'HM',
        'halfmarathon' => 'HM',
        // Marathon
        '42'       => 'Marathon',
        '42km'     => 'Marathon',
        '42195km'  => 'Marathon',
        'marathon' => 'Marathon',
        // der Rest ist geradeaus
        '5'  => '5km',   '5km'  => '5km',
        '10' => '10km',  '10km' => '10km',
        '15' => '15km',  '15km' => '15km',
        '20' => '20km',  '20km' => '20km',
        '25' => '25km',  '25km' => '25km',
        '50' => '50km',  '50km' => '50km',
        '100'=> '100km', '100km'=> '100km',
        // 6h / 12h / 24h fehlen bewusst: Zeitläufe werden nicht importiert
        // (s.o.) – dort hält lsg_best.time eine Strecke, keine Zeit.
    );
}
```

Die beiden wichtigen Fälle sind `21` → `HM` und `42` → `Marathon`: die Quellen
schreiben die Kilometerzahl, die Datenbank den Namen. Ohne diese Übersetzung
landet ein Halbmarathon nie in der Bestenliste, weil `21km` dort schlicht nicht
existiert.

Gesucht wird in dieser Reihenfolge:

```
1. Wettbewerbsname normalisieren
   „21 KM Sparkasse Kraichgau-Lauf"  →  „21 km sparkasse kraichgau lauf"
2. Zuerst nach einem Namens-Token suchen: halbmarathon, marathon, hm
   → trifft „Marathon" vor „42", und verhindert, dass „Marathon-Staffel
     über 4x10 km" wegen der 10 als 10km durchgeht
3. Dann nach einer Zahl mit optionalem „km" am Wortanfang
   → 21, 21km, 21,1km …
4. Kein Treffer → Feld bleibt leer
```

⚠ Reihenfolge beachten: Name vor Zahl. Ein „Halbmarathon (21,1 km)" enthält
beides, und `21` wäre hier zufällig richtig – aber „5. Ettlinger Marathon"
enthält eine `5`, die nichts mit der Distanz zu tun hat. Deshalb gewinnt immer
das Distanzwort, und die Zahl greift nur, wenn keines da ist.

⚠ Auch bei einem Treffer bleibt das Feld **sichtbar und änderbar**. Die
Zuordnung ist eine Vorbelegung, keine Entscheidung – bei einem „Silvesterlauf
über 8,5 km" liegt sie zwangsläufig daneben.

Läuft die Zuordnung ins Leere (10-Meilen-Lauf, 7,5-km-Strecke), bleibt das Feld
leer, der Parsen-Button gesperrt, und die Meldung sagt: *„Für diesen Wettbewerb
gibt es keine passende Distanz in der Bestenliste."* Wer eine Distanz doch
aufnehmen will, erweitert `lsg_bl_distance_map()` bewusst im Code – nicht
versehentlich beim Importieren.

**Wettbewerbsauswahl und Distanz sind zwei getrennte Entscheidungen.** Das ist
die Arbeitsteilung zwischen Schritt 2 und Schritt 3:

```
contests (Schritt 2)   →  WELCHE Ergebnisliste geparst wird
Distanz-Control        →  UNTER WELCHER Distanz sie in lsg_best landet
```

Der Wettbewerb bestimmt also nur die Datenquelle. Ob und unter welchem Code die
Zeiten gespeichert werden, entscheidet allein das Distanz-Dropdown – vorbelegt,
wenn die Zuordnung eindeutig ist, sonst leer und von Hand zu wählen.

Deshalb braucht es für einzelne Wettbewerbsarten keine Sonderregeln. Ein
Walking-Wettbewerb („5 KM Interstick-Walk" bei Runtix, „Walking 21,1km" bei
race result), eine Staffel oder ein Bambinilauf sind aus Sicht des Imports
nichts Besonderes: Sie erscheinen in der Wettbewerbsauswahl wie jeder andere
Eintrag, und wer sie parst, sieht die vorgeschlagene Distanz und entscheidet.

⚠ Das heißt aber auch: Die Vorbelegung liest nur die Streckenangabe im Namen.
„Walking 21,1km" bekommt `HM` vorgeschlagen, weil `21,1km` darin steht. Wer
einen solchen Wettbewerb nicht in der Lauf-Bestenliste haben will, ändert das
Feld oder bricht ab – das Dropdown steht sichtbar über der Tabelle, damit genau
diese Entscheidung nicht übersprungen wird.

**Der Ort** kommt aus dem Eventnamen und ist frei überschreibbar
(`lsg_best.town`, `varchar(30)` – bei langen Namen kürzen, nicht abschneiden
lassen).

**Das Veranstaltungsdatum** ist der heikelste der drei Werte, weil an ihm mehr
hängt als die Anzeige: **es bestimmt das Jahr, und das Jahr bestimmt, gegen
welchen Bestand P4 vergleicht.** Ein Datum im falschen Jahr überschreibt nicht
die vorhandene Zeit, sondern legt still eine zweite Zeile an – und in der
Bestenliste steht der Lauf dann im falschen Jahrgang. Deshalb wird das Datum
genauso behandelt wie die Distanz: erkannt, angezeigt, änderbar, und ohne
gültigen Wert geht es nicht weiter.

Ermittelt wird es in dieser Reihenfolge:

```
1. Adapter-Metadaten
   Runtix       Veranstaltungsübersicht bzw. Ausschreibung – Details unten
   race result  Datumsfeld aus der config-Antwort

2. Datum im Event- oder Wettbewerbsnamen
   „…, 17.05.2026" · „2026-05-17" · „17. Mai 2026"

3. Nur eine Jahreszahl im Namen
   „17. SWE Halbmarathon Ettlingen 2026"  →  Jahr 2026, Tag und Monat fehlen

4. Nichts gefunden  →  Feld bleibt leer
```

**Runtix im Detail – geprüft am 2026-08-27.** Die Ergebnisliste selbst enthält
**kein Datum**. Nachgesehen und ohne jede Veranstaltungsangabe:

```
/sts/10050/{id}/{contest}/{rlt}   Ergebnisliste   → kein Datum
/sts/10051/{id}/{contest}/{stnr}  Einzelergebnis  → kein Datum
/sts/10080/{id}                   Statistik       → kein Datum
```

Alle drei zeigen im Kopf nur den Veranstaltungsnamen („19. Hambrücker
Lußhardtlauf") und in der Fußzeile „Copyright © CODERESEARCH 2001 - 2026" –
eine Jahreszahl, die nichts mit dem Lauf zu tun hat und die ein zu gieriger
Parser prompt als Veranstaltungsjahr missverstehen würde.

Es gibt zwei Stellen, an denen das Datum steht:

**1. Veranstaltungsübersicht `/sts/10020/{jahr}` – strukturiert, bevorzugt**

Ein Eintrag je Veranstaltung, jeweils mit vorangestelltem Datum:

```
[16.08.2026]  19. Hambrücker Lußhardtlauf   Anmelden · Teilnehmer · Ergebnisse
[04.01.2026]  Dolgesheimer Neujahrslauf     Anmelden · Teilnehmer · Ergebnisse
[22.02.2026]  38. Oggersheimer Berglauf     Anmelden · Teilnehmer · Ergebnisse
```

Der „Ergebnisse"-Link zeigt auf `/sts/10050/{eventId}`. **Darüber wird der
Eintrag gefunden – über die ID, niemals über den Namen.** Namen wiederholen
sich („Silvesterlauf"), IDs nicht.

Die Seite ist nach Jahr gegliedert: Auswahl 2008–2027, Standard ist das
laufende Jahr, und `/sts/10020/2025` liefert verifiziert das Jahr 2025
(01.01.2025 bis 31.12.2025). Ein Monatsfilter existiert, wird hier nicht
gebraucht. Rund 200–220 Einträge pro Jahr – ein Request, der sich zu cachen
lohnt (Transient je Jahr, 15 min, wie die übrige Discovery in 6.4).

**2. Veranstaltungsseite `/sts/10021/{eventId}` – Fließtext, Notnagel**

Dort steht die Ausschreibung des Veranstalters, das Datum als Überschrift:

```
Sonntag, den 16. August 2026
```

Frei formuliert, also ohne Formatgarantie – der nächste Veranstalter schreibt
„16.8.26" oder „So., 16. Aug." Als alleinige Quelle taugt das nicht, als
Einstieg schon.

**Das Henne-Ei-Problem:** Die Übersichtsseite ist die verlässliche Quelle,
braucht aber ein Jahr, das man erst kennt, wenn man das Datum hat. Deshalb
dieser Ablauf:

```
1. /sts/10021/{eventId} holen, Datum aus dem Text lesen
   Muster: „16. August 2026" · „16.08.2026" · „16.8.26"
   → ergibt meist ein vollständiges Datum, mindestens aber ein Jahr

2. Mit diesem Jahr /sts/10020/{jahr} holen und den Eintrag suchen,
   dessen Link /sts/10050/{eventId} enthält
   → dessen [TT.MM.JJJJ] ist der maßgebliche Wert (datum_quelle = 'liste')

3. Liefert Schritt 1 nichts: /sts/10020/{laufendes Jahr} probieren,
   dann das Vorjahr. Danach abbrechen und das Feld leer lassen.
   Zwei Fehlversuche sind vertretbar, ein Durchsuchen von 2008 bis 2027
   nicht – 20 Requests für ein Datum, das der Mensch in fünf Sekunden
   eintippt.
```

Schritt 2 ist auch dann sinnvoll, wenn Schritt 1 bereits ein vollständiges
Datum geliefert hat: stimmen beide überein, ist der Wert bestätigt; weichen
sie ab, gewinnt die Übersichtsliste und der Unterschied wird angezeigt.

⚠ Bei mehrtägigen Veranstaltungen nennt die Übersicht nur einen Tag. Für die
Bestenliste genügt das – dort steht ohnehin ein einzelnes Datum. Fällt eine
solche Veranstaltung über einen Jahreswechsel, entscheidet das angezeigte
Datum, und der Mensch kann korrigieren.

**race result im Detail – geprüft am 2026-08-27.** Ergebnis vorweg: **die
`config`-Antwort enthält kein Veranstaltungsdatum.** Die Schlüssel auf oberster
Ebene sind (Event 375768, Ettlingen):

```
key · contests · splits · eventname · TimerLogo · TimerURL
EventOver · Time · server · BrandColorDark · ListCommentsEnabled
Tab · TabConfig · ContestColors
```

Kein `EventDate`, kein `Datum`, kein `Date`. Was auf den ersten Blick danach
aussieht, ist es nicht:

- `Tab` / `TabConfig` führen `ActiveFrom: 2022-04-09T00:00:00+02:00` und
  `ActiveUntil: 2100-12-31T23:59:59+01:00` – das ist die Gültigkeit der
  Ergebnis-Ansicht, nicht der Lauf. Ein Parser, der `ActiveFrom` nimmt, trägt
  2022 ein.
- `Time: 69537` ist ein Zählwert der Zeitmessung, kein Zeitstempel.
- `EventOver: true` sagt nur, dass die Veranstaltung vorbei ist.
- `contests` ist eine flache Zuordnung `ID → Name`, ohne Datum:
  `{"1":"Walking 21,1km", "2":"Hauptlauf 21,1km", "8":"Bambini 500m (<2019)", …}`
- `eventname: "17. SWE Halbmarathon Ettlingen"` enthält **keine** Jahreszahl.

Damit greift für race result keine der Stufen 1 bis 3: **das Datumsfeld bleibt
leer und wird von Hand ausgefüllt.** Das ist kein Fehler, sondern der Normalfall
dieser Quelle – die Oberfläche sagt es entsprechend: *„Die Quelle nennt kein
Datum – bitte eintragen."*

Zwei Nebenbefunde aus derselben Prüfung, die in den Adapter gehören:

- `server` lieferte hier `my-us-1.raceresult.com`, im früheren Test
  `my4.raceresult.com`. Der Wert wechselt tatsächlich – er **muss** aus
  `config.server` kommen (4.2), eine feste Annahme bricht.
- `contests` zeigt „Walking 21,1km" neben „Hauptlauf 21,1km" – zwei Einträge,
  die beide auf `HM` abgebildet würden. Ein weiterer Beleg dafür, dass die
  Wettbewerbsauswahl die eigentliche Entscheidung ist und das Distanz-Dropdown
  sichtbar bleiben muss: Am Distanzwert allein sind die beiden nicht zu
  unterscheiden.

⚠ **`/results/list` ist in der `robots.txt` von my.raceresult.com für Crawler
gesperrt** (u.a. `/*/*/list`, `/RRPublish`; für Yandex die ganze Domain). Der
Abruf der `config` ist davon nicht betroffen. Für das Plugin ändert das nichts
an der Funktion – es ist kein Crawler, ruft eine Adresse ab, die der Nutzer
selbst eingegeben hat, und tut das einmal pro Import (9.1, gleiche Haltung wie
bei Runtix). Festgehalten wird es hier, weil es beim Testen mit fremden
Werkzeugen erklärt, warum ein Abruf ohne Vorwarnung abgelehnt wird.

**Im Zweifel bleibt das Feld leer – für beide Controls.** Weder das Datum noch
die Distanz werden geraten:

| Lage | Datum | Distanz |
|---|---|---|
| eindeutig erkannt | vorbelegt, änderbar | vorbelegt, änderbar |
| mehrdeutig oder unvollständig | **leer** | **leer** |
| gar nicht gefunden | **leer** | **leer** |

„Mehrdeutig" heißt beim Datum: zwei Quellen nennen verschiedene Tage, oder es
steht nur eine Jahreszahl fest. Bei der Distanz: der Wettbewerbsname enthält
mehrere Streckenangaben („Marathon-Staffel 4x10 km") oder eine, die
`lsg_bl_distance_map()` nicht kennt.

Ein leeres Feld ist ehrlicher als ein falsch geratenes: Es hält den
Parsen-Button gesperrt und verlangt eine Entscheidung, statt eine falsche
Vorbelegung durchzuwinken, die niemand mehr prüft.

**Woher der Wert stammt, wird mitgeführt** (`datum_quelle`), am Feld angezeigt
und in `lsg_import_run` protokolliert – damit später nachvollziehbar ist, wie
sicher er war:

| Wert | Herkunft | Anzeige am Feld |
|---|---|---|
| `liste` | Runtix, `/sts/10020/{jahr}` | „aus der Veranstaltungsübersicht" |
| `ausschreibung` | Runtix, `/sts/10021/{eventId}` | „aus der Ausschreibung gelesen" |
| `api` | race result, `config`-Antwort | „aus der Quelle übernommen" |
| `name` | Datum im Event-/Wettbewerbsnamen | „aus dem Namen gelesen" |
| `jahr` | nur die Jahreszahl erkannt | „nur das Jahr erkannt – Tag und Monat ergänzen" |
| `manuell` | von Hand eingetragen | – |



⚠ **Ein unvollständiges Datum wird nicht ergänzt.** Kein stiller 1. Januar, kein
Importdatum als Ersatz. `lsg_best.date` wird in der Bestenliste als TT.MM.JJJJ
ausgegeben – ein erfundener Tag wäre dort eine sichtbare Falschangabe. Fehlen
Tag und Monat, bleibt das Feld unvollständig und der Parsen-Button gesperrt.

⚠ **Als Timestamp mit 12:00 Uhr Ortszeit speichern**, nicht mit 00:00 – und
zwar über `wp_timezone()`, nicht über `mktime()`:

```php
$ts = ( new DateTimeImmutable(
    sprintf( '%04d-%02d-%02d 12:00:00', $y, $m, $d ), wp_timezone()
) )->getTimestamp();
```

Zwei Fehler stecken hier übereinander. Der erste: Bei Mitternacht kann die
Zeitzonenrechnung den Tag um eins verschieben, und dann steht in der
Bestenliste der Vortag – genau so liegen sechs Altfälle im Bestand (6.5.4).
Der zweite: WordPress setzt die PHP-Zeitzone auf UTC, `mktime( 12, 0, 0, … )`
liefert also 12:00 **UTC**. In Mitteleuropa ist das derselbe Tag, der Wert
wäre damit brauchbar – aber er ist nicht das, was hier steht, und auf einer
Installation mit anderer Zeitzone bricht die Annahme. Der Bestand in
`lsg_best.date` ist ein `int`-Timestamp, die Uhrzeit wird nirgends ausgegeben –
12:00 Ortszeit kostet nichts und verhindert beides.

Als Eingabefeld ein `<input type="date">` mit Textfeld-Fallback (`TT.MM.JJJJ`),
damit die Seite ohne JavaScript bedienbar bleibt (6.9). Dazu drei
Plausibilitätshinweise – Hinweise, keine Sperren:

- Datum in der Zukunft → *„Der Lauf liegt in der Zukunft – stimmt das Datum?"*
- mehr als zehn Jahre zurück → Nachfrage
- Jahr weicht von der Jahreszahl im Eventnamen ab → beide Werte anzeigen

**Beide Felder – Datum und Distanz – stehen immer über der Tabelle**, auch wenn
die Erkennung erfolgreich war. Sie sind Vorbelegungen, keine Feststellungen.
Und sie sind Pflicht: ohne gültige Distanz *und* vollständiges Datum bleibt der
Parsen-Button gesperrt, mit einer Meldung, die sagt, welcher der beiden Werte
fehlt.

⚠ **Wird einer der beiden Werte nach dem Parsen geändert, ist die Vorschau
ungültig.** Beide gehen in P4 ein: die Distanz in die Suche nach dem Bestand,
das Datum in das Jahr. Die Oberfläche verwirft die Tabelle dann und schaltet
zurück auf „Parsen" – lieber ein zweiter Durchlauf als eine Tabelle, deren
Statusspalte nicht mehr zu den Feldern darüber passt.

#### 6.5.2 P2 – Auf LSG Karlsruhe filtern

Übernommen werden nur Zeilen, deren Vereinsfeld **sowohl `LSG` als auch
`Karlsruhe`** enthält. Vergleich auf einer normalisierten Fassung des
Vereinsnamens:

```php
function lsg_bl_verein_normalisieren( string $v ): string {
    $v = strtolower( $v );
    $v = strtr( $v, array( 'ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss' ) );
    $v = preg_replace( '/[^a-z0-9]+/', ' ', $v );   // Punkte, Bindestriche raus
    return trim( preg_replace( '/\s+/', ' ', $v ) );
}

function lsg_bl_ist_lsg( string $verein ): bool {
    $n = lsg_bl_verein_normalisieren( $verein );
    return ( false !== strpos( $n, 'lsg' ) ) && ( false !== strpos( $n, 'karlsruhe' ) );
}
```

Damit greifen `LSG Karlsruhe`, `LSG-Karlsruhe`, `lsg karlsruhe e.v.`,
`LSG Karlsruhe/Lemminge` gleichermaßen.

Bewusst **nicht** getroffen: `LG Region Karlsruhe` (das ist ein anderer Verein –
`lg` ≠ `lsg`) und ein bloßes `Karlsruhe` als Wohnort. Das ist der Zweck der
UND-Verknüpfung.

Zwei Sicherungen gegen stille Fehler:

- Die Oberfläche zeigt neben der Trefferzahl einen aufklappbaren Block
  **„nicht übernommene Vereine"** mit allen ausgefilterten Vereinsschreibweisen
  und ihrer Häufigkeit. Steht dort ein `LSG Ka.` oder `LSG KA`, sieht man den
  verpassten Treffer sofort, statt ihn nie zu bemerken.
- Aus diesem Block heraus lässt sich eine Schreibweise per Klick als
  **Vereins-Alias** aufnehmen (Option `lsg_bl_verein_alias`, eine Liste
  normalisierter Strings, die zusätzlich als LSG gelten).

Zeilen ohne Vereinsangabe fallen durch den Filter. Sie erscheinen im
Nicht-übernommen-Block unter „(kein Verein)", damit ein Mitglied, das ohne
Verein gemeldet war, nicht unsichtbar verschwindet.

#### 6.5.3 P3 – Athleten in `lsg_athlete` zuordnen

Für jede verbliebene Zeile wird der Athlet gesucht. In dieser Reihenfolge,
erster Treffer gewinnt:

| Stufe | Kriterium | `match_type` |
|---|---|---|
| 1 | `name` + `firstname` + `born` exakt (case-insensitive) | `exakt` |
| 2 | Zuordnungsregel aus `lsg_athlete_map` (s.u.) | `regel` |
| 3 | normalisierter Name + `born` (Umlaute, Bindestriche, Groß/Klein egal) | `normalisiert` |
| – | mehrere Regeln treffen | `mehrdeutig` → nicht importieren |
| – | kein Treffer | `offen` → nicht importieren |

Eine vierte Stufe „ähnlicher Name, wahrscheinlich dieselbe Person" gibt es
bewusst **nicht**. Entweder die Zuordnung ist eindeutig – über den Namen, eine
Regel oder die Normalisierung – oder die Zeile wird nicht importiert. Ein
„wahrscheinlich" hätte niemand bestätigt, ohne es doch von Hand zu prüfen.

Normalisierung wie beim Verein: `strtolower`, `ä→ae`, `ß→ss`, alles außer
`a-z0-9` zu Leerzeichen. Damit fallen `Körner`/`Koerner`,
`Anna-Maria`/`Anna Maria`, `MÜLLER`/`Müller` von selbst zusammen.

**Der Jahrgang ist in jeder Stufe Pflicht.** Zwei Personen mit gleichem Namen
und gleichem Jahrgang im Verein sind unwahrscheinlich; ein Namensabgleich ohne
Jahrgang würde dagegen früher oder später Ergebnisse dem Falschen zuschreiben.
Liefert die Quelle keinen Jahrgang, bleibt die Zeile `offen` – auch wenn der
Name eindeutig aussieht.

**Mapping 2 von 2: Zuordnungsregeln (`lsg_athlete_map`).** Hier landen die
Fälle, die kein Namensvergleich löst – dauerhaft, damit dieselbe Korrektur
nicht jedes Jahr neu von Hand gemacht wird.

Die drei bekannten Fälle zeigen, warum eine reine Alias-Tabelle
(„Schreibweise X gehört zu Athlet Y") nicht reicht:

| Fall | Athlet in `lsg_athlete` | Regel | Was daran besonders ist |
|---|---|---|---|
| `171` | `Dr. Pfeiffer`, Wolfram, 1961 | Vorname `wolfram` **und** Nachname `pfeiffer` **und** Jg. 1961 | der Normalfall: beide Felder gesetzt – die Quelle schreibt „Pfeiffer", die Datenbank „Dr. Pfeiffer" |
| `183` | `van Wees`, Harry, 1943 | Vorname `harry` **und** Jg. 1943 | **kein Nachname** – der variiert in den Listen |
| `377` | `Schlippe-Schrieber`, Gudrun, 1955 | `gudrun` als Vor- **oder** Nachname **und** Jg. 1955 | Felder sind in der Quelle vertauscht |

⚠ Die IDs sind gegen `assets/lsg_athlete.sql` geprüft (2026-08-31). Ein
früherer Entwurf nannte hier `337` – das ist *Österle, Hans-Jörg, 1967, m* und
damit eine völlig andere Person. Beim Anlegen der Startdatensätze deshalb
jede `athletes_id` einmal gegen Name und Jahrgang gegenlesen: Eine Regel, die
auf den Falschen zeigt, schreibt Zeiten still einem Fremden gut, und in der
Bestenliste sieht man dem Eintrag nichts an.

Daraus folgt das Tabellenmodell: leeres Feld = beliebig, plus ein Modus für
den feldunabhängigen Vergleich.

```php
$sql = "CREATE TABLE {$t_map} (
  id           int UNSIGNED NOT NULL AUTO_INCREMENT,
  tstamp       int UNSIGNED NOT NULL DEFAULT 0,
  athletes_id  int UNSIGNED NOT NULL,                 -- Ziel in lsg_athlete
  born         year         NOT NULL,                 -- Pflicht, immer
  vorname      varchar(30)  NOT NULL DEFAULT '',      -- normalisiert; '' = beliebig
  nachname     varchar(30)  NOT NULL DEFAULT '',      -- normalisiert; '' = beliebig
  modus        varchar(8)   NOT NULL DEFAULT 'feld',  -- 'feld' | 'egal'
  aktiv        tinyint(1)   NOT NULL DEFAULT 1,
  notiz        varchar(255) NOT NULL DEFAULT '',      -- warum es diese Regel gibt
  user_id      bigint UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY lookup (born, aktiv),
  KEY athlete (athletes_id)
) {$charset_collate};";
```

⚠ Die Blöcke hier und in 6.8 sind bereits so geschrieben, wie `dbDelta()` sie
braucht – siehe die Regeln am Ende von 6.8. Wer sie kopiert, kopiert also
keine Fallstricke mit: `$t_map` kommt aus `lsg_bl_table( 'lsg_athlete_map' )`,
`$charset_collate` aus `$wpdb->get_charset_collate()`, und die Feldtypen
tragen keine Anzeigebreiten. `born` bekommt bewusst **kein**
`DEFAULT 0000` – der Jahrgang ist Pflicht, ein Vorgabewert lädt nur dazu ein,
ihn wegzulassen.

Bedeutung der Felder – knapp, weil daran alles hängt:

- **`born` ist immer Pflicht.** Keine Regel ohne Jahrgang, auch keine mit
  vollem Namen. Das ist dieselbe Regel wie in den übrigen Zuordnungsstufen und
  der Grund, warum die breite Regel `harry` überhaupt vertretbar ist.
- **`vorname` / `nachname` beschreiben die Quelle, nicht `lsg_athlete`.** Was in
  der Ergebnisliste steht, wird zugeordnet; wie der Athlet in der Datenbank
  heißt, ist Sache von `athletes_id`.
- **Beide Felder sind normalisiert gespeichert** (klein, `ä→ae`, ohne
  Sonderzeichen) – der Vergleich läuft nie über Rohstrings.
- **`modus = 'feld'`**: Vorname gegen Vornamensfeld, Nachname gegen
  Nachnamensfeld. **`modus = 'egal'`**: jedes gesetzte Token muss in *einem* der
  beiden Felder vorkommen, egal in welchem. Das deckt vertauschte Spalten und
  den Fall ab, dass der Splitter aus 6.5.1 danebengegriffen hat.
- **`aktiv`** statt Löschen: eine Regel, die sich als falsch erweist, wird
  abgeschaltet und bleibt im Log nachvollziehbar.

Die drei bekannten Regeln als Startdatensatz:

```sql
INSERT INTO lsg_athlete_map (tstamp, athletes_id, born, vorname, nachname, modus, notiz) VALUES
  (UNIX_TIMESTAMP(), 171, 1961, 'wolfram', 'pfeiffer', 'feld',
   'Schreibweise des Nachnamens weicht in den Listen ab'),
  (UNIX_TIMESTAMP(), 183, 1943, 'harry',   '',         'feld',
   'Nachname variiert; Vorname + Jahrgang sind im Verein eindeutig'),
  (UNIX_TIMESTAMP(), 377, 1955, 'gudrun',  '',         'egal',
   'Vor- und Nachname in der Quelle vertauscht');
```

Regel `377` braucht `nachname` nicht: im Modus `egal` genügt das eine Token
`gudrun`, und es darf in beiden Feldern stehen.

Der Lookup in Stufe 2, als Pseudocode:

```
kandidaten = SELECT * FROM lsg_athlete_map WHERE born = <jahrgang> AND aktiv = 1

treffer = kandidaten filtern auf:
    modus = 'feld':
        (vorname  = ''  ODER vorname  = <quelle.vorname_norm>)
    UND (nachname = ''  ODER nachname = <quelle.nachname_norm>)
    modus = 'egal':
        jedes nicht-leere Token ∈ { quelle.vorname_norm, quelle.nachname_norm }

genau 1 Treffer  →  athletes_id übernehmen, match_type = 'regel'
mehrere Treffer  →  match_type = 'mehrdeutig', Zeile bleibt offen
kein Treffer     →  weiter mit Stufe 3
```

⚠ **Zwei Regeln, die dieselbe Zeile treffen, sind ein Fehler, keine
Auswahlfrage.** Die Zeile bleibt `offen`, die Meldung nennt beide Regel-IDs.
Sonst entscheidet die Sortierreihenfolge der Datenbank darüber, wem ein
Ergebnis gutgeschrieben wird – und das fällt niemandem auf.

⚠ Regeln greifen erst **nach** dem exakten Treffer (Stufe 1) und **nach** P2.
Beides begrenzt den Schaden einer breiten Regel wie `harry` + 1943 erheblich:
verglichen wird nur gegen die Handvoll LSG-Zeilen einer Liste, und wo der Name
ohnehin exakt passt, kommt die Regel gar nicht zum Zug.

⚠ Eine Regel ohne Vor- **und** Nachname (nur Jahrgang) wird beim Anlegen
abgelehnt. Sie würde jeden LSG-Läufer dieses Jahrgangs auf einen Athleten
ziehen.

Gepflegt werden die Regeln im Untermenü **„Zuordnungen"** (6.2) und direkt aus
der Übernahme-Tabelle heraus: jede `offen`-Zeile bietet neben der Zuordnung
eine Checkbox *„als Regel merken"*, die aus den Werten der Zeile eine
`modus = 'feld'`-Regel mit Vorname, Nachname und Jahrgang erzeugt. Die
Sonderformen (`egal`, leeres Feld) entstehen durch Nachbearbeiten im Untermenü –
sie sind selten genug, dass sich dafür keine eigene Bedienlogik lohnt.

**Eine nicht zuordenbare Zeile wird nicht importiert – aber angezeigt.** Beides
gehört zusammen: Es entsteht kein Datensatz, den später niemand erklären kann,
und trotzdem sieht der Mensch, dass da jemand war.

Der Import legt **niemals** einen Athleten an. `lsg_athlete` wird an anderer
Stelle gepflegt (Phase 4), und die Zuordnungsregeln im Untermenü
„Zuordnungen". Ein Tippfehler in einer Ergebnisliste kann so keinen
Doppel-Athleten erzeugen, und ein Import bleibt das, was er ist: das Übernehmen
von Zeiten für bekannte Personen.

Die Zeile steht **mitten unter den anderen**, nicht in einem abgetrennten
Block: gleiche Tabelle, gleiche Reihenfolge, nur ohne Checkbox und ohne
Auswahl. Wer die Liste von oben nach unten durchgeht, kann sie nicht übersehen.

Der Grund steht im Klartext in der Statusspalte – nicht nur die Feststellung:

| Grund | Meldung in der Tabelle |
|---|---|
| Name unbekannt | „Keine Zuordnung möglich – kein Athlet mit diesem Namen und Jahrgang" |
| Jahrgang fehlt in der Quelle | „Keine Zuordnung möglich – die Ergebnisliste nennt keinen Jahrgang" |
| Mehrere Regeln passen | „Keine Zuordnung möglich – Regeln #12 und #17 treffen beide zu" |

„Keine Zuordnung möglich" ohne Begründung wäre eine Sackgasse; mit Begründung
weiß der Mensch sofort, ob ein Athlet fehlt, eine Regel gebraucht wird oder die
Quelle unvollständig ist.

Ausgegeben wird die Zeile mit **allem, was die Quelle geliefert hat** – Platz,
Startnummer, roher Teilnehmerstring, Vor- und Nachname wie gesplittet,
Jahrgang, Verein, Zeit. Bei einer nicht zuordenbaren Person ist genau das die
einzige Information, die überhaupt noch da ist:

```
[ – ]  Weber, Klaus            1969   —     00:44:12   —
       roh: „WEBER Klaus" · Stn 218 · Platz 34 · LSG Karlsruhe
       ⚠ Keine Zuordnung möglich – kein Athlet mit diesem Namen und Jahrgang
       ähnlich in lsg_athlete: Weber, Claus (1969) · Weber, Klaus (1972)
```

Die Liste **ähnlicher Athleten** darunter ist reine Lesehilfe, kein
Auswahlfeld. Sie beantwortet die häufigste Frage von selbst – „gibt es den
schon, nur anders geschrieben?" – und macht im Beispiel sichtbar, dass
`Weber, Claus (1969)` und `Weber, Klaus (1972)` beide *nicht* passen, das eine
im Namen, das andere im Jahrgang.

Über der Tabelle steht die Zahl zusätzlich als eigene Meldung, damit sie bei
40 Zeilen nicht untergeht: *„2 Teilnehmer ohne Zuordnung – nicht importiert"*,
mit Link auf den Filter „nur ohne Zuordnung".

**Der Weg aus so einer Zeile heraus** führt bewusst über zwei Schritte, nicht
über einen Knopf in der Import-Tabelle:

```
1. Ursache beheben
   Athlet fehlt      →  Untermenü „Sportler" (Phase 4) bzw. direkt in lsg_athlete
   Schreibweise      →  Untermenü „Zuordnungen": Regel in lsg_athlete_map
   Regeln kollidieren→  Untermenü „Zuordnungen": eine der beiden abschalten
2. Import erneut ausführen
   Dieselbe URL, derselbe Wettbewerb. Bereits übernommene Zeiten stehen
   danach auf „gleich" und ändern nichts – nur die reparierte Zeile kommt neu
   dazu (P4, Idempotenz).
```

Das kostet einen zweiten Durchlauf, hält aber die Import-Seite frei von
Stammdatenpflege – und der zweite Durchlauf ist billig, weil er nichts
doppelt schreibt.

⚠ **Auch eine unzugeordnete Zeile landet im Log** (`aktion = skip_offen`,
`athletes_id = 0`, Rohfelder gefüllt). Damit ist Monate später noch
nachvollziehbar, dass diese Person am Lauf teilgenommen hat und warum sie nicht
in der Bestenliste steht. Ohne diesen Eintrag wäre der Fall nach dem Schließen
der Seite verloren.

**Altersklasse.** `lsg_best.ak` erwartet Codes wie `m45`, `whk`
(Geschlechts-Präfix + Alterszahl bzw. `hk` für die Hauptklasse). Die
Altersklasse wird **selbst berechnet**, nicht aus der Quelle übernommen:

```
alter = Jahr(Veranstaltungsdatum) − Jahrgang
alter < 30      →  'hk'
sonst           →  5er-Stufe abgerundet: 30,35,40,…  (floor(alter/5)*5)
Code            =  ('f' === cat ? 'w' : 'm') . stufe
```

**Entschieden: Jahrgangsklassen, keine Stichtagsklassen.** Das Alter ist
`Veranstaltungsjahr − Jahrgang`, unabhängig davon, ob der Geburtstag am
Wettkampftag schon war. Ein Jahrgang 1976 läuft also ab dem 1. Januar 2026 in
`m50`, auch wenn er erst im November 50 wird. Genau das leistet die Formel
oben – das Geburtsdatum wird nicht gebraucht, und `lsg_athlete.born` ist
ohnehin nur ein `year(4)`.

Grund für die Eigenberechnung: die Quellen benutzen eigene Klassenschemata
(`M 30`, `1. M35`, teils Jahrgangsklassen wie `MJU20`), und der Bestand in
`lsg_best` muss in sich konsistent bleiben. Weicht die berechnete Klasse von
der Quelle ab, wird das als Hinweis angezeigt – bei einem Veranstalter, der
nach Stichtag wertet, ist diese Abweichung erwartbar und kein Fehler.

⚠ **`lsg_ak` ist eine Anzeigeliste, keine Prüfinstanz.** Ein früherer Entwurf
sah vor, den berechneten Code gegen `lsg_ak` zu validieren und im Zweifel zu
warnen. Das trägt nicht: Die Tabelle kennt nur `mhk`/`whk`, `m30`–`m75` und
`w30`–`w70`, im Bestand stehen aber längst 32 Zeilen mit `m80`, `w75`, `w80`,
`w85` und `w90` (geprüft 2026-09-01). Eine Prüfung dagegen schlüge bei korrekt
gerechneten Codes dauerhaft an und sagte niemandem etwas.

Wozu `lsg_ak` tatsächlich dient: `lsg_bl_ak_list_for_gender()` baut daraus das
AK-Dropdown der Frontend-Blöcke. Fehlt ein Code dort, ist die Altersklasse im
Filter nicht auswählbar – genau das ist heute für die 32 Zeilen oben der Fall,
ganz ohne Import.

Daraus folgt das Verhalten, im Import wie im Formular (7.2):

- Der berechnete Code wird **immer geschrieben**, ohne Vorbehalt und ohne
  Bestätigungsschritt.
- Fehlt er in `lsg_ak`, steht daneben der Hinweis *„Die Altersklasse m80 fehlt
  in `lsg_ak` – bis sie ergänzt ist, lässt sich im Frontend nicht danach
  filtern."* Das ist ein Hinweis auf eine Lücke in den Stammdaten, keine
  Warnung vor dem eigenen Ergebnis.
- Die Lücke ist am 2026-09-01 für die fünf tatsächlich vorkommenden Codes
  geschlossen (Vorarbeit **V2**, Abschnitt 8). Offen bleibt, `lsg_ak`
  großzügig bis `m95`/`w95` durchzuschreiben – sonst läuft die Tabelle dem
  Bestand in ein paar Jahren wieder hinterher.

#### 6.5.4 P4 – Gegen `lsg_best` abgleichen

**Der Bezugsrahmen ist immer ein Jahr: `lsg_best` hält Jahresbestleistungen.**
Eine Zeile dort ist die beste Leistung *eines Athleten* auf *einer Distanz* in
*einem Kalenderjahr* – nicht ein Wettkampfergebnis. Jeder Vergleich in P4
findet innerhalb dieses Rahmens statt, und alles Weitere folgt daraus.

„Ein Jahr" heißt **Kalenderjahr**, 1. Januar bis 31. Dezember, nicht die
letzten 365 Tage. Das ist keine freie Wahl: der Bestand und die
Frontend-Blöcke rechnen längst so (`lsg_bl_get_best_rows()` filtert nach
Kalenderjahr – nur eben zeitzonenabhängig, siehe unten), und ein rollierendes
Fenster würde die Jahres-Bestenliste in sich widersprüchlich machen.

Für jede zugeordnete Zeile wird geprüft, ob für **denselben Athleten, dieselbe
Distanz und dasselbe Jahr** bereits ein Eintrag existiert:

```sql
SELECT id, time, town, date
  FROM lsg_best
 WHERE athletes_id = %d
   AND distance    = %s
   AND `date`     >= %d          -- 1. Januar 00:00 Ortszeit
   AND `date`      < %d          -- 1. Januar 00:00 Ortszeit des Folgejahrs
```

Das Jahr kommt aus dem **Veranstaltungsdatum** (6.5.1), nicht aus `date('Y')`
und nicht aus dem Importzeitpunkt – ein im Januar nachgetragener Dezemberlauf
gehört ins Vorjahr, ein Silvesterlauf am 31.12. in das Jahr, in dem er
stattgefunden hat.

⚠ **Entschieden: das Jahr wird in PHP zu einer Zeitspanne aufgelöst, nicht in
SQL aus dem Timestamp gerechnet.** Ein früherer Entwurf schrieb hier
`YEAR(FROM_UNIXTIME(\`date\`)) = %d`, wie es die Frontend-Abfragen heute noch
tun. Das ist zeitzonenabhängig: `FROM_UNIXTIME()` rechnet mit der
MySQL-Session-Zeitzone, und die ist nicht die Zeitzone der WordPress-
Installation. Der Bestand speichert `date` als **00:00 Ortszeit** des
Wettkampftags (5 949 von 5 951 Zeilen, geprüft 2026-09-01) – steht die
Session auf UTC, wird daraus der Vortag, und bei einem Lauf am 1. Januar das
**Vorjahr**.

Im Bestand liegen genau sechs solche Zeilen: ids 1073, 1532, 1535, 3356, 3396,
3972 – alle Neujahrsläufe. Die 52 Silvesterläufe am 31.12. sind unkritisch,
dort bleibt die Verschiebung innerhalb des Jahres. `lsg_win` ist nicht
betroffen, dort gibt es keinen Lauf am 1. Januar (Stand 2026-09-01) – die
Abfrage wird trotzdem mit umgestellt, weil sonst dieselbe Falle offen bleibt.

Die Grenzen kommen aus einer einzigen Funktion:

```php
/**
 * Kalenderjahr → Zeitspanne [von, bis) in Unix-Timestamps, in der Zeitzone
 * der Installation. Für jede Jahresabfrage auf lsg_best und lsg_win.
 */
function lsg_bl_jahr_grenzen( int $jahr ): array {
    $tz = wp_timezone();
    return array(
        ( new DateTimeImmutable( sprintf( '%04d-01-01 00:00:00', $jahr ),     $tz ) )->getTimestamp(),
        ( new DateTimeImmutable( sprintf( '%04d-01-01 00:00:00', $jahr + 1 ), $tz ) )->getTimestamp(),
    );
}
```

⚠ **Nicht `mktime()`.** WordPress setzt die PHP-Zeitzone auf UTC
(`date_default_timezone_set( 'UTC' )` im Core), also liefert `mktime( 0, 0, 0,
1, 1, $jahr )` den 1. Januar 00:00 **UTC** – und eine Zeile, die auf 00:00
Ortszeit liegt, fällt davor. Genau der Fehler, den die Umstellung beheben soll,
wäre damit nur von SQL nach PHP verschoben. `wp_timezone()` liefert die
Zeitzone der Installation, `DateTimeImmutable` rechnet damit.

⚠ Dieselbe Korrektur betrifft das **Speichern** in 6.5.1 und 7.3: Auch dort ist
`mktime( 12, 0, 0, … )` unter WordPress 12:00 UTC, nicht 12:00 Ortszeit. Der
Wert ist zwar harmlos – 12:00 UTC liegt in Mitteleuropa am selben Tag –, aber
er ist nicht das, was dort steht. Also auch beim Schreiben
`new DateTimeImmutable( 'JJJJ-MM-TT 12:00:00', wp_timezone() )`.

⚠ **Die fünf Frontend-Abfragen werden mit umgestellt**, nicht nur die des
Imports: `lsg_bl_get_best_years()`, `lsg_bl_get_win_years()`,
`lsg_bl_get_best_rows()`, `lsg_bl_get_distances_present()` und
`lsg_bl_get_win_rows()` benutzen alle `YEAR(FROM_UNIXTIME())`. Bliebe eines
davon stehen, könnte die Bestenliste einen Lauf in einem anderen Jahr zeigen,
als der Import ihn verglichen hat – ein Widerspruch, den niemand aufklären
kann. Die beiden `SELECT DISTINCT YEAR(...)`-Abfragen für die Jahres-Dropdowns
lesen nur einen Timestamp und dürfen ihn in PHP über
`lsg_bl_year_from_timestamp()` in ein Jahr umrechnen – die Funktion gibt es
schon und benutzt `date_i18n()`, also die richtige Zeitzone.

Nebenbei ist die Zeitspanne auch die schnellere Form: `YEAR(FROM_UNIXTIME(x))`
ist ein Funktionsaufruf auf der Spalte und schließt jeden Index aus, ein
`BETWEEN`-Vergleich nicht.

⚠ **Die Abfrage darf genau eine oder keine Zeile liefern – aber sie kann
mehr.** Der Bestand hielt sich an diese Regel nicht durchgehend: am 2026-09-01
gab es 26 Kombinationen aus Athlet, Distanz und Jahr mit zwei Zeilen, davon
elf aus 2024 bis 2026. Das sind Erfassungsfehler und keine zweite Lesart der
Tabelle; sie sind am 2026-09-01 bereinigt (Vorarbeit **V1**, Abschnitt 8).

Trotzdem braucht P4 eine Regel für den Fall, sonst entscheidet die
Sortierreihenfolge der Datenbank, welche Zeile überschrieben wird – und das
fällt niemandem auf:

```
1 Zeile   → Normalfall, wie unten beschrieben
0 Zeilen  → Status 'neu'
> 1 Zeile → Bezug ist die beste der gefundenen Zeilen (lsg_bl_parse_performance()).
            Der Status wird dagegen gebildet, geschrieben wird ausschließlich
            in diese eine Zeile, die übrigen bleiben unangetastet.
            Zusätzlich: Statusspalte bekommt den Zusatz
            „Doppelzeile im Bestand (ids #…) – bitte bereinigen",
            und der Vorgang zählt sie in lsg_import_run.note mit.
```

Kein stilles `LIMIT 1`, kein automatisches Aufräumen im Import: Der Import
meldet den kaputten Bestand, er repariert ihn nicht. Nach V1 sollte dieser
Zweig nie mehr greifen – genau deshalb ist er die Stelle, an der man es merken
will, wenn er es doch tut. Dieselbe Regel gilt im Formular (7.3).

Was aus dem Jahresbezug folgt – die vier Fälle, die in der Praxis vorkommen:

| Fall | Ergebnis |
|---|---|
| Erste Zeit des Athleten auf dieser Distanz in diesem Jahr | neue Zeile (`neu`) |
| Schneller als die bisherige Jahresbestzeit | dieselbe Zeile wird überschrieben (`schneller`) |
| Langsamer | nichts passiert; die Jahresbestzeit bleibt (`langsamer`) |
| Gleiche Distanz, **anderes Jahr** | neue Zeile – das Vorjahr wird nie angefasst |

Der letzte Fall ist der wichtige: **über Jahresgrenzen hinweg wird nichts
überschrieben.** Läuft jemand 2027 einen schnelleren Halbmarathon als 2026,
entstehen zwei Zeilen, und die Jahres-Bestenliste 2026 bleibt so, wie sie war.
Die Ewige Bestenliste sucht sich daraus ohnehin die beste Leistung über alle
Jahre (`lsg_bl_dedupe_rows_by_athlete()`) – dafür braucht es keine zweite
Datenhaltung.

Umgekehrt heißt es auch: **`lsg_best` ist keine Wettkampfhistorie.** Wer im
selben Jahr fünf Halbmarathons läuft, hinterlässt dort eine Zeile. Die anderen
vier stehen nur im Import-Log (6.8) – so gewollt (9.1, kein Ergebnisarchiv).

⚠ Deshalb hängt so viel am Datumsfeld: Ein Datum, das versehentlich im
Nachbarjahr liegt, erzeugt keinen sichtbaren Fehler, sondern eine zweite
Jahresbestzeit in einem Jahr, in dem der Athlet vielleicht gar nicht gelaufen
ist. Genau darum ist das Feld Pflicht, sichtbar und mit Herkunftsangabe
versehen (6.5.1).

Verglichen wird über `lsg_bl_parse_performance()`, nicht über `strcmp` – die
Funktion kennt bereits die Formatvarianten des Bestands, auch die
Tippfehler-Schreibweisen (`01:20.24`) und die fehlende Stundenangabe
(`38:57`). Ein String-Vergleich würde bei `38:57` gegen `01:38:57` falsch
liegen.

Der Zweig für Zeitläufe (`better => 'higher'`) wird dabei nie erreicht: Die
Distanzen `6h`, `12h` und `24h` stehen gar nicht erst im Import-Select
(6.5.1). Alles, was P4 zu sehen bekommt, ist eine Zeit, bei der kleiner besser
ist.

Daraus ergibt sich der Status je Zeile:

| Status | Bedeutung | Anzeige | Vorauswahl |
|---|---|---|---|
| `neu` | noch keine Zeit für Jahr + Distanz | „Noch keine Zeit in der Datenbank vorhanden" | ✓ |
| `schneller` | neue Leistung besser als der Bestand | „Neue Zeit ist schneller (01:38:12 → 01:36:44)" | ✓ |
| `langsamer` | neue Leistung schlechter | „Neue Zeit ist langsamer (01:36:44 bleibt)" | ✗ |
| `gleich` | identische Leistung | „Zeit bereits vorhanden" | ✗ |
| `offen` | kein Athlet zugeordnet (aus P3) | „Keine Zuordnung möglich – wird nicht importiert" | keine Checkbox |
| `mehrdeutig` | mehrere Regeln treffen (aus P3) | „Keine Zuordnung möglich – Regeln #… treffen beide zu" | keine Checkbox |

`gleich` ist der Wiederholungsfall: derselbe Import ein zweites Mal ausgeführt
zeigt lauter `gleich` und schreibt bei „Übernehmen" nichts. Das ist die
Idempotenz aus Abschnitt 8 – sichtbar gemacht, statt nur behauptet.

Bei mehreren Zeilen desselben Athleten auf derselben Distanz im selben Import
(kommt vor: Staffel plus Einzellauf, oder zwei Listen nacheinander) gewinnt die
bessere Leistung; die schlechtere wird als `langsamer` mitgeführt und ist
abwählbar, nicht stillschweigend verworfen.

#### 6.5.5 Gesamtsieg erkennen (vorgemerkt, noch nicht umsetzen)

> **Status: geplant, nicht im ersten Wurf.** Der Punkt steht hier, damit die
> Datenstruktur ihn später aufnehmen kann, ohne dass eine Migration nötig wird.
> Die Erkennung und die Markierung kommen mit; das Schreiben nach `lsg_win`
> ausdrücklich noch nicht.

Hat ein LSG-Läufer den Wettbewerb **gewonnen**, ist das mehr als eine gute
Zeit – es gehört in die Gesamtsiege (`lsg_win`, Block „Gesamtsiege").

**Erkennung:** `platz` aus P1 ist `1` (bzw. `1.`). Mehr braucht es nicht.

⚠ Aber nur in der **Gesamtwertung**. In einer nach Geschlecht oder
Altersklasse gefilterten Liste ist Platz 1 kein Gesamtsieg, sondern ein
Klassensieg. Die Erkennung greift deshalb ausschließlich, wenn die gewählte
Ergebnisliste die Gesamtwertung ist:

```
Runtix         rlt = 'total'
race result    Liste ohne Klassen-/Geschlechtsfilter im Namen
               (bei Unklarheit: nicht erkennen, lieber nichts anbieten)
```

Bei jeder anderen Liste wird kein Gesamtsieg gemeldet – ein falsch gemeldeter
Sieg wäre deutlich ärgerlicher als ein übersehener.

**Was jetzt schon gebaut wird:**

- `platz` wird in P1 gelesen und in `lsg_import_log.roh_platz` protokolliert.
- Die Zeile bekommt in der Übernahme-Tabelle (6.6) eine Markierung
  **🏆 Gesamtsieg** neben dem Status, plus einen Hinweis über der Tabelle:
  *„1 Gesamtsieg erkannt – Eintrag in die Gesamtsiege bitte noch von Hand."*
- Mehr nicht. Kein Schreibvorgang, keine Checkbox, kein `lsg_win`-Eintrag.

**Was später dazukommt** – und was dafür noch fehlt:

`lsg_win` hat eine Spalte, die es in `lsg_best` nicht gibt:

```
lsg_win: date, town, event (varchar 40), distance, athletes_id, time
                      ^^^^^ Name der Veranstaltung
```

`event` ist beim Import vorhanden (Adapter-Metadaten, `event_name`), aber
`varchar(40)` ist knapp – „17. SWE Halbmarathon Ettlingen" passt, längere
Namen nicht. Beim Umsetzen also: kürzbares Feld in der Oberfläche, nicht
stillschweigend abschneiden.

Offene Punkte für die spätere Umsetzung:

- Eigene Checkbox-Spalte „auch als Gesamtsieg übernehmen", oder ein separater
  Arbeitsschritt nach dem Bestzeiten-Import?
- Dublettenprüfung in `lsg_win` (Athlet + Datum + Veranstaltung) analog zu P4.
- Zählt ein Sieg in einer kleinen Klasse mit? → Nein, per Definition oben:
  nur die Gesamtwertung.
- Log-Aktion `win_insert` ergänzen (der Wertebereich in 6.8 hat Platz dafür).

### 6.6 Die Übernahme-Oberfläche

Ergebnis von P1–P4 ist eine Tabelle
(`wp-list-table widefat fixed striped`), eine Zeile je Ergebnis:

```
[ ✓ ]  Nachname, Vorname   Jg    AK    Zeit       Bestand     Status
[ ✓ ]  Körner, Holger      1993  m30   01:11:55   –           Noch keine Zeit vorhanden  🏆
[ ✓ ]  Häffner, Anna       1988  w35   01:36:44   01:38:12    Neue Zeit ist schneller
[   ]  Schmidt, Peter      1975  m50   01:52:03   01:47:30    Neue Zeit ist langsamer
[ – ]  Weber, Klaus        1969  –     00:44:12   –           Keine Zuordnung möglich – wird nicht importiert
```

- **Checkbox je Zeile** – außer bei `offen`/`mehrdeutig`: dort steht keine,
  weil es kein Ziel zum Schreiben gibt. Vorausgewählt sind `neu` und
  `schneller`; `langsamer` und `gleich` sind leer. Die Vorauswahl ist eine
  Bequemlichkeit, keine Sperre – jede Zeile bleibt frei wählbar. Auch eine
  `langsamer`-Zeile darf angehakt werden; sie wird dann bewusst als
  „geprüft, nicht übernommen" protokolliert.
- **Checkbox „Alle"** im Tabellenkopf, wie in WordPress üblich
  (`#cb-select-all-1`): schaltet alle Zeilen um, die überhaupt eine Checkbox
  haben. `offen` und `mehrdeutig` sind damit automatisch außen vor. Ohne JavaScript ist die Kopf-Checkbox schlicht nicht da;
  die Einzel-Checkboxen funktionieren als normale Formularfelder weiter.
- **Statusspalte** in Klartext, mit alter und neuer Zeit im Vergleich. Nicht
  nur ein Icon: was gleich passiert, muss lesbar dastehen.
- **Gesamtsieg-Markierung** 🏆 an Zeilen mit Platz 1 in der Gesamtwertung
  (6.5.5) – vorerst nur als Hinweis, ohne Wirkung auf die Übernahme.
- **Filter/Sortierung** über der Tabelle nach Status, damit man bei 40 Zeilen
  die drei `offen`-Fälle findet.
- **Nicht zugeordnete Teilnehmer stehen mit in der Tabelle** (6.5.3) – ohne
  Checkbox, mit Grund im Klartext und den Rohdaten der Quelle. Sie werden nicht
  importiert, aber auch nie weggelassen: die Zeilenzahl der Tabelle entspricht
  immer der LSG-Zahl aus dem Trichter.
- **Button „Übernehmen"** unter der Tabelle, mit der Anzahl der ausgewählten
  Zeilen im Label: *„3 Ergebnisse übernehmen"*. Bei 0 ausgewählten Zeilen
  deaktiviert.

Nach dem Übernehmen bleibt die Tabelle stehen, jede Zeile bekommt aber ihr
Resultat angeheftet (`angelegt`, `aktualisiert`, `übersprungen`, `Fehler`),
plus eine Bilanz als `notice notice-success` und ein Link ins Log (6.8).

### 6.7 Was beim Übernehmen passiert

Für jede **angehakte** Zeile:

```
Status neu         →  INSERT in lsg_best
                      (distance, time, town, date, athletes_id, ak, tstamp)

Status schneller   →  UPDATE lsg_best SET time, town, date, ak, tstamp
                      WHERE id = <gefundene Zeile>
                      Die alte Zeit steht danach im Log (time_old).

Status langsamer   →  nichts schreiben. Der Bestand bleibt.
                      Wird trotzdem als 'skip_langsamer' protokolliert.

Status gleich      →  nichts schreiben, protokolliert als 'skip_gleich'.

Status offen       →  hat keine Checkbox, wird nie geschrieben.
Status mehrdeutig  →  desgleichen.
```

Nicht angehakte Zeilen erzeugen `skip_abgewaehlt` im Log – auch das ist eine
Information: man sieht später, dass ein Ergebnis gesehen und bewusst nicht
übernommen wurde, statt zu rätseln, ob es je da war.

⚠ Eine angehakte `langsamer`-Zeile schreibt hier **nichts** – anders als im
Formular aus 7.3, wo sich ein falscher Bestand nach ausdrücklicher Bestätigung
auch mit einer langsameren Leistung ersetzen lässt. Der Unterschied ist
gewollt: Im Import stehen vierzig Zeilen zur Auswahl, und ein versehentlich
gesetzter Haken darf keine Bestzeit verschlechtern. Wer einen falschen Bestand
korrigieren will, tut das dort, wo eine Leistung einzeln vor Augen steht – die
Meldung an der Zeile sagt das auch so: *„Der Bestand bleibt. Korrektur unter
‚Bestenliste'."*

Umsetzungsdetails:

- Der Statusvergleich aus P4 wird **unmittelbar vor dem Schreiben wiederholt**.
  Zwischen Parsen und Übernehmen liegt eine Benutzerentscheidung, in der eine
  zweite Person denselben Import gemacht haben kann. Weicht der Status ab, wird
  die Zeile nicht geschrieben, sondern als `konflikt` gemeldet.

  ⚠ „Abweichung" heißt: von **außen** geändert. Innerhalb eines Vorgangs ändert
  sich der Status planmäßig – hakt jemand zwei Zeilen desselben Athleten auf
  derselben Distanz an (6.5.4, Staffel plus Einzellauf), steht die zweite nach
  dem Schreiben der ersten zwangsläufig auf `langsamer` oder `gleich`. Das ist
  kein Konflikt, sondern das erwartete Ergebnis, und wird als `skip_langsamer`
  bzw. `skip_gleich` protokolliert. Verglichen wird deshalb gegen den Stand zu
  Beginn des Schreibvorgangs **plus die eigenen Schreibvorgänge**, nicht gegen
  die Datenbank von vorhin. Ohne diese Unterscheidung meldet der erste Import
  mit einer Staffel einen Konflikt, den es nicht gibt.
- Alle Schreibvorgänge eines Klicks laufen in **einer Transaktion**
  (`START TRANSACTION` / `COMMIT`), damit ein Fehler in der Mitte keinen halben
  Import hinterlässt. Voraussetzung InnoDB – bei MyISAM greift das nicht, dann
  ist das Log der Rettungsanker.
- `tstamp` wird wie im Bestand auf `time()` gesetzt.
- Geschrieben wird ausschließlich über `$wpdb->insert()` / `$wpdb->update()`
  mit Formatangaben, Tabellennamen immer über `lsg_bl_table()`.

### 6.8 Import-Log

Alles, was die Übernahme tut *und bewusst nicht tut*, wird protokolliert. Das
Log ist die Antwort auf „warum steht bei X diese Zeit" – Monate später, wenn
niemand mehr weiß, welche Liste importiert wurde.

**Vorschlag: zwei Tabellen.** Ein Import-Vorgang (der Klick) und seine
Einzelzeilen sind unterschiedliche Dinge; in einer Tabelle würde man die
Vorgangs-Metadaten (URL, Event, Distanz, Benutzer) auf jeder der 40 Zeilen
wiederholen.

```php
// Der Vorgang: ein Datensatz je Klick auf „Übernehmen"
$sql = "CREATE TABLE {$t_run} (
  id            int UNSIGNED NOT NULL AUTO_INCREMENT,
  tstamp        int UNSIGNED NOT NULL DEFAULT 0,       -- Konvention wie lsg_best
  user_id       bigint UNSIGNED NOT NULL DEFAULT 0,
  adapter       varchar(32)  NOT NULL DEFAULT '',      -- 'raceresult' | 'runtix'
  source_url    varchar(255) NOT NULL DEFAULT '',
  event_id      varchar(32)  NOT NULL DEFAULT '',
  event_name    varchar(120) NOT NULL DEFAULT '',
  event_date    int UNSIGNED DEFAULT NULL,             -- = lsg_best.date
  datum_quelle  varchar(16)  NOT NULL DEFAULT '',      -- liste|ausschreibung|api|name|jahr|manuell
  jahr          smallint UNSIGNED NOT NULL DEFAULT 0,  -- Vergleichsjahr aus 6.5.4
  contest_id    varchar(32)  NOT NULL DEFAULT '',      -- String! ("w")
  contest_name  varchar(120) NOT NULL DEFAULT '',
  list_id       varchar(64)  NOT NULL DEFAULT '',
  list_name     varchar(120) NOT NULL DEFAULT '',
  distance      varchar(15)  NOT NULL DEFAULT '',      -- kanonischer Code
  town          varchar(30)  NOT NULL DEFAULT '',
  zeit_typ      varchar(8)   NOT NULL DEFAULT '',      -- 'netto' | 'brutto'
  cnt_gelesen   int UNSIGNED NOT NULL DEFAULT 0,       -- P1
  cnt_lsg       int UNSIGNED NOT NULL DEFAULT 0,       -- P2
  cnt_zugeordnet int UNSIGNED NOT NULL DEFAULT 0,      -- P3
  cnt_angelegt  int UNSIGNED NOT NULL DEFAULT 0,
  cnt_aktualisiert int UNSIGNED NOT NULL DEFAULT 0,
  cnt_uebersprungen int UNSIGNED NOT NULL DEFAULT 0,
  cnt_fehler    int UNSIGNED NOT NULL DEFAULT 0,
  status        varchar(16)  NOT NULL DEFAULT '',      -- 'uebernommen'|'fehler'|'abgebrochen'
  note          text         NULL,
  PRIMARY KEY  (id),
  KEY zeit (tstamp),
  KEY event (event_id, contest_id)
) {$charset_collate};";

// Die Zeilen: ein Datensatz je Ergebnis, auch für nicht geschriebene
$sql .= "CREATE TABLE {$t_log} (
  id            int UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id        int UNSIGNED NOT NULL,
  tstamp        int UNSIGNED NOT NULL DEFAULT 0,
  athletes_id   int UNSIGNED NOT NULL DEFAULT 0,       -- 0 = nicht zugeordnet
  best_id       int UNSIGNED NOT NULL DEFAULT 0,       -- betroffene Zeile in lsg_best
  match_type    varchar(16) NOT NULL DEFAULT '',       -- exakt|regel|normalisiert|mehrdeutig|offen
  aktion        varchar(20) NOT NULL DEFAULT '',       -- s.u.
  distance      varchar(15) NOT NULL DEFAULT '',
  ak            varchar(10) NOT NULL DEFAULT '',
  time_neu      varchar(15) NOT NULL DEFAULT '',
  time_alt      varchar(15) NOT NULL DEFAULT '',       -- leer bei INSERT
  -- Rohdaten der Quelle, unverändert: macht das Log ohne die Quelle lesbar
  roh_teilnehmer varchar(120) NOT NULL DEFAULT '',
  roh_name      varchar(30) NOT NULL DEFAULT '',
  roh_vorname   varchar(30) NOT NULL DEFAULT '',
  roh_verein    varchar(60) NOT NULL DEFAULT '',
  roh_jahrgang  year        NULL DEFAULT NULL,
  roh_zeit      varchar(20) NOT NULL DEFAULT '',
  roh_startnr   varchar(16) NOT NULL DEFAULT '',
  roh_platz     varchar(8)  NOT NULL DEFAULT '',       -- Gesamtplatz, für 6.5.5
  gesamtsieg    tinyint(1)  NOT NULL DEFAULT 0,        -- erkannt, noch ohne Wirkung
  meldung       varchar(255) NOT NULL DEFAULT '',      -- Klartext, wie in der UI
  PRIMARY KEY  (id),
  KEY run (run_id),
  KEY athlet (athletes_id, distance),
  KEY aktion (aktion),
  KEY suche (roh_name, roh_vorname)
) {$charset_collate};";
```

⚠ Kein `FOREIGN KEY` von `run_id` auf `lsg_import_run`: Der Bestand liegt auf
MyISAM-Tabellen aus phpMyAdmin-Dumps, `dbDelta()` verwaltet keine Constraints,
und ein Log, das beim Aufräumen an einem Fremdschlüssel scheitert, hilft
niemandem. Der `KEY run (run_id)` reicht für den Join.

⚠ `roh_jahrgang` ist `NULL`, nicht `0000`: „die Quelle nannte keinen Jahrgang"
ist einer der drei `offen`-Gründe aus 6.5.3 und muss im Log von „Jahrgang 0"
unterscheidbar bleiben.

⚠ **Diese Tabellen entstehen nicht durch die Aktivierung.** `lsg_bl_activate()`
hängt an `register_activation_hook()` und läuft auf einer Installation, auf der
das Plugin bereits aktiv ist, kein zweites Mal. Die drei neuen Tabellen kämen
dort also nie an – und der Fehler zeigt sich erst beim ersten Import, als
„Table doesn't exist". Es braucht eine Schema-Version:

```php
define( 'LSG_BL_DB_VERSION', 2 );

add_action( 'admin_init', function () {
    if ( (int) get_option( 'lsg_bl_db_version' ) === LSG_BL_DB_VERSION ) {
        return;
    }
    lsg_bl_install_schema();          // dieselbe Funktion wie bei der Aktivierung
    update_option( 'lsg_bl_db_version', LSG_BL_DB_VERSION );
} );
```

Der Aktivierungs-Hook ruft dieselbe `lsg_bl_install_schema()` auf: eine
Definition der Tabellen, zwei Einstiegspunkte. `dbDelta()` ist idempotent, ein
überflüssiger Durchlauf kostet nichts.

⚠ **`lsg_bl_install_schema()` enthält nur die drei neuen Tabellen** –
`lsg_athlete_map`, `lsg_import_run`, `lsg_import_log`. Die vier Bestands-
tabellen bleiben, wo sie sind: in `lsg_bl_activate()`, das nur bei einer
frischen Installation etwas tut. Der Grund steht im nächsten Absatz: Ihre
Definitionen in `lsg-bestenliste.php` schreiben `int(10) UNSIGNED`,
`year(4)`, `varchar(1)`. Liefe das ab jetzt bei jedem Versionssprung durch
`dbDelta()`, bekämen vier Tabellen mit 6 000 Zeilen Vereinsgeschichte bei
jedem Durchlauf überflüssige `ALTER TABLE`s. Zwei Funktionen also, nicht eine:

```php
function lsg_bl_activate() {
    lsg_bl_install_schema();          // die drei neuen Tabellen
    lsg_bl_install_legacy_schema();   // die vier Bestandstabellen, nur hier
    update_option( 'lsg_bl_db_version', LSG_BL_DB_VERSION );
}
```

Wer die alten Definitionen später doch mitverwalten will, zieht sie vorher auf
dieselbe Schreibweise nach (`int UNSIGNED`, `year`) – dann, und erst dann,
dürfen sie in `lsg_bl_install_schema()`.

⚠ Zwei `dbDelta()`-Eigenheiten, die in den `CREATE TABLE`s oben bereits
berücksichtigt sind und beim Erweitern berücksichtigt bleiben müssen:

- **Anzeigebreiten weglassen.** `int(10) UNSIGNED` und `year(4)` gibt MariaDB
  10.11 noch so zurück, MySQL 8.0.19+ normalisiert sie zu `int unsigned` bzw.
  `year`. `dbDelta()` vergleicht Strings – die Tabelle gilt dann bei *jedem*
  Aufruf als geändert und bekommt endlos `ALTER TABLE`s. Deshalb überall
  `int UNSIGNED`, `bigint UNSIGNED`, `smallint UNSIGNED`, `year`. Einzige
  Ausnahme: `tinyint(1)`, das MySQL als eigenen Typ führt und nicht
  normalisiert – die Breite bleibt dort stehen.
- **Zwei Leerzeichen nach `PRIMARY KEY`**, ein `KEY` je Zeile, Feldtypen klein.
  Die Formatvorgaben von `dbDelta()` sind wörtlich zu nehmen, sonst legt es
  Indizes bei jedem Lauf neu an.
- Tabellennamen immer über `lsg_bl_table()`, Kollation immer über
  `$wpdb->get_charset_collate()` – sonst legt das Plugin auf einer Installation
  mit WordPress-Präfix (`LSG_BL_USE_WP_PREFIX`) Tabellen an, die kein anderer
  Teil des Plugins findet.

Wertebereich `aktion` – bewusst auch die Nicht-Aktionen:

```
insert             neue Zeile in lsg_best angelegt
update             vorhandene Zeile überschrieben (time_alt gefüllt)
skip_langsamer     angehakt, aber Bestand war besser
skip_gleich        identische Leistung, nichts zu tun
skip_abgewaehlt    Checkbox war leer
skip_offen         kein Athlet zugeordnet
konflikt           Status hatte sich seit dem Parsen geändert
fehler             DB-Fehler, Details in meldung
delete             Zeile aus lsg_best entfernt          ← manuelle Seite (7.5)
win_insert         Gesamtsieg nach lsg_win geschrieben  ← reserviert (6.5.5)
```

`match_type` kennt zusätzlich `manuell`: Der Athlet wurde im Formular gewählt,
nicht über Name, Regel oder Normalisierung zugeordnet (7.5).

`roh_platz`, `gesamtsieg` und `win_insert` werden **jetzt schon angelegt**,
obwohl der Gesamtsieg-Teil noch nicht umgesetzt wird. Eine leere Spalte kostet
nichts; eine `ALTER TABLE` auf einer Log-Tabelle mit Produktivdaten kostet
Nerven.

Warum die Rohfelder mitgespeichert werden, obwohl sie redundant wirken: das Log
soll auch dann noch verständlich sein, wenn die Quelle offline ist, der Athlet
umbenannt oder eine Zuordnungsregel korrigiert wurde. Ein Log, das nur IDs enthält,
ist genau dann wertlos, wenn man es braucht.

**Entschieden: kein zusätzliches Ergebnisarchiv.** `lsg_import_run` und
`lsg_import_log` reichen. `lsg_best` bleibt damit das, was es ist – die beste
Zeit je Athlet, Jahr und Distanz – und das Log ist die Historie dazu.

Bewusste Konsequenz: protokolliert werden nur die Zeilen, die P2 passiert
haben. Die 420 Nicht-LSG-Ergebnisse eines Laufs landen **nirgends**; wer sie
später braucht, ruft die Quelle erneut ab. Was von einem gelesenen Lauf dauerhaft
bleibt, ist also: die LSG-Zeilen im Log, die Bestzeiten in `lsg_best`, und in
`lsg_import_run` die Quell-URL, mit der sich der Rest jederzeit neu holen lässt.

**Alternative (nicht empfohlen):** eine einzige Tabelle mit einer
JSON-Spalte für die Rohdaten. Spart die Join-Tabelle, kostet aber die Suche –
`LIKE` auf JSON ist weder indizierbar noch nachvollziehbar, und
`lsg_bl_table()`-Konsistenz hilft dabei nicht. Bei erwarteten Größenordnungen
(einige hundert Zeilen pro Jahr) ist die Zweitabellen-Lösung in jeder Hinsicht
billiger.

**Log-Ansicht** als weiteres Untermenü unter `lsg-bestenliste`:

- `WP_List_Table` mit Paginierung, Standardsortierung `tstamp DESC`.
- **Suchfeld** über `roh_name`, `roh_vorname`, `roh_verein` und den
  Athletennamen aus `lsg_athlete` (Join).
- Filter: Vorgang (Dropdown der letzten Läufe), Aktion, Distanz, Jahr, Benutzer.
- Zwei Ebenen: Übersicht der Vorgänge (`lsg_import_run`) → Klick öffnet die
  Zeilen dieses Vorgangs. Direkter Einstieg in die Zeilensuche über das Suchfeld.
- Spalte „Ergebnis" verlinkt bei `insert`/`update` auf den betroffenen
  `lsg_best`-Datensatz – die Bearbeitungsansicht dafür ist die Seite aus
  Abschnitt 7 (`&action=edit&id=`). Bei `delete` gibt es kein Ziel mehr; dort
  steht der protokollierte Datensatz selbst.
- Aufbewahrung: unbegrenzt. Bei wenigen hundert Zeilen pro Jahr ist Aufräumen
  unnötiger Aufwand; falls doch, ein `Löschen älter als …`-Knopf statt eines
  automatischen Cron-Jobs, der unbemerkt Historie wegwirft.

Ein **Rückgängig** ist damit prinzipiell möglich (`time_alt` steht ja da), aber
bewusst nicht Teil des ersten Wurfs – siehe 9.2.

### 6.9 Seitenzustand, Formular und Sicherheit

- **Kein persistenter Zustand am Beitrag.** Der Stand des Assistenten steht in
  der Query (`?page=lsg-bestenliste&url=…&contest=…&list=…`) und in Hidden-Feldern.
  Vorteil: Browser-Zurück funktioniert, ein Zwischenstand ist verlinkbar, und
  ein abgebrochener Import hinterlässt nichts außer einem ablaufenden Transient.
- **Progressive Enhancement**, wie bei den Frontend-Blöcken: die drei Schritte
  funktionieren als normale Formular-Roundtrips über `admin-post.php`. Ein
  kleines `assets/js/admin-import.js` macht daraus per `fetch` gegen die
  REST-Routen (6.10) einen Ablauf ohne Reload – ohne JS bleibt die Seite
  vollständig bedienbar. Kein Build-Schritt, konsistent zum restlichen Plugin.
- **Nonces:** `wp_nonce_field( 'lsg_bl_import' )` im Formular,
  `check_admin_referer()` im Handler; für die REST-Variante `X-WP-Nonce`.
- **Capability-Prüfung in jedem Handler**, nicht nur beim Rendern des Menüs –
  `add_menu_page` versteckt den Eintrag, schützt aber keinen Endpunkt.
- Assets nur auf dieser Seite laden (`admin_enqueue_scripts` mit `$hook`-Vergleich).

### 6.10 REST-Routen

Alle unter `lsg/v1/import/`, alle mit
`permission_callback => fn() => current_user_can( LSG_BL_CAP )` (= `read`,
also jeder angemeldete Benutzer, siehe 6.2).

**Nicht** `__return_true` wie die Frontend-Routen. Auch wenn praktisch jeder
angemeldete Benutzer durchkommt, ist der Unterschied wesentlich: hier werden
fremde URLs serverseitig abgerufen, und ohne Prüfung wäre das ein offener
SSRF-Proxy für nicht angemeldete Besucher.

| Route | Methode | Parameter | Antwort |
|---|---|---|---|
| `/import/erkennen` | POST | `url` | `{ adapter, label, eventId, eventName, contestId?, listId? }` |
| `/import/wettbewerbe` | GET | `adapter`, `eventId` | `{ contests:[{id,name}] }` |
| `/import/listen` | GET | `adapter`, `eventId`, `contestId` | `{ lists:[{id,name,live}] }` |
| `/import/parsen` | POST | `adapter`, `eventId`, `contestId`, `listId?`, `distanz`, `ort`, `datum` | `{ token, meta, trichter, zeilen[], warnungen[] }` |
| `/import/uebernehmen` | POST | `token`, `zeilen[]` (Indizes der angehakten) | `{ run_id, angelegt, aktualisiert, uebersprungen, konflikte, ergebnisse[] }` |

`trichter` ist das Zählwerk aus 6.5: `{ gelesen, lsg, zugeordnet, neu,
schneller, langsamer, gleich, offen }`.

Eine Route zum Zuordnen einzelner Zeilen gibt es nicht: der Import legt keine
Athleten an und schreibt keine Regeln (6.5.3). Wer eine offene Zeile auflösen
will, pflegt Athlet oder Regel im jeweiligen Untermenü und führt den Import
erneut aus.

Die Formular-Handler an `admin-post.php` rufen dieselben Funktionen auf – die
REST-Schicht ist nur ein zweiter Eingang, keine zweite Implementierung.

**Was der Client nicht bestimmt** (ebenso wenig optional): `/import/uebernehmen`
bekommt `token` und die Auswahl – und zwar als **Zeilenindizes**, nicht als
Daten. Athlet, Zeit, Distanz, Datum und Status kommen ausschließlich aus dem
Parse-Transient, den der Server selbst geschrieben hat. Sonst wäre die Route
mit einer Capability, die jeder angemeldete Benutzer hat, ein freier
Schreibzugriff auf `lsg_best`: Man schickt eine beliebige `athletes_id` mit
einer beliebigen Zeit, ohne dass je eine Ergebnisliste im Spiel war.

Dazu gehört, dass **das `token` an die `user_id` gebunden** und beim Übernehmen
gegengeprüft wird. Ein Transient-Schlüssel, den ein zweiter Benutzer erraten
oder mitlesen kann, öffnet dieselbe Lücke wieder.

**SSRF-Absicherung** (nicht optional):

- Nur `http`/`https`, keine anderen Schemata.
- Host-Allowlist aus der Adapter-Registry: eine URL wird nur abgerufen, wenn
  ein Adapter sie mit Score > 0 beansprucht. Damit ist die Allowlist
  automatisch identisch mit der Menge der unterstützten Portale.
- `wp_safe_remote_get()` statt `wp_remote_get()` – blockt private IP-Bereiche.
- Redirects auf nicht beanspruchte Hosts abbrechen (`redirection` begrenzen
  und Ziel-Host erneut prüfen).
- Rate-Limit pro Benutzer: max. 30 Abrufe / 10 min (Transient-Zähler).

⚠ **Aufgefallen am 2026-09-02, bewusst so gelassen:** `http` auf einem
erlaubten Host ist zugelassen – `http://runtix.com/sts/10050/3152` läuft durch.
Das folgt aus der ersten Regel („nur http/https"), war so aber nirgends
ausgesprochen. Beide Adapter bauen ausschließlich `https`; betroffen ist nur
eine von Hand eingegebene oder von der Quelle weitergeleitete `http`-Adresse,
und die holt eine öffentliche Ergebnisliste ohne Anmeldung. Ein Angreifer in
der Leitung könnte dort Ergebniszeilen unterschieben – nicht mehr, aber auch
nicht weniger.

**Wer das zuschnüren will**, streicht `http` aus der Schema-Prüfung in
`lsg_bl_url_erlaubt()`. Das ist eine Zeile. Nicht getan wurde es, weil es eine
M1-Entscheidung nachträglich ändert und eine eingegebene `http`-URL dann ohne
erkennbaren Grund abgelehnt würde. Die Regel steht jetzt als Test fest
(`rendertest.php`, Abschnitt runtix-Allowlist) – sie kann also nicht mehr
unbemerkt kippen, in keine Richtung.

⚠ **Die Allowlist gilt für jede abgerufene Adresse, nicht nur für die
eingegebene.** Das ist bei race result kein Nebensatz, sondern die einzige
Stelle, an der die Absicherung sonst ins Leere liefe: Der zweite Request geht
nach 4.1 an den Host aus **`config.server`** – ein Wert, der aus der Antwort
eines Fremdservers stammt und laut 4.2 tatsächlich wechselt (`my4`,
`my-us-1`, …). Wer nur die Eingabe-URL prüft, hat eine Allowlist, die genau
den Request nicht abdeckt, der die Daten holt.

Deshalb: **jede** URL läuft durch dieselbe Funktion, bevor sie abgerufen wird –
die eingegebene, die aus `config.server` gebaute, und das Ziel jedes
Redirects. Der Adapter liefert dafür die Hosts, die er beansprucht:

```php
/** Hosts, die dieser Adapter abrufen darf. Wildcard nur als Suffix. */
public static function hosts(): array;   // z.B. array( '*.raceresult.com', 'raceresult.com' )

/**
 * Zentrale Torwächter-Funktion. Jeder Abruf im Plugin geht hier durch,
 * ohne Ausnahme – auch der zweite Request eines Adapters.
 */
function lsg_bl_url_erlaubt( string $url, string $adapter_cls ): bool {
    $teile = wp_parse_url( $url );
    if ( empty( $teile['scheme'] ) || ! in_array( $teile['scheme'], array( 'http', 'https' ), true ) ) {
        return false;
    }
    if ( empty( $teile['host'] ) ) {
        return false;
    }
    $host = strtolower( $teile['host'] );
    foreach ( $adapter_cls::hosts() as $muster ) {
        $muster = strtolower( $muster );
        if ( 0 === strpos( $muster, '*.' ) ) {
            $suffix = substr( $muster, 1 );            // '.raceresult.com'
            if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
                return true;
            }
        } elseif ( $host === $muster ) {
            return true;
        }
    }
    return false;
}
```

⚠ Das Suffix-Muster muss mit dem Punkt beginnen (`.raceresult.com`), sonst
passt auch `boeseraceresult.com`. Und die Prüfung läuft auf dem Host, nie auf
der ganzen URL – `https://angreifer.example/?x=my.raceresult.com` enthält den
erlaubten Namen, ist aber eine fremde Adresse.

Passt `config.server` nicht, wird **abgebrochen**, nicht auf
`my.raceresult.com` zurückgefallen: Ein Adapter, dessen Portal plötzlich auf
einen fremden Host verweist, hat ein Problem, das eine Meldung verdient und
keine stille Ersatzroute.

### 6.11 Zustände der Oberfläche

Ein Zustand, der nicht dargestellt ist, wird später als Bug gemeldet – daher
explizit:

```
leer            URL-Feld leer                    → nur Feld, Hinweistext
erkenne         Request läuft                    → Spinner am URL-Feld
unbekannt       kein Adapter                     → Notice + Liste der Portale
erkannt         Adapter steht, Wettbewerbe laden → Spinner am Select
bereit          Wettbewerb gewählt               → Parsen-Button aktiv
parse           Request läuft                    → Button disabled + .spinner.is-active
vorschau        P1–P4 durch                      → Trichter + Checkbox-Tabelle
uebernahme      Schreibvorgang läuft             → Button disabled + .spinner.is-active
gespeichert     Übernahme fertig                 → Bilanz + Link ins Log
teilfehler      einzelne Zeilen mit Konflikt     → Bilanz + markierte Zeilen
fehler          HTTP-/Parse-Fehler               → Notice mit Klartext + Wiederholen
```

`teilfehler` ist wichtig genug für einen eigenen Zustand: „7 von 9 übernommen"
darf nicht wie ein glatter Erfolg aussehen und auch nicht wie ein Totalausfall.

Darstellung mit den WordPress-Admin-Klassen (`notice notice-error`,
`.spinner.is-active`, `wp-list-table`) – kein eigenes UI-Framework, kein
zusätzliches CSS außer ein paar Zeilen für die Vorschautabelle.

### 6.12 Einen weiteren Adapter ergänzen

Die Schnittstelle ist so geschnitten, dass ein dritter Adapter genau drei
Berührungspunkte hat:

1. Klasse anlegen, `ErgebnisQuelle` implementieren.
2. In `lsg_bl_adapter_registry()` eintragen (oder per Filter von außen).
3. Fixture + Contract-Test ergänzen (Abschnitt 8, Verifikation).

An der Oberfläche ist **nichts** zu ändern: Adapterauswahl, Wettbewerbs- und
Listen-Select sind generisch. Liefert ein Adapter für `listen()` ein leeres
Array, blendet die Seite das zweite Feld von selbst aus.

**Eine dritte Quelle ist derzeit nicht angedacht** (9.1). Die Registry
bleibt trotzdem so, wie sie ist: sie kostet in dieser Form nichts – ein Array
und ein Filter – und erspart, falls doch einmal ein Portal dazukommt, das
Aufbrechen einer `if/else`-Kette, die sich quer durch Erkennung, Discovery und
Datenabruf zieht. Denkbare Kandidaten wären DLV / ladv.de, Davengo oder
Zeitmessung Barth; geplant ist keiner davon.

---

## 7. Backend-Oberfläche: Ergebnisse von Hand erfassen

Der Import deckt ab, was die Portale in einer parsebaren Liste liefern. Daneben
bleiben drei Fälle, die es nie in diese Oberfläche schaffen:

- **Zeitläufe** (`6h`, `12h`, `24h`) – vom Import ausgenommen, weil dort eine
  Strecke und keine Zeit gespeichert wird (6.5.1).
- **Läufe ohne brauchbare Quelle** – ein Veranstalter, der nur ein PDF
  veröffentlicht, ein Lauf im Ausland, eine Urkunde als einziger Beleg.
- **Korrekturen** – ein Tippfehler im Bestand, eine falsch zugeordnete Zeile,
  ein Eintrag, der gar nicht hätte entstehen dürfen.

Dafür bekommt das Plugin eine zweite Admin-Seite. **Sie beschränkt sich nicht
auf die Zeitläufe, sondern erfasst alle Distanzen** – die Felder sind dieselben,
das Ziel ist dieselbe Tabelle, und eine Korrektur an einer importierten Zeile
ist derselbe Vorgang wie eine Neueingabe. Eine eigene Oberfläche nur für drei
Distanzen wäre eine zweite Bedienlogik für dasselbe Formular.

Damit entsteht der Menüpunkt **„Bestenliste"**, der in 6.2 noch für Phase 4
vorgemerkt war, schon jetzt. Der Anlass ist der Zeitlauf; der Nutzen ist
größer.

```
Ergebnis-Import   URL → parsen → Vorschau → übernehmen     (Abschnitt 6)
Bestenliste       Formular → prüfen → speichern            (Abschnitt 7)
                  ↓                                  ↓
                        lsg_best + dasselbe Log
```

### 7.1 Menü, Capability und Seitenaufbau

Untermenü `lsg-bestenliste-best` unter dem Top-Level-Menü aus 6.2, Callback in
`includes/admin/page-best.php` – ein Callback pro Seite, eine Datei pro
Callback, wie dort festgelegt.

**Entschieden: dieselbe Capability wie der Import**, `LSG_BL_CAP` (= `read`,
also jeder angemeldete Benutzer). Der Kreis der Leute ist derselbe, und zwei
Konstanten für dieselbe Personengruppe wären eine Unterscheidung, die niemand
pflegt.

⚠ Das ist trotzdem nicht dasselbe Risiko wie beim Import. Dort steht zwischen
Eingabe und Datenbank der ganze Trichter aus P1–P4 mit einer Vorschau, hier
schreibt ein Formular direkt in den Bestand. Was diese Seite deshalb zwingend
mitbringt:

- **Jeder Schreibvorgang wird protokolliert** – dieselben Tabellen wie der
  Import (7.5). Ohne das Log wäre die Seite ein anonymer Direktzugriff auf
  `lsg_best`.
- **Kein Löschen ohne Rückfrage**, und der gelöschte Datensatz steht vollständig
  im Log (7.4).
- Capability-Prüfung, Nonce und `check_admin_referer()` in *jedem* Handler,
  nicht nur beim Rendern – gleiche Regel wie 6.9.

Die Seite hat zwei Ansichten, die sich über `?action=` unterscheiden:

```
?page=lsg-bestenliste-best                 Liste (7.4)
?page=lsg-bestenliste-best&action=new      Formular, leer
?page=lsg-bestenliste-best&action=edit&id= Formular, vorbelegt
```

Kein Modal, kein Inline-Edit in der Tabelle: Die Jahresbestzeit-Prüfung aus 7.3
braucht Platz für einen Vergleich, und ein Formular mit sechs Feldern ist
nichts, wofür man eine Zeile aufklappt.

### 7.2 Das Formular

| Feld | Steuerelement | Pflicht | Regel |
|---|---|---|---|
| Athlet | Select über `lsg_athlete` | ja | Wert = `athletes_id` |
| Datum | `<input type="date">` + Textfallback | ja | Veranstaltungsdatum, nicht Erfassungsdatum |
| Distanz | Select über `lsg_bl_distance_map()` | ja | **alle zwölf Codes**, inkl. `6h`/`12h`/`24h` |
| Leistung | Textfeld | ja | Label und Prüfung folgen der Distanz, s.u. |
| Ort | Textfeld, max. 30 Zeichen | ja | `lsg_best.town` |
| Altersklasse | Anzeige, kein Eingabefeld | – | berechnet, s.u. |

**Athlet-Dropdown.** Rund 430 Datensätze, davon etwa 260 aktiv – zu viele für
eine ungeordnete Liste, zu wenige für eine Suche mit Autocomplete. Deshalb ein
gewöhnliches `<select>`:

```
Anzeige     „Nachname, Vorname (Jahrgang)"   →  „Schlippe-Schrieber, Gudrun (1955)"
Sortierung  name, firstname
Gruppen     <optgroup> „Aktiv"  /  <optgroup> „Ehemalige"   (lsg_athlete.active)
```

Der Jahrgang gehört sichtbar in den Eintrag, nicht nur in den Wert: Er
unterscheidet gleiche Namen, und er ist die Größe, aus der gleich die
Altersklasse gerechnet wird – wer sie im Dropdown sieht, erkennt eine
Fehlauswahl sofort.

Ehemalige werden mit angeboten, aber getrennt: Ein Ergebnis aus 2019 kann zu
jemandem gehören, der inzwischen ausgetreten ist. Sie zu verstecken würde
genau die Nachträge verhindern, für die es diese Seite gibt.

⚠ **Das Formular legt keinen Athleten an.** Gleiche Regel wie beim Import
(6.5.3): Wer fehlt, wird im Untermenü „Sportler" (Phase 4) angelegt. Solange
das nicht existiert, steht unter dem Dropdown der Hinweis, wo Athleten gepflegt
werden – nicht ein Feld, das still einen zweiten „Müller, Peter" erzeugt.

**Das Leistungsfeld wechselt mit der Distanz.** Das ist der eigentliche Grund,
warum diese Seite die Zeitläufe kann und der Import nicht:

| `lsg_bl_distance_type()` | Distanzen | Label | Eingabe | Beispiel |
|---|---|---|---|---|
| `time` | `5km` … `100km`, `HM`, `Marathon` | „Zeit" | `HH:MM:SS` | `01:36:44` |
| `distance` | `6h`, `12h`, `24h` | „Strecke" | `N,NNN km` bis `NNN,NNN km` | `96,723 km`, `112,737 km` |

Beides landet in derselben Spalte `lsg_best.time` – so ist der Bestand
aufgebaut, und `lsg_bl_parse_performance()` liest beides bereits richtig. Das
Formular muss nur dafür sorgen, dass in der Spalte das steht, was zur Distanz
gehört.

Regeln für das Feld:

- **Label, Platzhalter und Prüfmuster folgen `lsg_bl_distance_type()`.** Wechselt
  jemand die Distanz von `HM` auf `12h`, ändert sich das Label von „Zeit" auf
  „Strecke" und das Feld wird geleert – ein stehengebliebenes `01:36:44` unter
  „Strecke" wäre die naheliegendste Fehleingabe überhaupt.
- **Zeiten** werden wie beim Import normalisiert (6.5.1): `1:13:08` → `01:13:08`,
  `38:57` → `00:38:57`, Zehntel nach World-Athletics-Regel aufgerundet. Dieselbe
  Funktion, nicht eine zweite Implementierung.
- **Strecken** werden als Kilometerzahl mit **drei Nachkommastellen**
  gespeichert, Komma als Dezimaltrenner, Leerzeichen, `km` – also `96,723 km`
  und `112,737 km`.

  ⚠ **Entschieden: keine führende Null.** Ein früherer Entwurf verlangte drei
  Vorkommastellen (`096,723 km`) mit der Begründung, der Bestand sei
  durchgehend so geschrieben. Das stimmt nicht: von 199 Zeitlauf-Zeilen
  entsprachen 173 der Form ohne Auffüllung, nur 23 trugen eine führende Null
  (geprüft 2026-09-01). Die Auffüllung wird nicht erzeugt, und die 23
  Altzeilen werden auf dieselbe Form gebracht (V1, Abschnitt 8) – die Spalte
  ist danach einheitlich, und `96,723 km` ist die einzige gültige Schreibweise.
  Das Prüfmuster des Formulars lehnt eine führende Null ab.

  Die drei Nachkommastellen bleiben dagegen Pflicht: sie sind die Auflösung,
  in der die Veranstalter messen, und `64,16 km` neben `112,737 km` liest sich
  wie zwei verschiedene Genauigkeiten. Für die Sortierung ist beides
  unerheblich – `lsg_bl_parse_performance()` liest die Zahl, nicht den
  String –, für die Tabellenspalte nicht.
- **Abgelehnt wird alles, was der Parser nur über den Zahlen-Fallback
  einfangen würde.** Dieser Fallback existiert für die historischen Tippfehler im
  Bestand; über das Formular darf kein neuer dazukommen.

**Die Altersklasse wird gerechnet, nicht eingegeben.** Dieselbe Formel wie in
P3 (6.5.3), nur mit den Daten aus dem Formular statt aus der Ergebnisliste:

```
alter = Jahr(Datum) − lsg_athlete.born
alter < 30      →  'hk'
sonst           →  floor(alter/5)*5
Code            =  ('f' === lsg_athlete.cat ? 'w' : 'm') . stufe
```

Angezeigt wird der Code unmittelbar unter dem Datumsfeld, sobald Athlet und
Datum stehen – als Text, nicht als Feld: *„Altersklasse: m50 (Jahrgang 1976,
Lauf 2026)"*. Man soll sehen, was gespeichert wird, ohne es ändern zu können;
änderbar wäre es nur um den Preis, dass `lsg_best.ak` und
`lsg_best.athletes_id` auseinanderlaufen.

⚠ **Nicht jeder gerechnete Code steht in `lsg_ak`.** Die Tabelle kennt
`mhk`/`whk` und `m30`–`m75` bzw. `w30`–`w70`. Ein Athlet des Jahrgangs 1943
ergibt bei einem Lauf 2026 `m80` – das ist kein Randfall, sondern ein aktiver
Datensatz, und im Bestand stehen bereits 32 solche Zeilen. Verhalten wie beim
Import (6.5.3): **speichern, ohne Rückfrage.** Daneben der Hinweis *„Die
Altersklasse m80 fehlt in `lsg_ak` – bis sie ergänzt ist, lässt sich im
Frontend nicht danach filtern."* `lsg_ak` ist die Anzeigeliste des
AK-Dropdowns, nicht die Instanz, die über die Richtigkeit eines Ergebnisses
entscheidet; ein Bestätigungsschritt an dieser Stelle wäre eine Rückfrage, die
der Mensch am Formular gar nicht beantworten kann.

### 7.3 Jahresbestzeit-Prüfung – warnend, nicht sperrend

`lsg_best` hält Jahresbestleistungen: eine Zeile je Athlet, Distanz und
Kalenderjahr (6.5.4). Das gilt unabhängig davon, ob eine Zeile importiert oder
getippt wurde – ein Formular, das diese Regel nicht kennt, erzeugt genau die
Doppelzeilen, die P4 beim Import sorgfältig vermeidet.

Sobald Athlet, Distanz und Datum stehen, sucht die Seite deshalb mit derselben
Abfrage wie P4:

```sql
SELECT id, time, town, date
  FROM lsg_best
 WHERE athletes_id = %d
   AND distance    = %s
   AND `date`     >= %d
   AND `date`      < %d
```

Grenzen aus `lsg_bl_jahr_grenzen()`, aus demselben Grund wie in 6.5.4 – kein
`YEAR(FROM_UNIXTIME())`, kein `mktime()`.

⚠ Liefert die Abfrage **mehr als eine Zeile**, gilt dieselbe Regel wie in P4
(6.5.4): Bezug ist die beste der gefundenen Zeilen, geschrieben wird nur in
diese, und über dem Vergleich steht *„Doppelzeile im Bestand (ids #…) – bitte
bereinigen"*. Seit der Bereinigung (Vorarbeit **V1**, Abschnitt 8, ausgeführt
am 2026-09-01) sollte der Fall nicht mehr auftreten.

**Entschieden: geprüft und gewarnt wird, gesperrt nicht.** Der Mensch am
Formular weiß Dinge, die die Datenbank nicht weiß – etwa dass der vorhandene
Eintrag falsch ist. Was er nicht wissen kann, ist, dass es ihn überhaupt gibt.
Genau das liefert die Prüfung:

| Lage | Anzeige | Vorbelegung |
|---|---|---|
| keine Zeile | *„Noch keine Leistung für 2026 auf dieser Distanz."* | anlegen |
| neue Leistung besser | Bestand mit Ort und Datum, *„Die neue Leistung ist schneller (01:38:12 → 01:36:44)"* | **Bestand überschreiben** |
| neue Leistung schlechter | Bestand, *„Die neue Leistung ist langsamer (01:36:44 bleibt)"* | **nichts tun** – Überschreiben nur nach ausdrücklichem Haken *„Der vorhandene Eintrag ist falsch, ersetzen"* |
| identisch | *„Diese Leistung steht bereits so in der Datenbank."* | Speichern deaktiviert |

⚠ **Es gibt keine Option „zusätzlich anlegen".** Eine zweite Zeile für
denselben Athleten, dieselbe Distanz und dasselbe Jahr ist kein Sonderfall,
sondern ein kaputter Bestand: Die Bestenliste zeigt dann beide, die Ewige
Bestenliste dedupliziert eine davon weg, und keine der beiden Ansichten ist
mehr erklärbar. Wer eine zweite Leistung desselben Jahres festhalten will, hat
dafür das Log – `lsg_best` ist keine Wettkampfhistorie (6.8).

„Besser" heißt bei Zeitläufen **größer**. Das entscheidet
`lsg_bl_parse_performance()` über `better`; hier wird der Zweig `'higher'`
tatsächlich erreicht, anders als im Import (6.5.4). Für `12h` ist
`112,737 km` besser als `96,723 km`, und der Vergleichstext heißt dann
*„weiter"* statt *„schneller"*.

⚠ Das Jahr kommt aus dem **eingegebenen Veranstaltungsdatum**, nie aus
`date('Y')` – ein im Januar nachgetragener Dezemberlauf gehört ins Vorjahr.
Gespeichert wird der Timestamp mit **12:00 Uhr Ortszeit** über
`wp_timezone()`, nicht über `mktime()`, aus demselben Grund wie in 6.5.1.

### 7.4 Liste, Bearbeiten, Löschen

Die Einstiegsansicht ist eine `WP_List_Table` über `lsg_best`, mit Join auf
`lsg_athlete`:

- **Spalten:** Athlet (Nachname, Vorname), Jahrgang, Jahr, Distanz, Leistung,
  AK, Ort, Datum, Aktionen.
- **Filter:** Jahr, Distanz, Geschlecht, Athlet (Suchfeld über Name und
  Vorname).
- **Standardsortierung:** Jahr absteigend, dann Distanz in der Reihenfolge von
  `lsg_bl_distance_map()` (nicht alphabetisch – `100km` vor `10km` wäre die
  Folge), dann Leistung über `lsg_bl_sort_rows_by_performance()`.
- **Paginierung**, weil der Bestand rund 6 000 Zeilen hat.

**Bearbeiten** öffnet dasselbe Formular, vorbelegt aus der Zeile.

⚠ Ändert die Bearbeitung **Athlet, Distanz oder das Jahr des Datums**, läuft
die Prüfung aus 7.3 erneut – und zwar gegen die *neue* Kombination. Sonst wäre
das Bearbeiten die Hintertür, durch die genau die Doppelzeile entsteht, die das
Anlegen verhindert: Man legt eine Zeile für 2025 an und schiebt sie anschließend
auf 2026, wo schon eine steht.

**Löschen** ist einzeln, mit Rückfrage, und die Aktions-URL trägt eine Nonce
(`wp_nonce_url()`) – ein Löschlink, den ein Crawler oder ein Prefetch anfassen
kann, ist keiner. Kein Bulk-Delete im ersten Wurf.

⚠ Löschen ist die einzige Aktion ohne Wiederherstellung in der Oberfläche.
Deshalb schreibt sie den **vollständigen Datensatz** ins Log (7.5), nicht nur
die ID: Distanz, Leistung, Ort, Datum, Athlet. Wer versehentlich löscht, kann
die Zeile aus dem Log heraus neu tippen.

**Die AK-Spalte wird bei jedem Speichern neu gerechnet**, nie aus der alten
Zeile übernommen. Korrigiert später jemand den Jahrgang eines Athleten, stimmen
dessen bereits gespeicherte AK-Werte allerdings nicht mehr – ein Nachrechnen
des gesamten Bestands ist **nicht** Teil dieser Seite. Das wäre eine Migration
über tausende Zeilen, kein Formularvorgang; vorgemerkt in 9.2.

### 7.5 Protokollierung – dasselbe Log wie der Import

Manuelle Änderungen laufen in `lsg_import_run` und `lsg_import_log` (6.8), nicht
in eine dritte Tabelle. Der Grund ist die Frage, die das Log beantworten soll:
*„warum steht bei X diese Zeit"*. Ein Log, das nur die importierte Hälfte des
Bestands kennt, beantwortet sie in genau den Fällen nicht, in denen jemand von
Hand eingegriffen hat – also in den interessanten.

Ein Datensatz in `lsg_import_run` je Formularaktion, mit den Feldern, die es
gibt:

```
adapter       'manuell'
source_url    ''
event_id      ''        contest_id ''      list_id ''
event_name    ''        contest_name ''    list_name ''
event_date    Veranstaltungsdatum aus dem Formular
datum_quelle  'manuell'                    (Wert existiert bereits, 6.5.1)
jahr          Jahr des Veranstaltungsdatums
distance      gewählter Code
town          eingegebener Ort
zeit_typ      ''        (die Quelle ist ein Mensch, nicht netto/brutto)
cnt_gelesen   0   cnt_lsg 0   cnt_zugeordnet 0
cnt_angelegt / cnt_aktualisiert / cnt_fehler   je 0 oder 1
user_id       aktueller Benutzer
```

Dazu genau eine Zeile in `lsg_import_log` mit `match_type = 'manuell'` und den
Rohfeldern gefüllt aus dem, was im Formular stand (`roh_teilnehmer` = angezeigter
Athletenname, `roh_jahrgang`, `roh_zeit` = die Eingabe vor der Normalisierung).

Zwei Ergänzungen am Wertebereich aus 6.8, beide klein:

```
aktion:      delete      Zeile aus lsg_best entfernt (Rohfelder = der Zustand
                         vor dem Löschen, time_alt = die gelöschte Leistung)
match_type:  manuell     Athlet wurde von Hand gewählt, nicht zugeordnet
```

Die Log-Ansicht bekommt entsprechend einen Filter **„von Hand erfasst"**
(`adapter = 'manuell'`), damit sich beide Wege getrennt betrachten lassen.

⚠ `cnt_gelesen`, `cnt_lsg` und `cnt_zugeordnet` bleiben bewusst auf 0 statt auf
1: Der Trichter aus 6.5 hat hier keine Entsprechung, und eine 1 würde in der
Vorgangsübersicht so aussehen, als wäre etwas gelesen und gefiltert worden.

### 7.6 Was diese Seite ausdrücklich nicht tut

- **Keine Athleten anlegen** – Untermenü „Sportler", Phase 4 (7.2).
- **Nicht nach `lsg_win` schreiben.** Gesamtsiege bleiben vorerst Handarbeit,
  wie in 6.5.5 entschieden. Wenn dieser Ausbaustand kommt, bekommt er ein
  eigenes Formular auf derselben Seite – die Felder sind fast dieselben, aber
  `lsg_win` hat mit `event` eine Spalte mehr.
- **Keinen Bestand nachrechnen** – weder AK-Massenkorrektur noch das Auflösen
  vorhandener Doppelzeilen (9.2).
- **Kein CSV-Upload.** Für Massen gibt es den Import; für alles andere ist ein
  Formular pro Ergebnis ehrlicher als eine Datei, deren Spalten niemand prüft.

---


## 8. Umsetzungsschritte

Geschnitten nach Meilensteinen. Jeder endet mit etwas, das man ausprobieren
kann – das ist der Zweck der Aufteilung, sonst liegen 150 Häkchen nebeneinander
und nichts läuft, bis alle abgehakt sind.

**Davor standen zwei Vorarbeiten am Bestand.** Sie gehören nicht in „später
vorgemerkt" (9.2): V1 ist die Voraussetzung dafür, dass P4 und 7.3 überhaupt
auf einer eindeutigen Zeile arbeiten, V2 dafür, dass die Ergebnisse hinterher
im Frontend auffindbar sind. Beide sind einmalige SQL-Vorgänge, kein Code.

| V | Inhalt | Stand | Spätestens vor |
|---|---|---|---|
| V1 | Doppelzeilen, Schreibweisen und Datenfehler in `lsg_best` bereinigen | **erledigt 2026-09-01**, Skript von Hand ausgeführt | M3 |
| V2 | `lsg_ak` bis `m95`/`w95` durchschreiben | **teilweise** – die fünf im Bestand vorkommenden Codes (`m80`, `w75`, `w80`, `w85`, `w90`) sind eingetragen; `m85`, `m90`, `m95`, `w95` fehlen noch | M3 |

Die Anweisungen dafür liegen als kommentiertes Skript im Repository:
`maintenance/2026-09-01-bestand-bereinigung.sql`. **Es ist am 2026-09-01 von
Hand ausgeführt worden** und bleibt als Protokoll liegen – es dokumentiert, was
am Bestand geändert wurde, und die Gegenproben in seinem Abschnitt 7 lassen
sich jederzeit erneut laufen lassen.

⚠ **Die Dumps in `assets/*.sql` sind damit veraltet.** Sie zeigen den Stand
*vor* der Bereinigung. Wer eine spätere Prüfung gegen sie fährt, findet die
26 Doppelzeilen und die Schreibweisen erneut und hält sie für offen. Vor der
nächsten Auswertung also neu exportieren – oder direkt gegen die Datenbank
arbeiten.

Zwei Stellen waren im Skript auskommentiert und sind nachgezogen worden:
Abschnitt 5 (`DELETE` der Leerzeile id 6556) ist ausgeführt, und für
Abschnitt 3b ist entschieden – **keine führenden Nullen bei den Zeitläufen**,
die 23 Altzeilen werden angeglichen. Der Block ist deshalb nicht mehr
optional, sondern aktiver Bestandteil des Skripts.

⚠ **Ein Punkt bleibt: die Dublettenprüfung gehört wiederholt.** Die Liste in
Abschnitt 1 wurde gegen `date = 0` erzeugt, id 1649 bekam ihr Datum erst
danach. Athlet 288 (Scholz, Steffen, 1970) hat 10-km-Jahresbestzeiten in fünf
Jahren, und `00:38:57` aus id 1649 trifft je nach Jahr anders:

| Jahr | vorhandene Zeile | Leistung | Folge |
|---|---|---|---|
| 2013 | id 1646, Rheinzabern | 00:36:37 | Bestand ist schneller → id 1649 entfällt |
| 2014 | id 1647, Dillenburg | 00:37:42 | Bestand ist schneller → id 1649 entfällt |
| 2015 | id 1648, Stutensee-Blankenl. | 00:40:56 | id 1649 ist schneller → id 1648 entfällt |
| 2016 | id 4419, Stutensee-Büchig | 00:44:03 | id 1649 ist schneller → id 4419 entfällt |
| 2017 | id 4745, Stutensee-Blankenloch | 00:39:44 | id 1649 ist schneller → id 4745 entfällt |

Jedes andere Jahr: keine Dublette, id 1649 bleibt als einzige Zeile stehen.

⚠ **Die Dumps in `assets/*.sql` bleiben absichtlich auf dem Stand vor der
Bereinigung.** Sie sind der Ausgangszustand, gegen den dieser Plan geprüft
wurde, und in dieser Rolle nützlich. Für eine spätere Auswertung sind sie
damit aber nicht mehr maßgeblich – wer die Zahlen in diesem Abschnitt gegen
sie nachrechnet, findet die 26 Doppelzeilen und die Schreibweisen erneut und
hält sie für offen. Maßgeblich ist die Datenbank.

Was V1 im Einzelnen umfasste (Ausgangsstand 2026-09-01, 5 951 Zeilen):

| Befund | Umfang | Behandlung |
|---|---|---|
| zwei Zeilen für Athlet + Distanz + Jahr | 26 Gruppen, davon 11 aus 2024–2026 | die bessere Leistung bleibt, die schlechtere entfällt |
| Zeit nicht als `HH:MM:SS` (`01:33.38`, `4:02:19`, `59:24`, `01:32:35.`) | 18 Zeilen | auf `HH:MM:SS` vereinheitlichen |
| Strecke nicht als `N,NNN km` (führendes Leerzeichen, zu wenig Nachkommastellen) | 3 Zeilen (ids 4194, 4242, 6296) | korrigieren |
| Strecke mit führender Null (`096,723 km`) | 23 Zeilen | Null entfernen – keine führenden Nullen bei Zeitläufen (7.2) |
| `date = 0` → Jahr 1970, in keiner Jahresauswahl sichtbar | 1 Zeile (id 1649) | Datum von Hand nachgetragen |
| Leerzeile (`distance = ''`, `00:00:00`) | 1 Zeile (id 6556) | gelöscht |
| Lauf am 1. Januar, als 00:00 Ortszeit gespeichert → bei UTC-Session im Vorjahr | 6 Zeilen (ids 1073, 1532, 1535, 3356, 3396, 3972) | auf 12:00 Ortszeit ziehen, wie 6.5.1 es für neue Zeilen vorschreibt |

⚠ Die 26 Doppelzeilen sind **Erfassungsfehler, keine zweite Lesart der
Tabelle**. Auffällig ist die Häufung beim Halbmarathon 2024: sieben Athleten
mit je einem Ettlingen- und einem Karlsruhe-Eintrag – das sieht nach einem
doppelt erfassten Lauf aus. Die Bereinigung verwirft die jeweils schlechtere
Leistung endgültig; `lsg_best` ist keine Wettkampfhistorie (6.8). Wer die
zweiten Läufe behalten möchte, findet sie als Kommentar in Abschnitt 1 des
Skripts – das ist jetzt die einzige Stelle, an der sie noch stehen.

| M | Inhalt | Fertig, wenn |
|---|---|---|
| M1 | Datenmodell inkl. Schema-Version, Interface, Registry, `RaceResultAdapter`, Fixtures, `tests/unit/` | ✅ **erledigt 2026-09-01** – `phpunit --testsuite unit` ist grün (148 Tests) und die Ettlingen-Liste kommt normalisiert aus dem Adapter |
| M2 | Admin-Seite Schritt 1–3 ohne JavaScript, P1 + P2, Distanz-/Datums-Controls | ✅ **erledigt 2026-09-01** – die Vorschau zeigt den Trichter `658 gelesen → 1 ohne Zeit → 11 LSG`, geschrieben wird nichts |
| M3 | P3, P4, Übernahme, Log + Log-Ansicht | ✅ **erledigt 2026-09-02** – der Ettlinger Halbmarathon landet als `7 angelegt, 1 aktualisiert, 3 übersprungen` in `lsg_best`; ein zweiter Durchlauf zeigt lauter `gleich` und schreibt nichts |
| M4 | `RuntixAdapter` inkl. Datumsermittlung über `/sts/10020` | ✅ **erledigt 2026-09-02** – derselbe Ablauf mit `https://runtix.com/sts/10050/3152/21/total`: erkannt als runtix, Datum `16.08.2026` aus der Veranstaltungsübersicht, Distanz „Halbmarathon" aus dem Wettbewerbsnamen, Trichter `22 gelesen → 1 LSG`. Die Oberfläche hat dafür keine Zeile Sonderbehandlung |
| M5 | Seite „Bestenliste" (Abschnitt 7), Untermenü „Zuordnungen" | Zeitläufe und Korrekturen sind erfassbar |
| M6 | REST-Routen + `assets/js/admin-import.js`, Zustände aus 6.11 verfeinern | derselbe Ablauf ohne Reload |

⚠ **M6 kommt zuletzt, nicht nebenbei.** Progressive Enhancement heißt, dass die
Seite ohne JavaScript zuerst vollständig funktioniert (6.9). Wer die
REST-Schicht parallel baut, hat zwei halbfertige Eingänge in dieselbe Logik und
prüft am Ende keinen von beiden.

- [x] ~~**V1** Bestand bereinigen –
      `maintenance/2026-09-01-bestand-bereinigung.sql`~~ – **von Hand
      ausgeführt am 2026-09-01**
  - [x] ~~26 Doppelzeilen auflösen, 18 Zeit- und 3 Streckenschreibweisen
        vereinheitlichen~~
  - [x] ~~Datum für id 1649 aus der Vereinsablage nachtragen~~
  - [x] ~~Die sechs Neujahrsläufe auf 12:00 Ortszeit ziehen~~ (6.5.4)
  - [x] ~~Leerzeile id 6556 löschen~~ (Abschnitt 5)
  - [ ] Abschnitt 3b ausführen: die 23 führenden Nullen bei den Zeitläufen
        entfernen – entschieden, nicht mehr optional (7.2)
  - [ ] **Dublettenprüfung wiederholen**, weil id 1649 erst nach der Erzeugung
        der Liste ein Datum bekam – Abschnitt 1b des Skripts, mit der
        Entscheidungstabelle für Athlet 288 oben
  - [ ] Gegenprobe (Abschnitt 7) liefert überall die leere Menge – zusammen
        mit den beiden Punkten oben
- [x] ~~**V2** `lsg_ak`: die fünf im Bestand vorkommenden Codes `m80`, `w75`,
      `w80`, `w85`, `w90` ergänzen~~ – erledigt mit Abschnitt 6 des Skripts
  - [ ] `lsg_ak` bis `m95`/`w95` durchschreiben (`m85`, `m90`, `m95`, `w95`
        fehlen noch) – sonst läuft die Tabelle dem Bestand in ein paar Jahren
        wieder hinterher (6.5.3)
- [x] Plugin-Grundgerüst (Header, Autoloader, Activation/Deactivation-Hooks)
      → ein Autoloader kam bewusst nicht dazu: `require_once` je Datei, kein
        Composer im Produktivpfad (9.1)
- [x] Datenmodell: Zusatztabellen `lsg_athlete_map`, `lsg_import_run`,
      `lsg_import_log` per `dbDelta()` in `lsg_bl_install_schema()`
  - [x] Schema-Version `lsg_bl_db_version` + Upgrade auf `admin_init` – der
        Activation-Hook läuft auf der bestehenden Installation nicht noch
        einmal, sonst fehlen die Tabellen schlicht (6.8)
  - [x] Aktivierung und Upgrade rufen dieselbe `lsg_bl_install_schema()` – die
        **nur** die drei neuen Tabellen kennt; die vier Bestandstabellen
        bleiben in `lsg_bl_activate()` (6.8)
  - [x] Keine Anzeigebreiten (`int UNSIGNED`, `year`; Ausnahme `tinyint(1)`) –
        sonst wiederholte `ALTER TABLE`s durch `dbDelta()` auf MySQL 8 (6.8)
  - [x] Tabellennamen über `lsg_bl_table()`, Kollation über
        `$wpdb->get_charset_collate()`
- [x] `lsg_bl_jahr_grenzen( int $jahr ): array` – Kalenderjahr → `[von, bis)`
      über `wp_timezone()` und `DateTimeImmutable`, **nicht** `mktime()` (6.5.4)
  - [x] `YEAR(FROM_UNIXTIME())` aus allen fünf Bestandsabfragen entfernen:
        `lsg_bl_get_best_rows()`, `lsg_bl_get_distances_present()`,
        `lsg_bl_get_win_rows()` auf die Zeitspanne umstellen;
        `lsg_bl_get_best_years()` und `lsg_bl_get_win_years()` lesen den
        Timestamp und rechnen über `lsg_bl_year_from_timestamp()`
  - [x] Veranstaltungsdatum beim Schreiben über
        `new DateTimeImmutable( '… 12:00:00', wp_timezone() )` (6.5.1, 7.3)
        → `lsg_bl_datum_zu_timestamp()` steht; benutzt wird sie erst vom
          Schreibweg (M3) und vom Formular (M5)
- [x] `ErgebnisQuelle`-Interface + `Ergebnis`-Value-Object
- [x] `RaceResultAdapter`
  - [x] `config` abrufen, `key` + `server` extrahieren
  - [x] Listen auflisten / per `Name` auflösen
  - [x] `list` abrufen mit `r=all&l=0`
  - [x] Feld-Mapping über `DataFields`
        → über die Expression aufgelöst, Positionsversatz nur als letzte
          Rückfallebene. Dazu: `data` kommt bei gruppierten Listen (AK-Liste)
          als Objekt statt als Array – im Plan nicht vorgesehen, kommt aber vor
- [x] `RuntixAdapter`
  - [x] URL-Builder (ohne trailing slash!)
        → eine einzige Funktion `url_bauen()`, die nie ein `/` anhängt. `url_zerlegen()`
          verträgt umgekehrt einen eingegebenen Schrägstrich am Ende.
  - [x] Contest-IDs als String, `"w"`-Fall berücksichtigen
        → am 2026-09-02 bestätigt: Event 3152 hat die Keys `21`, `10`, `5` und `w`
  - [x] DOMXPath-Parser nach CSS-Klassen
  - [x] `col-ageclass` vs. `col-place-ageclass` exakt trennen
        → über `hat_klasse()`: das class-Attribut wird an Whitespace zerlegt und
          exakt verglichen. Nötig für zwei Fälle zugleich – `col-ageclass` darf
          nicht in `col-place-ageclass` treffen, und `col-time` muss trotz des
          Leerzeichens in `class="col-time "` treffen.
  - [x] Umlaut-/Encoding-Test (Körner, Häffner, Säckingen)
        → acht Schreibweisen aus der echten Liste: `Jürgen`, `Pählke`, `Geißler`,
          `KRÜGER`, `SEIDER, FRANK`, `weschenfelder, andreas`, `Nees, Dr. Corinna`,
          `Michalewski,, Patrick`. ⚠ Der `<?xml encoding="UTF-8">`-Vorspann vor
          `loadHTML()` ist Pflicht: ohne ihn nimmt libxml Latin-1 an und macht aus
          „Lußhardtlauf" „LuÃŸhardtlauf".
        → ⚠ **Zwei Korrekturen gegenüber Abschnitt 3:**
          (a) Die drei Listentypen liefern **dieselben elf Spalten**. Geprüft am
              2026-09-02: `total` (234 Zeilen), `sex` (73) und `ac` (23) haben je
              Zeile elf Zellen, keinen colspan, keine Gruppenzeilen. Der Plan nahm
              an, `ac` lasse den Gesamtplatz und `sex` den AK-Platz weg – tun sie
              nicht. Am Lesen nach Klasse ändert das nichts; es kostet nichts und
              trägt auch dann, wenn Runtix die Reihenfolge doch einmal ändert.
          (b) `sex` und `ac` sind **Teillisten** (ein Geschlecht bzw. eine
              Altersklasse), nicht andere Darstellungen derselben Menge. Wer dort
              importiert, holt einen Ausschnitt – deshalb ist `gesamtwertung`
              ausschließlich bei `total` gesetzt.
- [x] Zeit-Parser: `01:11:54.9`, `1:13:08`, evtl. `MM:SS` → einheitlich Sekunden
- [x] Normalisierung: `lsg_bl_verein_normalisieren()` + Namens-Normalisierung
      (Umlaute, Bindestriche, Groß/Klein) – Basis für P2 und P3
- [x] Transient-Caching innerhalb eines Vorgangs (Discovery 15 min, Parse 1 h)
      → Discovery holt Wettbewerbe, Listen und Datum in einem Rutsch, damit ein
        Wechsel des Wettbewerbs keinen Request auslöst. Gemessen: der zweite
        Seitenaufruf derselben Veranstaltung kostet null Abrufe, ein Parsen kostet
        genau zwei (config + list).
- [x] Adapter-Registry + Filter `lsg_bl_ergebnis_adapter`
- [ ] Admin-Seite „Ergebnis-Import" (Abschnitt 6)
  - [x] Top-Level-Menü `lsg-bestenliste` + Konstante `LSG_BL_CAP`
  - [x] Schritt 1: URL-Feld + Adapter-Erkennung, Adapter manuell übersteuerbar
  - [x] Schritt 2: Wettbewerbs-Select, Listen-Select (ausblenden bei ≤ 1 Liste)
  - [x] Schritt 3: Parsen-Button, Vorschautabelle, Übernehmen/Verwerfen
        → Vorschau und Verwerfen stehen; „Übernehmen" kommt mit M3 – bis dahin sagt
          die Seite ausdrücklich, dass nichts gespeichert wird.
  - [x] Alle elf Zustände aus 6.11 darstellen (inkl. Fehler mit Klartext)
        → als eine Funktion (`lsg_bl_import_zustand()`); dargestellt werden die ohne
          JavaScript erreichbaren. `erkenne`, `parse` und `uebernahme` sind
          Zwischenzustände eines laufenden Requests und werden erst mit M6 sichtbar.
          ⚠ „erkannt" deckt zwei Lagen ab, die der Plan nicht trennt: Wettbewerb noch
          nicht gewählt, und Wettbewerb gewählt, aber Distanz oder Datum fehlen.
  - [x] Formular-Roundtrip über `admin-post.php` (funktioniert ohne JS)
  - [ ] `assets/js/admin-import.js` für den Ablauf ohne Reload
  - [ ] `AbortController` gegen Race Condition beim schnellen Umschalten
  - [x] Assets nur auf dieser Seite laden (`$hook`-Vergleich)
  - [x] Nonce + `check_admin_referer()` + Capability-Prüfung in jedem Handler
- [ ] Parse-Pipeline P1–P4 (Abschnitt 6.5)
  - [x] P1 Namens-Splitter: Komma-Form, GROSSBUCHSTABEN-Form, Fallback + `namen_unsicher`
        → ⚠ das scharfe ß hat keine Großform, die die Quellen benutzen
          (`GEIßLER`, `STÖßER`): vor dem Vergleich zu `SS` auflösen, sonst fällt
          jeder solche Nachname in den Rateweg
  - [x] P1 Geschlecht aus dem AK-Code der Quelle (`M 30`, `1. M35`) → `m`/`f`
  - [x] P1 Netto vor Brutto, `zeit_typ` mitführen
  - [x] P1 Zeit-Normalisierung auf `HH:MM:SS`, DNF/DSQ/DNS verwerfen + zählen
  - [x] P1 Felder Distanz / Datum / Ort über der Tabelle, vorbelegt + änderbar
        → Vorbelegt wird nur, was der Mensch nicht selbst gesetzt hat. Der
          Unterschied zwischen „noch nicht angefasst" und „bewusst geleert" steht in
          der Query: fasst jemand das Feld an, ist der Parameter da – auch leer.
  - [x] P1 Datum ermitteln: Adapter-Metadaten → Datum im Namen → Jahr → leer
  - [x] P1 Runtix: `/sts/10021/{id}` für den Einstieg, `/sts/10020/{jahr}` als
        maßgebliche Quelle, Eintrag über den Link `/sts/10050/{id}` finden
        → ⚠ **Korrektur:** der Eintrag wird über den **Datums-Link** `/sts/10021/{id}`
          gefunden, nicht über den Ergebnis-Link. Grund: 58 der 157 Zeilen der
          Übersicht 2026 haben noch keine Ergebnisse, und dort zeigt auch der Name
          auf `/sts/10021/`. Über `/sts/10050/{id}` allein wären die nicht zu finden.
          Der Datums-Link steht in jeder Zeile.
        → ⚠ Und gelesen wird **zeilenweise** (`div.row.competition` → `div.description`),
          nicht über einen flachen Link-Scan: der „Ergebnisse"-Knopf zeigt auf
          dieselbe `/sts/10050/{id}`, trägt aber das Wort „Ergebnisse" als Text –
          ein flacher Scan schriebe das als Veranstaltungsnamen fort.
        → Gefunden wird über die **ID**, niemals über den Namen. Bestätigt:
          3152 → 16.08.2026, und das deckt sich mit dem „Sonntag, den 16. August 2026"
          der Ausschreibung.
  - [x] P1 Runtix: höchstens zwei Jahres-Versuche, dann Feld leer lassen
        → `array_slice( $jahre, 0, 2 )`. Test `test_hoechstens_zwei_jahresversuche`
          zählt die Abrufe mit und prüft, dass das Feld leer bleibt statt zu raten.
  - [x] P1 Runtix: Jahreszahl der Fußzeile (`Copyright … 2001 - 2026`) ignorieren
        → das `<footer>` wird aus dem DOM entfernt, bevor irgendein Text gelesen wird.
  - [x] P1 Veranstaltungsübersicht je Jahr cachen (Transient, 15 min)
        → `lsg_bl_runtix_jahr_cache()`. ⚠ Auch ein **leeres** Ergebnis wird gecacht,
          sonst wird bei jedem Seitenaufruf erneut vergeblich abgerufen. Und: läuft
          das Rate-Limit an, gibt die Funktion leer zurück statt zu werfen – das
          Datum ist eine Zugabe, kein Pflichtfeld.
        → ⚠ **Neu gegenüber dem Plan:** die Veranstaltungsseite `/sts/10021/{id}`
          nennt **vier** Daten. Am 2026-09-02 standen dort: Lauftag 16.08.,
          Meldeschluss 15.08., Lastschrifteinzug 19.08. und „Stand der Ausschreibung
          12.03.". Von dort ein Datum zu greifen heißt raten. Der Adapter nimmt aus
          dieser Seite deshalb nur **Jahreszahlen**; das vollständige Datum kommt aus
          der Übersicht. Nur als Rückfall (Übersicht schweigt) wird das Datum aus dem
          `<strong>` mit dem Wochentag genommen – und dann als Quelle `ausschreibung`
          ausgewiesen, damit die Oberfläche zur Bestätigung auffordert.
        → ⚠ **Und:** auf der Ergebnisseite steht **kein** Datum. Kein einziges
          `TT.MM.JJJJ` im ganzen Seitentext (geprüft). Das ist der Grund für den
          ganzen zweistufigen Aufwand und steht als Test fest
          (`test_ergebnisseite_nennt_kein_datum`) – findet Runtix dort eines Tages
          doch ein Datum, fällt der Test um und der Weg gehört vereinfacht.
  - [x] P1 race result: kein Datum in `config` – Feld leer lassen, Hinweis zeigen
  - [x] P1 race result: `Tab.ActiveFrom` **nicht** als Veranstaltungsdatum lesen
        → und der Wettbewerbsname wird für das Datum gar nicht gelesen: dort
          stehen Jahrgangsgrenzen (`Bambini 500m`, `Kids 1000m`)
  - [x] P1 Beide Controls leer lassen, sobald der Wert mehrdeutig ist
  - [x] P1 `datum_quelle` mitführen und am Feld anzeigen
  - [x] P1 Unvollständiges Datum **nicht** ergänzen (kein stiller 1. Januar)
  - [x] P1 Timestamp mit 12:00 Uhr Ortszeit speichern (Zeitzonenfalle)
  - [x] P1 `<input type="date">` mit Textfeld-Fallback `TT.MM.JJJJ`
  - [x] P1 Plausibilität: Zukunft, > 10 Jahre zurück, Jahr ≠ Jahr im Eventnamen
        → ⚠ verglichen wird gegen `time()`, nicht gegen `current_time()`: WordPress
          setzt die PHP-Zeitzone auf UTC, `current_time()` wäre um den Offset
          verschoben.
  - [x] P1 Parsen erst freigeben, wenn Distanz **und** vollständiges Datum stehen
  - [x] Änderung von Datum oder Distanz verwirft die Vorschau (zurück auf „Parsen")
        → über einen Fingerabdruck über Adapter, Event, Wettbewerb, Liste, Distanz
          und Datum. ⚠ Der Ort steht bewusst NICHT drin: er landet in
          `lsg_best.town`, geht aber in keinen Vergleich ein – ihn zu ändern darf
          keine Tabelle wegwerfen.
  - [x] P1 `lsg_bl_distance_aliases()`: 21→HM, 42→Marathon, Zahl+Name
  - [x] P1 Distanzwort schlägt Zahl (Marathon vor 42, „5. Ettlinger Marathon")
        → aufgelöst über drei Regeln: ein Staffel-Multiplikator (`4x10 km`) ist
          sofort mehrdeutig, Ordnungszahlen (`5.`) und fremde Einheiten
          (`10 Meilen`, `500m`) zählen nicht, und widersprechen sich Name und
          Zahl, bleibt das Feld leer
  - [x] P1 Distanz-Select geschlossen: kein Freitext, keine neuen Distanzen
        → war mit M2 gebaut und hier nur nicht abgehakt. Das Select wird aus
          `lsg_bl_import_distanzen()` erzeugt, und derselbe Aufruf prüft den
          eingehenden Wert an **zwei** Stellen gegen: `lsg_bl_parsen()` bricht mit
          Klartext ab, und `lsg_bl_import_vorbelegung()` verwirft einen unbekannten
          Code. Ein handgeschriebenes `?distanz=42km` in der Adresszeile kommt also
          nicht durch.
  - [x] P1 Distanz-Select **ohne** `6h`/`12h`/`24h` – Zeitläufe halten in
        `lsg_best.time` eine Strecke, keine Zeit (6.5.1)
  - [x] P1 `platz` mitlesen (nur für 6.5.5)
  - [x] P2 `lsg_bl_ist_lsg()` (LSG **und** Karlsruhe, normalisiert)
        → In der Ettlinger Liste stehen `Karlsruher Lemminge` und `Karlsruher
          Lemminge e.V.` als eigene Vereine (je 1 Zeile). Beide fallen durch den
          Filter. **Offen: sind das eigene Leute?** Wenn ja, ist der Alias der Weg,
          nicht eine Änderung der UND-Regel.
        → ⚠ **Zum zweiten Mal, 2026-09-02:** in der Runtix-Liste zu Event 3152
          stehen sie wieder – `Karlsruher Lemminge e.V.` (Körner, Theresa, 1991)
          und `Karlsruher Lemminge` (Pählke, Frank, 1973). Zwei unabhängige
          Quellen, zwei Läufe, dieselben zwei Schreibweisen. Das ist keine
          Eigenheit einer Ergebnisliste, sondern wie diese Leute sich melden.
          **Die Frage gehört beantwortet, bevor M5 kommt** – bis dahin wandern
          diese Zeilen bei jedem Import in den Block „nicht übernommene Vereine"
          und müssen von Hand angesehen werden.
        → die Ettlingen-Fixture belegt den Zweck der UND-Regel: sie enthält
          22 Zeilen `LSG Weiher` neben 11 Zeilen `LSG Karlsruhe`, dazu
          `(Karlsruhe)` als Wohnort und `Karlsruher Lemminge e.V.`
  - [x] P2 Block „nicht übernommene Vereine" + Vereins-Alias-Option
        → Zwei Listen: Schreibweisen mit `LSG` oder `Karlsruhe` im Namen bekommen
          eine Aktion, die übrigen stehen vollständig als kompakte Liste da. Bei der
          Ettlinger Liste sind das 16 gegen 287 – mit einem Link je Zeile wog die
          Seite 137 kB, fast alles davon Links, die niemand anklickt. „(kein Verein)"
          steht mit in der oberen Tabelle: es ist der einzige Eintrag, der kein
          Verein ist, und dort können Mitglieder stecken.
          ⚠ Ein neuer Alias verwirft die Vorschau, wie Datum und Distanz – der Filter
          hat sich geändert. Eine leere Vereinsangabe wird als Alias abgelehnt.
  - [x] P3 Zuordnungsstufen exakt → regel → normalisiert → offen
        → ⚠ Stufe 1 vergleicht mit `mb_strtolower()`, nicht mit `strcasecmp()`: das
          arbeitet byteweise und faltet keine Umlaute. „KÖRNER" gegen „Körner" liefe
          sonst durch Stufe 1 hindurch und stünde im Log als `normalisiert`, obwohl
          der Treffer exakt war.
  - [x] P3 Tabelle `lsg_athlete_map` + Startdatensatz (171, 183, 377)
        → Die Tabelle steht seit M1; der Startdatensatz wird beim Schema-Upgrade
          geschrieben – jede `athletes_id` vorher gegen Name und Jahrgang gegengelesen,
          und was nicht passt, wird mit Meldung übersprungen statt geschrieben.
  - [x] P3 Jede `athletes_id` des Startdatensatzes gegen Name und Jahrgang
        in `lsg_athlete` gegenlesen, bevor sie geschrieben wird
  - [x] P3 Regel-Lookup: Modus `feld`/`egal`, leeres Feld = beliebig
  - [x] P3 Mehrfachtreffer → `mehrdeutig`, Zeile bleibt offen
  - [x] P3 Regel ohne Vor- und Nachname beim Anlegen ablehnen
  - [x] P3 Nicht zugeordnete Teilnehmer anzeigen, aber **nicht** importieren
  - [x] P3 Keine Checkbox an `offen`/`mehrdeutig`-Zeilen
  - [x] P3 Grund im Klartext (drei Fälle), Rohdaten der Quelle an der Zeile
  - [x] P3 Vorschlagsliste ähnlicher Athleten mit Jahrgang (nur Lesehilfe)
        → vier Ränge: Name passt aber Jahrgang nicht, Nachname + Jahrgang passen,
          Jahrgang passt und Nachname ist ähnlich (Levenshtein ≤ 2), nur Nachname.
          ⚠ Sie ordnet NIE zu – eine vierte Zuordnungsstufe „wahrscheinlich dieselbe
          Person" gibt es bewusst nicht.
  - [x] P3 Meldung über der Tabelle: „N Teilnehmer ohne Zuordnung"
  - [x] Der Import legt **keine** Athleten an und schreibt **keine** Regeln
  - [ ] Untermenü „Zuordnungen" zum Pflegen der Regeln
  - [x] P3 AK-Berechnung aus Jahrgang + Veranstaltungsjahr; Code immer
        schreiben, fehlender `lsg_ak`-Eintrag nur als Hinweis auf den Filter
        → `lsg_bl_ak_berechnen()`; der Code wird ohne Vorbehalt geschrieben,
          und fehlt er in `lsg_ak`, steht „fehlt im Filter" an der AK-Spalte
          der Vorschau. In der Ettlinger Liste trifft das `w75` (van
          Wees-Snel, 1948) – geprüft im Schreibtest
  - [x] P4 Abgleich Athlet + Distanz + Kalenderjahr gegen `lsg_best`
  - [x] P4 Jahr aus dem Veranstaltungsdatum, nie aus `date('Y')`
  - [x] P4 Über Jahresgrenzen hinweg wird nie überschrieben
  - [x] P4 Vergleich über `lsg_bl_parse_performance()`, nicht per String-Vergleich
  - [x] P4 Status neu / schneller / langsamer / gleich / offen
  - [x] P4 Mehr als eine Bestandszeile: beste als Bezug, nur dorthin schreiben,
        Zusatz „Doppelzeile im Bestand" – **kein** stilles `LIMIT 1` (6.5.4)
        → geprüft mit einer künstlich angelegten Doppelzeile: Bezug ist 01:33:00 von
          zwei Zeilen, geschrieben wird nur dorthin, die schlechtere bleibt
          unangetastet, und der Vorgang meldet es in `lsg_import_run.note`.
- [x] Gesamtsieg (Abschnitt 6.5.5) – **nur Erkennung und Markierung**
      → in der Ettlinger Liste gewinnt kein LSG-Läufer (bester Platz 20), die
        Erkennung meldet also richtigerweise nichts. Der Gesamtsieger der
        Liste ist aber sehr wohl als solcher erkennbar – geprüft.
  - [x] Platz 1 erkennen, aber ausschließlich in der Gesamtwertung
  - [x] 🏆 in der Übernahme-Tabelle + Hinweis über der Tabelle
        → Erkannt und markiert, ohne Wirkung. In der Ettlinger Liste gewinnt kein
          LSG-Läufer – bester LSG-Karlsruhe-Platz ist 20 –, die Erkennung meldet also
          richtigerweise nichts.
  - [x] Spalten `roh_platz`, `gesamtsieg` im Log anlegen (leer nutzen)
  - [x] **Nicht** nach `lsg_win` schreiben – späterer Ausbaustand
- [ ] Übernahme-Oberfläche (Abschnitt 6.6)
  - [x] Checkbox je Zeile, Vorauswahl nur `neu` + `schneller`
  - [ ] Kopf-Checkbox „Alle" (`offen`-Zeilen ausgenommen)
        → ⚠ kommt mit M6, und zwar aus dem Grund, den 6.6 selbst nennt: sie
          braucht JavaScript („Ohne JavaScript ist die Kopf-Checkbox schlicht
          nicht da"), und bis dahin soll die Seite vollständig ohne
          JavaScript bedienbar sein. Die Vorauswahl aus `neu` + `schneller`
          deckt den Normalfall ohnehin ab.
  - [x] Statusspalte im Klartext mit alter und neuer Zeit
  - [x] Button mit Anzahl im Label, bei 0 Auswahl deaktiviert
- [x] Schreiblogik (Abschnitt 6.7)
  - [x] INSERT bei `neu`, UPDATE bei `schneller`, nichts bei `langsamer`/`gleich`
  - [x] Statusvergleich unmittelbar vor dem Schreiben wiederholen → `konflikt`
        → verglichen wird gegen den Stand zu Beginn PLUS die eigenen Schreibvorgänge.
          Damit die Reihenfolge nicht über das Ergebnis entscheidet, werden die
          angehakten Zeilen einer Gruppe aus Athlet und Distanz vor dem Schreiben
          nach Leistung sortiert – die beste zuerst.
  - [x] Alles in einer Transaktion, `$wpdb->insert()/update()` mit Formaten
        → ⚠ Die Formatliste wird aus den Feldnamen abgeleitet, nicht von Hand
          gepflegt: `$wpdb->insert()` liest sie POSITIONSWEISE (`array_shift`). Eine
          Handliste steht falsch da, sobald jemand ein Feld einfügt – und der Fehler
          ist still. Ein Fehler in der Mitte rollt den ganzen Vorgang zurück; das Log
          wird danach trotzdem geschrieben.
- [x] Import-Log (Abschnitt 6.8)
  - [x] Tabellen `lsg_import_run` + `lsg_import_log` in `lsg_bl_install_schema()`
  - [x] Auch die Nicht-Aktionen protokollieren (`skip_*`, `konflikt`)
        → ⚠ Feiner als im Plan notiert: `skip_abgewaehlt` bleibt dem Fall vorbehalten,
          um den es 6.7 geht – etwas, das geschrieben WORDEN WÄRE, und jemand hat den
          Haken entfernt. Eine `langsamer`- oder `gleich`-Zeile ist gar nicht erst
          vorausgewählt; sie als „abgewählt" zu protokollieren läse sich wie eine
          Entscheidung, die niemand getroffen hat. Sie bekommt deshalb
          `skip_langsamer` bzw. `skip_gleich`, auch ohne Haken.
  - [x] Log-Ansicht als `WP_List_Table`: Suche, Filter, zwei Ebenen
        → ⚠ Ohne `WP_List_Table` gebaut: die Klasse bringt vor allem Bulk-Actions,
          Spaltensortierung und Screen-Options mit, von denen das Log keins braucht.
          Was es braucht – zwei Ebenen, Suche, Filter, Paginierung – sind dreißig
          Zeilen. Der Einstieg über das Suchfeld führt direkt in die Zeilenebene,
          weil „warum steht bei X diese Zeit" die häufigere Frage ist als „was ist in
          Vorgang 12 passiert".
- [ ] REST-Routen `lsg/v1/import/*` mit
      `permission_callback => fn() => current_user_can( LSG_BL_CAP )` –
      **nie** eine hart notierte Capability und **nie** `__return_true` (6.10)
      → die Routen selbst kommen mit M6. Die Unterpunkte darunter sind
        trotzdem erledigt: sie sind keine REST-Eigenschaften, sondern
        Eigenschaften des Abrufs und des Caches, und die brauchte schon der
        Formularweg. Die REST-Schicht ruft dieselben Funktionen (6.10) und
        erbt sie damit.
  - [x] `wp_safe_remote_get()`, Host-Allowlist aus der Registry, Redirect-Prüfung
        → Redirects werden von Hand verfolgt (`redirection => 0`), damit jeder
          Zwischenschritt erneut durch die Allowlist läuft
  - [x] `ErgebnisQuelle::hosts()` + `lsg_bl_url_erlaubt()`; **jeder** Abruf geht
        durch die Prüfung, auch der zweite Request aus `config.server` (6.10)
  - [x] Rate-Limit pro Benutzer (Transient-Zähler)
        → `lsg_bl_rate_limit_ok()` steht; verdrahtet wird sie in den Handlern (M2)
  - [x] Discovery-Cache (Transient, 15 min) – **ohne** den rotierenden `key`
        → Im Cache stehen nur die abgeleiteten Wettbewerbs- und Listennamen. `key`
          und `server` sind ausgenommen und werden bei jedem Datenabruf frisch aus
          `config` geholt.
  - [x] Parse-Ergebnis in Transient (1 h), Persistenz erst bei „Übernehmen"
        → Der Token ist an die `user_id` gebunden und wird beim Lesen gegengeprüft.
          Gehalten werden nur die Zeilen, die P2 passiert haben – die
          Nicht-LSG-Ergebnisse landen nirgends, auch nicht im Transient.
  - [x] Formular-Handler und REST-Route rufen dieselbe Funktion (keine Doppel-Logik)
        → Die Logik steht in `class-lsg-import.php` (`lsg_bl_discovery()`,
          `lsg_bl_parsen()`); die Admin-Seite ist nur ein Eingang. Der zweite kommt
          mit M6.
- [ ] Admin-Seite „Bestenliste": von Hand erfassen (Abschnitt 7)
  - [ ] Untermenü `lsg-bestenliste-best`, Callback in `includes/admin/page-best.php`
  - [ ] Capability `LSG_BL_CAP`, Nonce + `check_admin_referer()` in jedem Handler
  - [ ] Athlet-Select: „Nachname, Vorname (Jahrgang)", sortiert, `<optgroup>`
        Aktiv / Ehemalige – legt **keinen** Athleten an
  - [ ] Distanz-Select über **alle zwölf** Codes, inkl. `6h`/`12h`/`24h`
  - [ ] Leistungsfeld wechselt mit `lsg_bl_distance_type()`: Label, Platzhalter,
        Prüfmuster – und wird beim Wechsel geleert
  - [ ] Zeiten über dieselbe Normalisierung wie P1 (keine zweite Implementierung)
  - [ ] Strecken mit drei Nachkommastellen, **ohne** führende Null
        (`96,723 km`, nicht `096,723 km`) – 7.2
  - [ ] Eingaben ablehnen, die `lsg_bl_parse_performance()` nur über den
        Zahlen-Fallback einfangen würde
  - [ ] AK berechnen und **nur anzeigen**, nie als Eingabefeld
  - [ ] AK nicht in `lsg_ak` (z.B. `m80`) → Hinweis auf den fehlenden
        Frontend-Filter, kein Bestätigungsschritt
  - [ ] Datum als Timestamp mit 12:00 Uhr Ortszeit
  - [ ] Jahresbestzeit-Prüfung wie P4, aber warnend: überschreiben oder abbrechen
  - [ ] **Keine** Option „zusätzlich anlegen" – nie zwei Zeilen je Athlet/Distanz/Jahr
  - [ ] Zeitläufe: `better => 'higher'`, Vergleichstext „weiter" statt „schneller"
  - [ ] Liste als `WP_List_Table`: Filter Jahr/Distanz/Geschlecht/Athlet,
        Distanz-Sortierung nach `lsg_bl_distance_map()`, Paginierung
  - [ ] Bearbeiten: Prüfung aus 7.3 erneut, sobald Athlet, Distanz oder Jahr wechselt
  - [ ] Löschen einzeln, mit Rückfrage und `wp_nonce_url()`, kein Bulk-Delete
  - [ ] Jeder Schreibvorgang ins Log: `adapter='manuell'`, `match_type='manuell'`,
        `aktion` insert/update/**delete**, Rohfelder aus dem Formular
  - [ ] Beim Löschen den vollständigen Datensatz protokollieren, nicht nur die ID
  - [ ] Log-Ansicht: Filter „von Hand erfasst"
- [x] Weitere Untermenüs unter `lsg-bestenliste` (6.2) – Import-Log und
      „Bestenliste" jetzt, Sportler- und Gesamtsieger-Pflege aus Phase 4 danach
- [ ] **Keine** Event-Verwaltung: Läufe kommen ausschließlich über die URL
- [x] ~~Frontend: Shortcode und/oder Block für die Bestenliste~~ – erledigt,
      die drei Blöcke stehen (Phase 3 der README)
- [x] ~~Sortierung/Filter (AK, Geschlecht, Jahr, Verein)~~ – erledigt
- [ ] i18n (`load_plugin_textdomain`)
- [ ] Fehlerbehandlung + Logging (kein `error_log` in Produktion)

### Verifikation

Zuerst die beiden Vorarbeiten – sie sind mit reinem SQL prüfbar und brauchen
keinen Testrahmen. V1 und der Pflichtteil von V2 sind am 2026-09-01 von Hand
ausgeführt; was hier noch offen steht, ist die Abnahme:

- [ ] V1: die vier Gegenproben aus Abschnitt 7 des Skripts liefern die leere
      Menge (keine Doppelgruppe, keine abweichende Zeit- oder
      Streckenschreibweise, kein `lsg_best.ak` ohne Entsprechung in `lsg_ak`)
- [ ] V1: Zeilenzahl 5 951 → 5 924, bzw. 5 923, falls die Dublettenprüfung
      eine 27. Zeile findet; die Jahres-Bestenliste 2024 zeigt für den
      Halbmarathon jeden Athleten nur noch einmal
- [ ] V1: keine Zeitlauf-Zeile mehr mit führender Null –
      `SELECT COUNT(*) … WHERE \`time\` REGEXP '^0'` ergibt 0
- [ ] V1: id 1649 hat ein Datum, das zum Lauf passt, und `38:57` steht als
      `00:38:57` da
- [ ] V1: die sechs Neujahrsläufe stehen auf 12:00 Ortszeit – Gegenprobe aus
      Abschnitt 6b des Skripts
- [x] ~~V2: `lsg_ak` kennt `m80`, `w75`, `w80`, `w85`, `w90`~~
- [ ] V2: das AK-Dropdown im Frontend bietet auch `75`, `80`, `85`, `90` an,
      und die Auswahl liefert die zugehörigen Zeilen (32 Zeilen von fünf
      Personen)

#### Testrahmen: PHPUnit

**Entschieden: PHPUnit**, in zwei Lagen und als reine Entwicklungs-
abhängigkeit. Das Plugin selbst bleibt Composer-frei – `vendor/` wird nicht
ausgeliefert, es gibt keinen Composer-Autoloader im Produktivpfad, und ohne
`composer install` funktioniert das Plugin unverändert. Composer ist Werkzeug
der Entwicklung, nicht Bestandteil der Auslieferung.

Die zwei Lagen sind kein Selbstzweck, sie unterscheiden sich in der Laufzeit:

| Lage | Braucht | Prüft | Läuft |
|---|---|---|---|
| `tests/unit/` | nur PHPUnit | Adapter, Namenssplitter, Zeit- und Distanz-Normalisierung, Feld-Mapping über `DataFields` | in Sekunden, ohne Datenbank, ohne Netz |
| `tests/integration/` | WordPress-Testsuite + MySQL | `dbDelta()`-Schema, P3/P4 gegen echte Tabellen, REST-Routen, Capability, SSRF-Allowlist | langsamer, braucht eine Testdatenbank |

Die untere Lage ist die, die täglich läuft; sie ist auch der Grund für die
Trennung aus Abschnitt 5: **der Abruf gehört nicht in den Parser.** Ein Adapter,
der einen String bekommt und Zeilen zurückgibt, ist ohne WordPress prüfbar –
einer, der selbst `wp_remote_get()` aufruft, nicht.

Versionen, damit es nicht am ersten Tag klemmt:

```json
{
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0"
  }
}
```

⚠ **PHPUnit 9, nicht 10 oder 11.** Zwei Gründe, beide hart: Der Plugin-Header
nennt `Requires PHP: 7.4`, und PHPUnit 10+ verlangt PHP 8.1. Unabhängig davon
unterstützt die WordPress-Testsuite PHPUnit 10+ nicht – sie erwartet die
`yoast/phpunit-polyfills`, die genau diese Lücke schließen. Wer mit 10 anfängt,
hat die Integrationslage nie zum Laufen gebracht.

Ablage:

```
composer.json               nur require-dev, kein "autoload" für das Plugin
phpunit.xml.dist            zwei testsuites: unit, integration
tests/bootstrap.php         lädt je nach Suite: nur die Parser, oder WordPress
tests/unit/                 ohne WordPress
tests/integration/          WP_UnitTestCase
tests/fixtures/             die Rohantworten (Abschnitt 10)
.gitignore                  vendor/, .phpunit.result.cache
.distignore                 tests/, phpunit.xml.dist, composer.json
```

⚠ `.distignore` nicht vergessen (bzw. der entsprechende Ausschluss im
Build-Schritt): `tests/` und `phpunit.xml.dist` gehören nicht ins
Auslieferungs-ZIP. Eine mitgelieferte Testsuite ist im WP-Repo unerwünscht und
transportiert die Fixtures – fremde Ergebnislisten – auf jede Installation.

Die Integrationslage wird mit `install-wp-tests.sh` aus dem
WordPress-Develop-Repository eingerichtet (dieselbe Datei, die
`wp scaffold plugin-tests` erzeugt). Sie legt eine eigene Testdatenbank an und
leert sie bei jedem Lauf – **niemals auf die Datenbank der Installation
zeigen**, in der die 6 000 Zeilen Vereinsgeschichte liegen.

Zeitliche Einordnung: Die Unit-Lage entsteht **mit M1**, nicht danach – sie ist
der Grund, warum M1 als „eine Ettlingen-Liste kommt normalisiert aus dem
Adapter" überhaupt prüfbar ist. Die Integrationslage kommt mit M3 (erster
Schreibvorgang) und wächst mit M6 (REST).

- [x] Fixtures beschaffen und unter `tests/fixtures/` ins Repository legen
      (Abschnitt 10) – sie sind der einzige Weg, die Adapter zu prüfen, ohne
      die Portale zu treffen
      → race result ist da (`raceresult-375768-config.json`,
        `raceresult-375768-contest2.json`, 658 Datensätze, abgerufen 2026-09-01).
      → Die drei Runtix-Fixtures liegen jetzt auch da (2026-09-02).
        ⚠ Sie sind **nachgebaut, keine Byte-Kopien**: in der Umgebung, in der sie
        entstanden, war runtix.com nur über einen Browser erreichbar, und der gab
        rohes Markup nicht heraus. Extrahiert wurden Klassennamen und Textinhalte,
        daraus sind die Dateien geschrieben. Was daran live geprüft ist und was
        nicht, steht ausführlich in `tests/README.md` – kurz: alle elf
        Spaltenklassen samt `col-time ` mit Leerzeichen, alle Kopftexte, alle
        übernommenen Werte, die Optionen beider Selects, der Aufbau der
        Übersichtszeilen und die Fußzeile. Nicht geprüft: Einrückung, Script- und
        Style-Blöcke, und Sonderzustände, die an dem Tag nicht auf der Seite
        standen (eine DNF-Zeile etwa).
        Die `curl`-Zeilen für den Tag, an dem jemand sie echt ziehen kann, stehen
        weiter in `tests/README.md`.
- [x] `composer.json` mit `require-dev` (PHPUnit ^9.6, phpunit-polyfills ^2.0),
      `vendor/` in `.gitignore` – **kein** Composer-Autoloader im Plugin
- [x] `phpunit.xml.dist` mit den zwei Suites, `tests/bootstrap.php`
- [ ] `install-wp-tests.sh` + eigene Testdatenbank für die Integrationslage
- [x] `.distignore` (bzw. Build-Ausschluss) für `tests/`, `phpunit.xml.dist`,
      `composer.json`
- [x] Parser so schneiden, dass sie einen String entgegennehmen und keine
      WordPress-Funktion brauchen (5) – dann laufen die Adapter-Tests ohne
      WordPress und ohne Netz

- [x] Unit-Test `RaceResultAdapter` gegen Referenz-Fixture (658 Zeilen Ettlingen)
- [x] Unit-Test `RuntixAdapter` gegen gespeicherte HTML-Fixture
      → `tests/unit/runtix-adapter-test.php`, 22 Zeilen aus der Liste zu Event 3152
- [x] Beide Adapter → identisches Zielschema (Contract-Test)
      → `tests/unit/adapter-contract-test.php`. Geprüft wird je Adapter: Feldnamen
        und Typen jeder Zeile, die Zusagen des Schemas (Zeit `HH:MM:SS` oder Zeile
        verworfen, Nach- und Vorname nicht leer, Geschlecht in `m|f|''`, Jahrgang 0
        oder plausibel, Platz ohne Punkt), die Trichter-Kennzahlen
        (`gelesen === verworfen + geliefert`), Wettbewerbs- und Listen-Objekte
        (String-Keys, höchstens **eine** Gesamtwertung), die Struktur von `datum()`
        auch im Leerfall, `quelleUrl()` (https und vom eigenen Adapter wiedererkannt),
        die Form der Allowlist (keine nackte Wildcard, kein `*.com`) und dass Müll
        als `LSG_BL_Quelle_Exception` mit einem Satz für Menschen herauskommt.
      → ⚠ Der erste Test der Datei zählt die Adapterdateien im Verzeichnis und
        vergleicht mit der Liste im Test. Ohne das wäre der Contract-Test wertlos:
        ein dritter Adapter würde einfach nicht mitgeprüft.
- [ ] Zeit-Parser: `01:11:54.9` → `01:11:55`, `01:11:54.0` → `01:11:54`
      (kein Float-Rundungsfehler), `1:13:08` → `01:13:08`, `38:57` → `00:38:57`
- [x] Zeit-Parser: `18:57.3` und `18:57,3` → `00:18:58` (MM:SS mit Zehntel –
      der Fall, der ohne eigene Behandlung durchrutscht)
- [x] Zeit-Parser: `01:11:59.9` → `01:12:00` (Übertrag über Minute und Stunde)
- [x] Zeit-Parser: `.000` und `.004` – die erste rundet nicht, die zweite schon
- [x] Zeit-Parser: nicht erkannte Schreibweise liefert `''` und die Zeile wird
      verworfen – **kein** Rückfall auf den Zahlen-Fallback
- [ ] `dbDelta()`: `lsg_bl_install_schema()` zweimal hintereinander aufrufen →
      beim zweiten Mal keine `ALTER TABLE` (Query-Log prüfen)
- [x] Zeit-Parser: DNF / DSQ / DNS / leere Zeit werden verworfen und gezählt
- [ ] Manueller Abgleich: Top 10 einer Liste gegen die Website
- [x] Test mit leerer / noch nicht veröffentlichter Ergebnisliste
- [x] Test mit deaktiviertem Netzwerk: Klartext-Fehlermeldung und Zustand
      `fehler` (6.11) – **kein** Rückfall auf alte Daten. Einen
      stale-while-error-Pfad gibt es nicht (5.2), und ein halb gefüllter
      Import wäre schlimmer als ein sichtbarer Abbruch
      → geprüft mit einer leeren Antwort: `notice-error` mit Klartext, und keine
        alten Daten in der Ausgabe.
- [x] Erkennung: Tabelle aus 6.2 als Testfälle, inkl. URL mit/ohne trailing slash,
      `#2_B45FAB`-Fragment, `runtix.com`-URL ohne `/sts/`
      → beide Teile sind geprüft. Runtix: 90 mit Event-ID, 40 ohne, 0 bei fremdem
        Host – und 0 bei `runtix.com.angreifer.example`. Zusätzlich prüft der
        Contract-Test, dass kein Adapter die URL eines anderen beansprucht.
      → ⚠ **Neu:** die Vorauswahl steht bei Runtix im **Pfad**
        (`/sts/10050/3152/21/total`), nicht in einem Fragment. Dafür gibt es jetzt
        `vorauswahl_aus_url()`, und `lsg_bl_discovery_vorauswahl()` fragt die
        Methode vor `fragment_lesen()`. Ohne das ginge die Vorauswahl auf dem
        Cache-Treffer-Weg verloren – die Seite hätte beim zweiten Aufruf derselben
        Adresse plötzlich keinen Wettbewerb mehr vorbelegt.
- [x] Erkennung: unbekannter Host → kein Adapter, saubere Meldung statt Fehler
- [ ] SSRF: `http://127.0.0.1/`, `file://`, Redirect auf fremden Host → alle geblockt
- [ ] SSRF: manipulierte `config`-Antwort mit `server: "angreifer.example"` →
      Abbruch mit Meldung, **kein** Rückfall auf `my.raceresult.com`
- [x] SSRF: `boeseraceresult.com` und
      `https://angreifer.example/?x=my.raceresult.com` treffen die Allowlist nicht
      → ebenso `raceresult.com.angreifer.example`, `http://127.0.0.1/` und
        `file:///etc/passwd`
- [ ] REST-Routen ohne Login / ohne Nonce → 401/403
- [x] race result: Liste mit `Contest: 0` erscheint bei jedem Wettbewerb
      → im Adapter umgesetzt; die Ettlingen-Fixture enthält keinen solchen
        Eintrag, geprüft ist also die Regel, nicht der Fall
- [x] Runtix: Wettbewerb `"w"` überlebt den Weg durch Attribut, REST und Parser
      → Attribut und Parser sind geprüft (Unit-Test plus ein Durchlauf der
        Import-Seite mit `contest=w`, der „Interstick-Walk" und `value="w"` zeigt).
        Der REST-Weg fehlt noch – die Routen kommen mit M6.
- [x] Wettbewerbswechsel setzt die Listenauswahl zurück (kein Geisterwert)
- [ ] Admin-Seite mit deaktiviertem JavaScript komplett durchklickbar
- [ ] Benutzer ohne `LSG_BL_CAP`: Menüpunkt weg **und** Handler/REST verweigern
- [x] Zweimal derselbe Import → alle Zeilen `gleich`, keine Duplikate
      → geprüft: nach dem zweiten Durchlauf ist `lsg_best` Zeichen für Zeichen
        unverändert, und nichts ist vorausgewählt. Ein zweiter Klick auf denselben
        Token wird zusätzlich abgelehnt – ein Reload darf nicht noch einmal
        schreiben.
- [x] Künstlich angelegte Doppelzeile (Athlet + Distanz + Jahr): P4 nimmt die
      bessere als Bezug, schreibt nur dorthin, meldet „Doppelzeile im Bestand"
      mit beiden ids – dasselbe im Formular (7.3)
- [x] Namens-Splitter: „Körner, Holger", „BORGHARDT Lukas", „von Hoff Anna-Maria",
      „VAN DER BERG Jan-Peter", einteiliger Name
      → dazu `GEIßLER Franziska`, `STÖßER Vivien` und `VAN WEES-SNEL Trees`
        aus der echten Liste
- [x] Vereinsfilter: `LSG Karlsruhe`, `LSG-Karlsruhe`, `lsg karlsruhe e.V.` treffen –
      `LG Region Karlsruhe` und ein leeres Vereinsfeld treffen **nicht**
      → ebenso nicht getroffen: `LSG Weiher`, `(Karlsruhe)` und
        `Karlsruher Lemminge e.V.`
- [x] Athletenzuordnung: `Koerner` findet `Körner`, gleicher Name mit anderem
      Jahrgang findet **nicht**
- [x] Distanz-Mapping: `21 KM Sparkasse Kraichgau-Lauf` → `HM`,
      `42,195 km` → `Marathon`, `5. Ettlinger Marathon` → `Marathon` (nicht `5km`),
      `10 Meilen` → kein Treffer
      → dazu alle neun Ettlinger Wettbewerbsnamen aus der Fixture
- [x] Regel 171: `Pfeiffer, Wolfram` + 1961 → 171; anderer Jahrgang → kein Treffer
- [x] Regel 183: beliebiger Nachname + `Harry` + 1943 → 183
- [x] Regel 377: `Gudrun, Meier` und `Meier, Gudrun` + 1955 → beide 377
      (= Schlippe-Schrieber, Gudrun; **nicht** 337 = Österle, Hans-Jörg, 1967)
- [x] Zwei passende Regeln → `mehrdeutig`, Zeile bleibt offen, beide IDs genannt
- [x] Regel nur mit Jahrgang lässt sich nicht anlegen
- [x] Invariante: Zeilenzahl der Tabelle == LSG-Zahl aus P2, bei jedem Import
- [x] Unbekannter Teilnehmer erscheint in der Tabelle, nicht nur in den Zahlen
- [x] Unbekannter Teilnehmer hat keine Checkbox und wird auch von „Alle" nicht
      angehakt; nach dem Übernehmen steht kein neuer `lsg_athlete`-Datensatz da
- [x] Nach dem Anlegen einer Regel und erneutem Import: die vorher offene Zeile
      ist zugeordnet, die übrigen stehen auf `gleich`
      → geprüft mit dem echten Fall: SIEBERT Fridtjof (1971) fehlt in `lsg_athlete`
        und bleibt offen; nach dem Anlegen sind alle elf zugeordnet, genau eine
        Zeile ist `neu`, die übrigen stehen auf `gleich` bzw. `langsamer`.
- [x] Jeder `offen`-Grund erzeugt seinen eigenen Meldungstext (drei Fälle)
- [x] Zeile ohne Jahrgang in der Quelle → „nennt keinen Jahrgang", nicht
      „kein Athlet gefunden"
- [x] `skip_offen` steht mit Rohdaten im Log, auch wenn nichts geschrieben wurde
- [x] AK-Berechnung: Jahrgang 1993 bei Lauf 2026 → `m30`; unter 30 → `hk`;
      Code nicht in `lsg_ak` → wird geschrieben, Hinweis auf den fehlenden
      Frontend-Filter erscheint
      → beides geprüft: 1993 bei Lauf 2026 → `m30`, 1948 → `w75`, und weil
        `w75` in `lsg_ak` fehlt, erscheint „fehlt im Filter" – der Code wird
        trotzdem geschrieben
- [x] Distanz-Select bietet `6h`/`12h`/`24h` **nicht** an; ein Zeitlauf-Wettbewerb
      erzeugt die Meldung statt eines Imports
      → das Select ist geschlossen; die Meldung bei einem Zeitlauf-Wettbewerb
        kommt mit der Oberfläche (M2)
- [x] Datum: `17.05.2026` im Namen wird erkannt; nur `2026` → Feld unvollständig,
      Parsen gesperrt; gar nichts → Feld leer
      → die Sperre des Parsen-Buttons kommt mit M2. Zusätzlich geprüft: ein
        vollständiges, aber unmögliches Datum (`31.02.2026`) liefert auch keine
        Jahreszahl – wer den Tag falsch schreibt, kann das Jahr genauso falsch
        geschrieben haben
- [x] race result Ettlingen: Datumsfeld bleibt leer, Hinweistext erscheint,
      `ActiveFrom` (2022) taucht nirgends auf
- [x] Beide Wettbewerbe „Walking 21,1km" und „Hauptlauf 21,1km" schlagen `HM`
      vor; das Dropdown ist in beiden Fällen sichtbar und änderbar
      → dass das Dropdown sichtbar und änderbar ist, prüft M2
- [ ] Datum 31.12. → Import zählt ins alte Jahr, auch wenn im Januar importiert
- [ ] Datum 01.01. → Import zählt ins neue Jahr. Derselbe Test mit
      MySQL-Session-Zeitzone auf `UTC` **und** auf `Europe/Berlin`: gleiches
      Ergebnis, weil das Jahr nicht mehr in SQL gerechnet wird (6.5.4)
- [x] `lsg_bl_jahr_grenzen( 2026 )` liefert 31.12.2025 23:00 UTC bis
      31.12.2026 23:00 UTC (Winterzeit, Europe/Berlin) – und **nicht** die
      `mktime()`-Werte
- [ ] Bestandszeile, die auf 00:00 Ortszeit des 1. Januar liegt, wird dem
      richtigen Jahr zugeordnet (Regressionstest für die sechs Altfälle)
- [ ] Frontend und Import sind sich einig: für dieselbe Zeile nennt die
      Jahres-Bestenliste dasselbe Jahr wie der Vergleich in P4
- [x] Schnellere Zeit im Folgejahr → zweite Zeile, Vorjahr unverändert
- [ ] Datum nach dem Parsen geändert → Vorschau verworfen, Button zurück auf „Parsen"
- [x] Gespeicherter Timestamp wird als der eingegebene Tag ausgegeben
      (kein Vortag durch Zeitzone)
- [x] `38:57` gegen `01:38:57` wird korrekt verglichen (kein String-Vergleich)
- [x] Übernahme: `langsamer` angehakt → Bestand unverändert, Log-Eintrag da
- [x] Konflikterkennung: Bestand zwischen Parsen und Übernehmen von außen geändert
      → geprüft: der von außen geänderte Bestand wird als `konflikt` gemeldet, die
        Zeile nicht geschrieben, und der Konflikt steht im Log.
- [x] Log-Suche findet einen Athleten über die Rohschreibweise der Quelle

Manuelle Erfassung (Abschnitt 7):

- [ ] Distanz `12h` gewählt → Feld heißt „Strecke", nimmt `112,737 km` an und
      lehnt `01:36:44` ab; bei `HM` genau umgekehrt
- [ ] Distanzwechsel leert das Leistungsfeld (kein stehengebliebener Wert)
- [ ] `96,723 km` wird unverändert als `96,723 km` gespeichert – **keine**
      führende Null; `96,7 km` wird auf `96,700 km` ergänzt und `96,7234 km`
      abgelehnt (drei Nachkommastellen sind Pflicht)
- [ ] AK aus Jahrgang 1976 bei Lauf 2026 → `m50`, angezeigt und nicht editierbar
- [ ] Jahrgang 1943 bei Lauf 2026 → `m80`, wird ohne Rückfrage gespeichert;
      fehlt `m80` in `lsg_ak`, erscheint der Hinweis auf den Filter
- [ ] Athlet mit `cat = 'f'` → Code beginnt mit `w`, nicht mit `f`
- [ ] Zweiter Eintrag für Athlet + Distanz + Jahr: Vergleich erscheint, es gibt
      **keine** Möglichkeit, eine zweite Zeile anzulegen
- [ ] Langsamere Leistung: Speichern erst nach ausdrücklichem Haken
- [ ] Identische Leistung: Speichern deaktiviert
- [ ] `12h`: `112,737 km` gegen `96,723 km` gilt als besser (nicht als kürzer) –
      und der Vergleich funktioniert auch gegen eine Altzeile `096,723 km`
- [ ] Bearbeiten und dabei das Jahr ändern → Prüfung läuft gegen das neue Jahr
- [ ] Löschen ohne gültige Nonce → abgelehnt
- [ ] Gelöschter Datensatz steht vollständig im Log und lässt sich daraus
      neu eintippen
- [ ] Jede Formularaktion erzeugt genau einen `lsg_import_run` mit
      `adapter = 'manuell'` und genau eine `lsg_import_log`-Zeile
- [ ] Ehemalige Athleten (`active = '0'`) sind wählbar, aber getrennt gruppiert
- [ ] Benutzer ohne `LSG_BL_CAP`: Menüpunkt weg **und** Handler verweigern

---

## 9. Entscheidungen und Ausbaustufen

### 9.1 Entschieden

Festlegungen aus der Abstimmung vom 2026-08-27 – im Text jeweils an der
zuständigen Stelle eingearbeitet, hier nur als Nachweis:

- [x] **`teilnehmer` = roher Namensstring der Quelle**, ungesplittet, für
      Nachvollziehbarkeit im Log (6.5.1, `lsg_import_log.roh_teilnehmer`).
- [x] **Zehntelsekunden werden aufgerundet** (World Athletics). In `lsg_best`
      steht `HH:MM:SS`, die Originalzeit bleibt in `lsg_import_log.roh_zeit` (6.5.1).
- [x] **Jahrgangsklassen**, keine Stichtagsklassen:
      `alter = Veranstaltungsjahr − Jahrgang` (6.5.3).
- [x] **Kein zusätzliches Ergebnisarchiv.** `lsg_import_run` + `lsg_import_log`
      genügen; Nicht-LSG-Zeilen werden nicht gespeichert (6.8).
- [x] Oberfläche als **eigene Admin-Seite**, nicht im Block-Editor (6.1).
- [x] **Nur LSG Karlsruhe** wird übernommen – Vereinsfeld muss `LSG` *und*
      `Karlsruhe` enthalten; `LG Region Karlsruhe` fällt raus (6.5.2).
- [x] **Nettozeit vor Bruttozeit**, verwendeter Typ wird in `zeit_typ`
      mitgeführt und protokolliert (6.5.1).
- [x] **Keine Anfrage bei Runtix/CodeResearch.** Ergebnislisten und Bestenliste
      sind beide öffentlich, es bleibt beim bisherigen Vorgehen. Die
      technischen Rücksichtsmaßnahmen (eigener User-Agent, Abruf nur auf
      Anstoß, Rate-Limit 30 / 10 min, Transient-Cache) bleiben verbindlich
      (3.5). Die frühere Angabe „1 Abruf/Tag" stammt aus dem Cron-Entwurf und
      ist mit 5.2 hinfällig.
- [x] **Keine neuen Distanzen.** `lsg_bl_distance_map()` bleibt geschlossen;
      passt nichts, wird der Import abgelehnt statt die Karte erweitert (6.5.1).
- [x] **Zeitläufe (`6h`, `12h`, `24h`) werden nicht importiert.** Dort hält
      `lsg_best.time` eine Strecke (`112,737 km`), die Parse-Pipeline erzeugt
      aber immer eine Zeit. Das Import-Select bietet nur die neun
      Streckendistanzen an; erfasst werden sie über die Seite aus Abschnitt 7,
      das Frontend zeigt sie unverändert (6.5.1).
- [x] **Eine zweite Admin-Seite „Bestenliste" für die manuelle Erfassung**, und
      zwar für **alle** Distanzen, nicht nur die Zeitläufe. Sie kann Anlegen,
      Bearbeiten und Löschen; der Menüpunkt aus Phase 4 wird damit vorgezogen
      (Abschnitt 7).
- [x] **Manuelle Erfassung nutzt dieselbe Capability wie der Import**
      (`LSG_BL_CAP`). Der Ausgleich für den fehlenden Trichter ist die
      Protokollierung, nicht eine engere Rolle (7.1).
- [x] **Die Jahresbestzeit-Regel gilt auch im Formular, aber warnend.** Es gibt
      keine Option, eine zweite Zeile für Athlet + Distanz + Jahr anzulegen;
      wohl aber, den vorhandenen Eintrag nach einem Vergleich zu überschreiben –
      auch mit einer langsameren Leistung, wenn der Bestand falsch war (7.3).
- [x] **Die Altersklasse wird immer gerechnet, nie eingegeben** – im Import wie
      im Formular, aus Jahrgang, Veranstaltungsjahr und `cat` (6.5.3, 7.2).
- [x] **Manuelle Aktionen laufen in dasselbe Log** (`adapter = 'manuell'`),
      nicht in eine dritte Tabelle. Neu im Wertebereich: `aktion = 'delete'`
      und `match_type = 'manuell'` (7.5).
- [x] **Gesamtsieg wird erkannt und markiert, aber noch nicht geschrieben.**
      `lsg_win` bleibt vorerst Handarbeit; Log-Spalten sind schon da (6.5.5).
- [x] **DSGVO**: keine zusätzliche Maßnahme im Plugin, die Datenschutzerklärung
      auf der Homepage deckt es ab.
- [x] **Jeder angemeldete WordPress-Benutzer darf importieren**
      (`LSG_BL_CAP = 'read'`). Nachvollziehbarkeit über `user_id` im Log (6.2).
- [x] **Läufe kommen ausschließlich über die URL.** Keine gespeicherte
      Event-Liste, keine Event-Verwaltung im Backend – und damit auch kein
      WP-Cron und kein Hintergrundabruf (5.2).
- [x] **Die Frontend-Ausgabe bleibt unverändert**: Bestenliste = laufendes
      Jahr, dazu Ewige Bestenliste und Gesamtsiege. Die drei Blöcke aus
      Phase 3 bleiben, wie sie sind; der Import füllt nur ihre Datenbasis.
- [x] **Keine dritte Quelle angedacht.** Die Adapter-Registry bleibt trotzdem
      bestehen, weil sie in dieser Form nichts kostet (6.12).
- [x] **Ein Vorgang = eine Ergebnisliste.** Mehrere Wettbewerbe eines Events
      werden nacheinander importiert, jeder mit eigenem `lsg_import_run` (6.5).
- [x] **Keine Sonderregeln für einzelne Wettbewerbsarten.** Die
      Wettbewerbsauswahl bestimmt, welche Liste geparst wird; das
      Distanz-Dropdown bestimmt, unter welcher Distanz sie gespeichert wird –
      vorbelegt oder von Hand gewählt. Walking, Staffeln und Bambiniläufe
      brauchen darüber hinaus nichts (6.5.1).
- [x] **Datum und Distanz bleiben leer, wenn sie nicht eindeutig sind.** Kein
      Raten, kein stiller Ersatzwert – der Parsen-Button bleibt gesperrt, bis
      beide Felder stehen (6.5.1).
- [x] **Jahresabfragen laufen über eine Zeitspanne, nicht über
      `YEAR(FROM_UNIXTIME())`.** Die Grenzen kommen aus
      `lsg_bl_jahr_grenzen()` und damit aus `wp_timezone()`; `mktime()` ist
      dafür untauglich, weil WordPress die PHP-Zeitzone auf UTC setzt.
      Umgestellt werden alle fünf Frontend-Abfragen mit, und die sechs
      Neujahrsläufe im Bestand kommen in V1 auf 12:00 Ortszeit (6.5.4).
- [x] **Geprüft wird mit PHPUnit**, in zwei Lagen (`tests/unit/` ohne
      WordPress, `tests/integration/` mit der WordPress-Testsuite). Composer
      nur als Entwicklungsabhängigkeit, `vendor/` und `tests/` gehören nicht
      ins Auslieferungspaket. PHPUnit ^9.6 wegen `Requires PHP: 7.4` und der
      WordPress-Testsuite (Abschnitt 8, Verifikation).
- [x] **Der Bestand wird vor M3 bereinigt, nicht der Plan an ihn angepasst.**
      Die 26 Doppelzeilen aus Athlet + Distanz + Jahr sind Erfassungsfehler;
      die Regel „eine Zeile je Athlet, Distanz und Kalenderjahr" bleibt, wie
      sie ist. Vorarbeit V1, Abschnitt 8 – **ausgeführt am 2026-09-01**.
- [x] **P4 und das Formular haben trotzdem eine Regel für Mehrfachtreffer**:
      beste Zeile als Bezug, nur dorthin schreiben, Zusatz „Doppelzeile im
      Bestand" – kein stilles `LIMIT 1`, kein automatisches Aufräumen im
      Import (6.5.4, 7.3).
- [x] **`lsg_ak` ist eine Anzeigeliste, keine Prüfinstanz.** Der berechnete
      AK-Code wird immer geschrieben; fehlt er in `lsg_ak`, ist das ein
      Hinweis auf den fehlenden Frontend-Filter, keine Warnung vor dem
      Ergebnis. Die Tabelle wird einmalig bis `m95`/`w95` durchgeschrieben
      (Vorarbeit V2) – heute fehlen `m80`, `w75`, `w80`, `w85`, `w90`, die im
      Bestand schon 32-mal vorkommen (6.5.3, 7.2).
- [x] **Weitere Untermenüs** unter `lsg-bestenliste`: Import-Log,
      „Zuordnungen" und „Bestenliste" jetzt, Sportler- und Gesamtsieger-Pflege
      in Phase 4 (6.2).

### 9.2 Später vorgemerkt

Bewusst nicht im ersten Wurf – die Datenstruktur ist aber jeweils schon darauf
vorbereitet, damit später keine Migration nötig wird:

- [ ] **Rückgängig-Funktion für einen Import-Vorgang.** `lsg_import_log.time_alt`
      hält die überschriebene Zeit fest, `run_id` klammert einen Vorgang – das
      Zurückrollen ist damit ein Update je `update`-Zeile und ein Delete je
      `insert`-Zeile. Zwei Dinge dafür beim Bauen im Kopf behalten: ein
      zurückgerollter Vorgang muss als solcher markiert werden (sonst rollt ihn
      jemand zweimal zurück), und ein späterer Import auf denselben Datensatz
      macht das Zurückrollen ungültig – dann darf es nicht mehr angeboten werden.
- [ ] **Gesamtsieg nach `lsg_win` schreiben** (6.5.5). Erkennung und Markierung
      kommen jetzt, das Schreiben später. Spalten `roh_platz`, `gesamtsieg` und
      die Log-Aktion `win_insert` sind bereits vorgesehen.
- [ ] **Bestand nachrechnen.** Ändert sich der Jahrgang eines Athleten, sind
      dessen gespeicherte `lsg_best.ak`-Werte falsch. Das Formular rechnet nur
      die Zeile neu, die es speichert (7.4). Ein Durchlauf über den ganzen
      Bestand – AK neu berechnen, Abweichungen zur Vorschau stellen – ist ein
      eigener Wartungsvorgang, kein Formularknopf.

      Das *Auflösen* vorhandener Doppelzeilen gehört nicht mehr hierher: Es ist
      als Vorarbeit V1 vorgezogen (Abschnitt 8) und damit einmalig erledigt.
      Was bleibt, ist die laufende Kontrolle – eine Abfrage auf
      `GROUP BY athletes_id, distance, jahr HAVING COUNT(*) > 1`, die in einem
      späteren Wartungsvorgang mitlaufen kann. Der Import meldet solche Zeilen
      ohnehin an Ort und Stelle (6.5.4).
- [ ] **Phase 4 der README**: Pflege-Oberflächen für Sportler und Gesamtsiege.
      Die Bestenlisten-Pflege ist mit Abschnitt 7 vorgezogen und damit
      erledigt.

### 9.3 Offen

Entschieden bzw. erledigt sind inzwischen: der Testrahmen (PHPUnit, 9.1), das
fehlende Veranstaltungsdatum zu `lsg_best` id 1649 (von Hand nachgetragen am
2026-09-01) und die Zeitzonenrechnung bei Jahresabfragen (9.1, 6.5.4).

Ein Punkt bleibt:

**`tstamp` ist in `lsg_best` unbrauchbar.** 3 675 der 5 951 Zeilen tragen
`4294967295` – den Maximalwert von `int UNSIGNED`, also den 07.02.2106 –,
weitere 39 tragen `0`; nur 2 237 haben einen plausiblen Wert. Ein
Migrationsartefakt. `lsg_athlete`, `lsg_win` und `lsg_ak` sind sauber.

Heute schadet es nicht: die Spalte wird im Plugin **nirgends gelesen**, sie
steht nur in den vier `CREATE TABLE`s. Der Import würde sie erstmals mit einem
echten Wert füllen (6.7), und sobald irgendwo „zuletzt geändert" sortiert oder
angezeigt wird, stehen 3 675 Zeilen im Jahr 2106. Zur Wahl:

- die 3 714 Zeilen in V1 auf `0` setzen – der Default, und ehrlich „unbekannt";
- oder sie stehen lassen und in Kauf nehmen, dass die Spalte gemischt ist.

*Vorschlag:* auf `0`. Ein Wert, der in 80 Jahren liegt, ist schlimmer als kein
Wert, weil er jede Sortierung anführt.

Was hier neu auftaucht, gehört auch wirklich entschieden, bevor es in
Abschnitt 8 wandert.

## 10. Anhang: verifizierte Requests

```
# Runtix – Ergebnisliste (HTML, 200)
https://runtix.com/sts/10050/3152/21/total

# Runtix – Einzelergebnis
https://runtix.com/sts/10051/3152/21/1126

# Runtix – Veranstaltungsübersicht mit Datum je Lauf (Jahr im Pfad)
https://runtix.com/sts/10020            # laufendes Jahr
https://runtix.com/sts/10020/2025       # verifiziert: 01.01.2025 – 31.12.2025

# Runtix – Veranstaltungsseite, Datum im Ausschreibungstext
https://runtix.com/sts/10021/3152       # „Sonntag, den 16. August 2026"

# race result – Config (enthält KEIN Veranstaltungsdatum, geprüft 2026-08-27)
https://my.raceresult.com/375768/results/config?lang=de&noVisitor=1&sanitize=true
#   Schlüssel: key, contests, splits, eventname, TimerLogo, TimerURL,
#              EventOver, Time, server, BrandColorDark, ListCommentsEnabled,
#              Tab, TabConfig, ContestColors
#   server lieferte diesmal my-us-1.raceresult.com (früher my4) – Wert wechselt
#   Tab.ActiveFrom/ActiveUntil = Gültigkeit der Ansicht, NICHT der Lauf

# race result – robots.txt sperrt /results/list für Crawler
https://my.raceresult.com/robots.txt

# race result – Liste (658 Zeilen)
https://my4.raceresult.com/375768/results/list?key={KEY}
  &listname=01.1_Ergebnisse%7CZieleinlauf_Brutto
  &page=results&contest=2&r=all&l=0
```

### Testdaten

**Stand 2026-09-02: erledigt.** Unter `tests/fixtures/` liegen fünf Dateien:

```
raceresult-375768-config.json     Antwort von /results/config                    (Byte-Kopie)
raceresult-375768-contest2.json   Antwort von /results/list, r=all&l=0 (658)     (Byte-Kopie)
runtix-3152-21-total.html         /sts/10050/3152/21/total, 22 von 234 Zeilen    (nachgebaut)
runtix-10020-2026.html            /sts/10020/2026, 5 von 157 Zeilen              (nachgebaut)
runtix-10021-3152.html            /sts/10021/3152, Datum im Ausschreibungstext   (nachgebaut)
```

Die früher hier gesuchten Referenzdateien `zieleinlauf.csv` /
`zieleinlauf.json` braucht es nicht mehr: die JSON-Rohantwort ist die
Referenz, und der Contract-Test vergleicht nicht gegen eine erwartete Ausgabe,
sondern gegen die **Zusagen** des Zielformats aus 5.1 – Feldnamen, Typen,
Wertebereiche, `gelesen === verworfen + geliefert`. Das ist der robustere
Schnitt: eine erwartete Ausgabe müsste bei jeder neuen Fixture-Zeile
nachgezogen werden und ginge dann als Erstes ein, wenn niemand Zeit hat.

⚠ **Die drei Runtix-Dateien sind nachgebaut, keine Mitschnitte.** In der
Umgebung, in der sie entstanden, war runtix.com nur über einen Browser
erreichbar, und der gab rohes Markup nicht heraus. Extrahiert wurden
Klassennamen und Textinhalte, daraus sind die Dateien geschrieben. Was daran
live geprüft ist – alle elf Spaltenklassen samt `col-time ` mit Leerzeichen,
alle Kopftexte, alle übernommenen Werte, die Optionen beider Selects, der
Aufbau der Übersichtszeilen, die Fußzeile – und was nicht, steht in
`tests/README.md`. Dort stehen auch die `curl`-Zeilen für den Tag, an dem
jemand sie echt ziehen kann; danach sind die Erwartungswerte `22` in
`runtix-adapter-test.php` anzuheben.
