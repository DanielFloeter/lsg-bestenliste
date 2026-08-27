# Plan: WordPress-Plugin "LSG Bestenliste" – Ergebnisimport

> Arbeitsdokument. Abschnitt 7 ist die abarbeitbare Checkliste, Abschnitt 8
> hält die getroffenen Entscheidungen und die vorgemerkten Ausbaustufen fest.
>
> Stand der Recherche: 2026-08-26 (live gegen beide Systeme verifiziert)

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

Nur AI-Trainings-Crawler gesperrt, **kein generelles Disallow**. Höflicher
Abruf (1× pro Lauf pro Tag, eigener User-Agent) ist damit nicht ausgeschlossen.

**Entschieden:** keine Anfrage bei Runtix/CodeResearch. Die Ergebnislisten sind
öffentlich, die Bestenliste des Vereins ist es auch, und es bleibt beim
bisherigen Vorgehen. Die technischen Rücksichtsmaßnahmen bleiben trotzdem
verbindlich, weil sie den Abruf überhaupt erst unauffällig machen:

- eigener User-Agent mit Kontakt-URL, keine Browser-Tarnung
- ein Abruf pro Lauf und Tag, nicht bei jedem Seitenaufruf (5.2)
- Rate-Limit von 30 Abrufen / 10 min pro Benutzer (6.10)
- Ergebnisse kommen aus dem Cache, nicht aus wiederholten Requests

Damit erzeugt das Plugin pro Import ein bis zwei Requests – weniger als ein
einzelner Besucher, der sich die Liste im Browser ansieht.

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
Das ist mit den Festlegungen aus 8.1 hinfällig, und zwar aus einem
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
die ersten beiden gehören zu diesem Plan, der Rest zu Phase 4 der README:

| Untermenü | Slug | Inhalt | Wann |
|---|---|---|---|
| Ergebnis-Import | `lsg-bestenliste` | Abschnitt 6 | jetzt |
| Import-Log | `lsg-bestenliste-log` | Abschnitt 6.8 | jetzt |
| Zuordnungen | `lsg-bestenliste-map` | Regeln aus `lsg_athlete_map` (6.5.3) | jetzt |
| Sportler | `lsg-bestenliste-athleten` | `lsg_athlete` pflegen | Phase 4 |
| Bestenliste | `lsg-bestenliste-best` | `lsg_best` von Hand korrigieren | Phase 4 |
| Gesamtsiege | `lsg-bestenliste-win` | `lsg_win` pflegen, später Ziel von 6.5.5 | Phase 4 |

Zwei Dinge, die dabei jetzt schon festgelegt sein sollten, weil sie später
teuer nachzurüsten sind:

- **Ein Callback pro Seite, eine Datei pro Callback**
  (`includes/admin/page-import.php`, `page-log.php`, …). Nicht alles in eine
  wachsende `admin.php`.
- **`LSG_BL_CAP` gilt nicht überall.** Importieren darf jeder Angemeldete;
  Athleten- und Bestenlisten-Pflege sind Eingriffe in den Datenbestand und
  bekommen in Phase 4 eine eigene, engere Konstante. Die Menüpunkte dafür
  jetzt schon mit einem eigenen Capability-Platzhalter registrieren, statt
  später alle Aufrufe zu suchen.

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

⚠ Zwei Dinge, die aus dieser Entscheidung folgen und im Code stehen müssen:

- **`read` ist nicht „egal".** Nicht angemeldete Besucher haben diese
  Capability nicht – die Prüfung muss trotzdem in jedem Handler stehen, sonst
  ist der Import ein offener Endpunkt, über den Fremde Requests an
  Drittserver auslösen können.
- **Nachvollziehbarkeit ersetzt die Zugriffsbeschränkung.** Wenn viele Leute
  schreiben dürfen, muss ablesbar sein, wer was getan hat: `user_id` steht
  in `lsg_import_run`, das Log (6.8) hält jede Aktion fest, und
  `lsg_best`-Einträge lassen sich darüber zurückverfolgen.

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
geschrieben; das Zwischenergebnis liegt im Transient aus 6.4.

An zwei Stellen braucht der Import eine Übersetzung zwischen dem, was die
Quelle schreibt, und dem, was die Datenbank kennt:

| | Von | Nach | Wo |
|---|---|---|---|
| **Mapping 1** | Wettbewerbsbezeichnung („21 KM …") | Distanzcode (`HM`) | 6.5.1, im Code |
| **Mapping 2** | Teilnehmerzeile (Name + Jahrgang) | `lsg_athlete.id` | 6.5.3, Tabelle `lsg_athlete_map` |

Mapping 1 steht im Code, weil die Zielcodes feststehen (8.1: keine neuen
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

**Zeit** – Nettozeit hat Vorrang. race result führt sie je nach Liste als
eigenes Feld (`Netto`, `Nettozeit`, `Net`, `Chip`); der Adapter sucht diese
Labels in `DataFields`, und erst wenn keines existiert, nimmt er die
Bruttozeit. Runtix liefert nur eine Zeit. Welche es war, wird in `zeit_typ`
mitgeführt und im Log festgehalten – sonst vergleicht man später Netto gegen
Brutto, ohne es zu merken.

Normalisierung auf das Format von `lsg_best.time` (`varchar(15)`, `HH:MM:SS`):

```
1:13:08      →  01:13:08
01:11:54.9   →  01:11:55     Zehntel aufgerundet (World-Athletics-Regel)
38:57        →  00:38:57
DNF/DSQ/DNS  →  Zeile verwerfen, in P1-Warnungen zählen
```

**Entschieden:** Zehntel werden nach der World-Athletics-Regel **aufgerundet**
(`01:11:54.1` → `01:11:55`), nicht abgeschnitten. In `lsg_best.time` landet
immer ein sekundengenauer Wert `HH:MM:SS`, passend zum Bestand. Die
Originalzeit inklusive Zehntel bleibt im Log (`roh_zeit`) erhalten, falls
später doch einmal jemand nachrechnen will.

```php
// Aufrunden auf volle Sekunden, ohne Float-Rundungsfehler
if ( preg_match( '/^(\d{1,3}):([0-5]\d):([0-5]\d)[.,](\d+)$/', $raw, $m ) ) {
    $sek = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    if ( (int) $m[4] > 0 ) {          // jede Zehntelstelle > 0 rundet auf
        $sek++;
    }
}
```

⚠ `ceil()` auf einem Float wäre hier falsch: `(float) '54.9'` ist nicht exakt
darstellbar, und bei `.0` würde ein Rundungsfehler eine Sekunde erfinden.
Deshalb der Vergleich auf dem Nachkommastring.

**Distanz und Ort** kommen *nicht* aus der Zeile, sondern aus dem Wettbewerb.
Sie gelten für den gesamten Import und werden über der Tabelle als Felder
angeboten:

- `distanz` – Select mit den kanonischen Codes aus `lsg_bl_distance_map()`
  (`5km`, `10km`, `HM`, `Marathon`, …), vorbelegt durch eine Heuristik auf
  den Wettbewerbsnamen: `21 KM Sparkasse Kraichgau-Lauf` → `HM`,
  `10 KM Linhardt-Lauf` → `10km`. Die Heuristik darf danebenliegen, deshalb
  ist das Feld **immer sichtbar und änderbar**, nie versteckt.

  **Entschieden: die Liste der Distanzen bleibt geschlossen.** Es kommen keine
  Distanzen dazu, die nicht schon in der Datenbank stehen. Der Import kann
  deshalb gar keine neue Distanz erzeugen – ein Select über die zwölf
  bekannten Codes, kein Freitextfeld.

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
        '6h' => '6h',    '6stunden'  => '6h',
        '12h'=> '12h',   '12stunden' => '12h',
        '24h'=> '24h',   '24stunden' => '24h',
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

⚠ **Sonderfall Walking.** Runtix führt den Wettbewerb `"w"`
(„5 KM Interstick-Walk") gleichberechtigt neben den Läufen. Rein technisch
wären das 5 km, inhaltlich gehört ein Walking-Ergebnis aber nicht in eine
Lauf-Bestenliste. Vorgeschlagenes Verhalten: enthält der Wettbewerbsname
`walk`, `walking` oder `nordic`, wird **keine** Distanz vorbelegt und ein
Hinweis angezeigt – importieren lässt es sich weiterhin, aber nur mit
bewusster Auswahl (siehe 8.3).

- `ort` – aus dem Eventnamen abgeleitet, frei überschreibbar
  (`lsg_best.town`, `varchar(30)` – Länge prüfen).
- `datum` – Veranstaltungsdatum, als Unix-Timestamp in `lsg_best.date`.
  Bestimmt das Jahr für P4. Vorbelegt aus den Adapter-Metadaten, änderbar.

Ohne gültige Distanz kein P4 – der Parsen-Button bleibt gesperrt, solange
diese drei Felder nicht stehen.

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

| Fall | Regel | Was daran besonders ist |
|---|---|---|
| `171` | Vorname `wolfram` **und** Nachname `pfeiffer` **und** Jg. 1961 | der Normalfall: beide Felder gesetzt |
| `183` | Vorname `harry` **und** Jg. 1943 | **kein Nachname** – der variiert in den Listen |
| `337` | `gudrun` als Vor- **oder** Nachname **und** Jg. 1955 | Felder sind in der Quelle vertauscht |

Daraus folgt das Tabellenmodell: leeres Feld = beliebig, plus ein Modus für
den feldunabhängigen Vergleich.

```sql
CREATE TABLE lsg_athlete_map (
  id           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  tstamp       int(10) UNSIGNED NOT NULL DEFAULT 0,
  athletes_id  int(10) UNSIGNED NOT NULL,             -- Ziel in lsg_athlete
  born         year(4)      NOT NULL DEFAULT 0000,    -- Pflicht, immer
  vorname      varchar(30)  NOT NULL DEFAULT '',      -- normalisiert; '' = beliebig
  nachname     varchar(30)  NOT NULL DEFAULT '',      -- normalisiert; '' = beliebig
  modus        varchar(8)   NOT NULL DEFAULT 'feld',  -- 'feld' | 'egal'
  aktiv        tinyint(1)   NOT NULL DEFAULT 1,
  notiz        varchar(255) NOT NULL DEFAULT '',      -- warum es diese Regel gibt
  user_id      bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY lookup (born, aktiv),
  KEY athlete (athletes_id)
) ;
```

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
  (UNIX_TIMESTAMP(), 337, 1955, 'gudrun',  '',         'egal',
   'Vor- und Nachname in der Quelle vertauscht');
```

Regel `337` braucht `nachname` nicht: im Modus `egal` genügt das eine Token
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
`lsg_best` muss in sich konsistent bleiben. Der berechnete Code wird gegen
`lsg_ak` validiert; existiert er dort nicht, gibt es eine Warnung statt eines
stillen Schreibvorgangs. Weicht die berechnete Klasse von der Quelle ab, wird
das als Hinweis angezeigt – bei einem Veranstalter, der nach Stichtag wertet,
ist diese Abweichung erwartbar und kein Fehler.

#### 6.5.4 P4 – Gegen `lsg_best` abgleichen

Für jede zugeordnete Zeile wird geprüft, ob für **denselben Athleten, dieselbe
Distanz und dasselbe Jahr** bereits ein Eintrag existiert:

```sql
SELECT id, time, town, date
  FROM lsg_best
 WHERE athletes_id = %d
   AND distance    = %s
   AND YEAR(FROM_UNIXTIME(`date`)) = %d
```

Das Jahr kommt aus dem Veranstaltungsdatum (6.5.1), nicht aus `date('Y')` –
ein im Januar nachgetragener Dezemberlauf gehört ins Vorjahr.

Verglichen wird über `lsg_bl_parse_performance()`, nicht über `strcmp` – die
Funktion kennt bereits die Formatvarianten des Bestands und behandelt
Zeitläufe (`6h`, `12h`, `24h`) richtig, wo **größer** besser ist. Ein
String-Vergleich würde bei `38:57` gegen `01:38:57` falsch liegen.

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
Idempotenz aus Abschnitt 7 – sichtbar gemacht, statt nur behauptet.

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

Umsetzungsdetails:

- Der Statusvergleich aus P4 wird **unmittelbar vor dem Schreiben wiederholt**.
  Zwischen Parsen und Übernehmen liegt eine Benutzerentscheidung, in der eine
  zweite Person denselben Import gemacht haben kann. Weicht der Status ab, wird
  die Zeile nicht geschrieben, sondern als `konflikt` gemeldet.
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

```sql
-- Der Vorgang: ein Datensatz je Klick auf „Übernehmen"
CREATE TABLE lsg_import_run (
  id            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  tstamp        int(10) UNSIGNED NOT NULL DEFAULT 0,   -- Konvention wie lsg_best
  user_id       bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  adapter       varchar(32)  NOT NULL DEFAULT '',      -- 'raceresult' | 'runtix'
  source_url    varchar(255) NOT NULL DEFAULT '',
  event_id      varchar(32)  NOT NULL DEFAULT '',
  event_name    varchar(120) NOT NULL DEFAULT '',
  event_date    int(10) UNSIGNED DEFAULT NULL,         -- = lsg_best.date
  contest_id    varchar(32)  NOT NULL DEFAULT '',      -- String! ("w")
  contest_name  varchar(120) NOT NULL DEFAULT '',
  list_id       varchar(64)  NOT NULL DEFAULT '',
  list_name     varchar(120) NOT NULL DEFAULT '',
  distance      varchar(15)  NOT NULL DEFAULT '',      -- kanonischer Code
  town          varchar(30)  NOT NULL DEFAULT '',
  zeit_typ      varchar(8)   NOT NULL DEFAULT '',      -- 'netto' | 'brutto'
  cnt_gelesen   int(10) UNSIGNED NOT NULL DEFAULT 0,   -- P1
  cnt_lsg       int(10) UNSIGNED NOT NULL DEFAULT 0,   -- P2
  cnt_zugeordnet int(10) UNSIGNED NOT NULL DEFAULT 0,  -- P3
  cnt_angelegt  int(10) UNSIGNED NOT NULL DEFAULT 0,
  cnt_aktualisiert int(10) UNSIGNED NOT NULL DEFAULT 0,
  cnt_uebersprungen int(10) UNSIGNED NOT NULL DEFAULT 0,
  cnt_fehler    int(10) UNSIGNED NOT NULL DEFAULT 0,
  status        varchar(16)  NOT NULL DEFAULT '',      -- 'uebernommen'|'fehler'|'abgebrochen'
  note          text         NULL,
  PRIMARY KEY  (id),
  KEY zeit (tstamp),
  KEY event (event_id, contest_id)
) ;

-- Die Zeilen: ein Datensatz je Ergebnis, auch für nicht geschriebene
CREATE TABLE lsg_import_log (
  id            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id        int(10) UNSIGNED NOT NULL,
  tstamp        int(10) UNSIGNED NOT NULL DEFAULT 0,
  athletes_id   int(10) UNSIGNED NOT NULL DEFAULT 0,   -- 0 = nicht zugeordnet
  best_id       int(10) UNSIGNED NOT NULL DEFAULT 0,   -- betroffene Zeile in lsg_best
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
  roh_jahrgang  year(4)     NOT NULL DEFAULT 0000,
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
) ;
```

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
win_insert         Gesamtsieg nach lsg_win geschrieben  ← reserviert (6.5.5)
```

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
  `lsg_best`-Datensatz, sobald es dafür eine Bearbeitungsansicht gibt (Phase 4).
- Aufbewahrung: unbegrenzt. Bei wenigen hundert Zeilen pro Jahr ist Aufräumen
  unnötiger Aufwand; falls doch, ein `Löschen älter als …`-Knopf statt eines
  automatischen Cron-Jobs, der unbemerkt Historie wegwirft.

Ein **Rückgängig** ist damit prinzipiell möglich (`time_alt` steht ja da), aber
bewusst nicht Teil des ersten Wurfs – siehe 8.2.

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
| `/import/uebernehmen` | POST | `token`, `zeilen[]` (angehakte) | `{ run_id, angelegt, aktualisiert, uebersprungen, konflikte, ergebnisse[] }` |

`trichter` ist das Zählwerk aus 6.5: `{ gelesen, lsg, zugeordnet, neu,
schneller, langsamer, gleich, offen }`.

Eine Route zum Zuordnen einzelner Zeilen gibt es nicht: der Import legt keine
Athleten an und schreibt keine Regeln (6.5.3). Wer eine offene Zeile auflösen
will, pflegt Athlet oder Regel im jeweiligen Untermenü und führt den Import
erneut aus.

Die Formular-Handler an `admin-post.php` rufen dieselben Funktionen auf – die
REST-Schicht ist nur ein zweiter Eingang, keine zweite Implementierung.

**SSRF-Absicherung** (nicht optional):

- Nur `http`/`https`, keine anderen Schemata.
- Host-Allowlist aus der Adapter-Registry: eine URL wird nur abgerufen, wenn
  ein Adapter sie mit Score > 0 beansprucht. Damit ist die Allowlist
  automatisch identisch mit der Menge der unterstützten Portale.
- `wp_safe_remote_get()` statt `wp_remote_get()` – blockt private IP-Bereiche.
- Redirects auf nicht beanspruchte Hosts abbrechen (`redirection` begrenzen
  und Ziel-Host erneut prüfen).
- Rate-Limit pro Benutzer: max. 30 Abrufe / 10 min (Transient-Zähler).

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
3. Fixture + Contract-Test ergänzen (Abschnitt 7, Verifikation).

An der Oberfläche ist **nichts** zu ändern: Adapterauswahl, Wettbewerbs- und
Listen-Select sind generisch. Liefert ein Adapter für `listen()` ein leeres
Array, blendet die Seite das zweite Feld von selbst aus.

**Eine dritte Quelle ist derzeit nicht angedacht** (8.1). Die Registry
bleibt trotzdem so, wie sie ist: sie kostet in dieser Form nichts – ein Array
und ein Filter – und erspart, falls doch einmal ein Portal dazukommt, das
Aufbrechen einer `if/else`-Kette, die sich quer durch Erkennung, Discovery und
Datenabruf zieht. Denkbare Kandidaten wären DLV / ladv.de, Davengo oder
Zeitmessung Barth; geplant ist keiner davon.

---

## 7. Umsetzungsschritte

- [ ] Plugin-Grundgerüst (Header, Autoloader, Activation/Deactivation-Hooks)
- [ ] Datenmodell: Zusatztabellen `lsg_athlete_map`, `lsg_import_run`,
      `lsg_import_log` per `dbDelta()` in `lsg_bl_activate()`
- [ ] `ErgebnisQuelle`-Interface + `Ergebnis`-Value-Object
- [ ] `RaceResultAdapter`
  - [ ] `config` abrufen, `key` + `server` extrahieren
  - [ ] Listen auflisten / per `Name` auflösen
  - [ ] `list` abrufen mit `r=all&l=0`
  - [ ] Feld-Mapping über `DataFields`
- [ ] `RuntixAdapter`
  - [ ] URL-Builder (ohne trailing slash!)
  - [ ] Contest-IDs als String, `"w"`-Fall berücksichtigen
  - [ ] DOMXPath-Parser nach CSS-Klassen
  - [ ] `col-ageclass` vs. `col-place-ageclass` exakt trennen
  - [ ] Umlaut-/Encoding-Test (Körner, Häffner, Säckingen)
- [ ] Zeit-Parser: `01:11:54.9`, `1:13:08`, evtl. `MM:SS` → einheitlich Sekunden
- [ ] Normalisierung: `lsg_bl_verein_normalisieren()` + Namens-Normalisierung
      (Umlaute, Bindestriche, Groß/Klein) – Basis für P2 und P3
- [ ] Transient-Caching innerhalb eines Vorgangs (Discovery 15 min, Parse 1 h)
- [ ] Adapter-Registry + Filter `lsg_bl_ergebnis_adapter`
- [ ] Admin-Seite „Ergebnis-Import" (Abschnitt 6)
  - [ ] Top-Level-Menü `lsg-bestenliste` + Konstante `LSG_BL_CAP`
  - [ ] Schritt 1: URL-Feld + Adapter-Erkennung, Adapter manuell übersteuerbar
  - [ ] Schritt 2: Wettbewerbs-Select, Listen-Select (ausblenden bei ≤ 1 Liste)
  - [ ] Schritt 3: Parsen-Button, Vorschautabelle, Übernehmen/Verwerfen
  - [ ] Alle elf Zustände aus 6.11 darstellen (inkl. Fehler mit Klartext)
  - [ ] Formular-Roundtrip über `admin-post.php` (funktioniert ohne JS)
  - [ ] `assets/js/admin-import.js` für den Ablauf ohne Reload
  - [ ] `AbortController` gegen Race Condition beim schnellen Umschalten
  - [ ] Assets nur auf dieser Seite laden (`$hook`-Vergleich)
  - [ ] Nonce + `check_admin_referer()` + Capability-Prüfung in jedem Handler
- [ ] Parse-Pipeline P1–P4 (Abschnitt 6.5)
  - [ ] P1 Namens-Splitter: Komma-Form, GROSSBUCHSTABEN-Form, Fallback + `namen_unsicher`
  - [ ] P1 Geschlecht aus dem AK-Code der Quelle (`M 30`, `1. M35`) → `m`/`f`
  - [ ] P1 Netto vor Brutto, `zeit_typ` mitführen
  - [ ] P1 Zeit-Normalisierung auf `HH:MM:SS`, DNF/DSQ/DNS verwerfen + zählen
  - [ ] P1 Felder Distanz / Ort / Datum über der Tabelle, vorbelegt + änderbar
  - [ ] P1 `lsg_bl_distance_aliases()`: 21→HM, 42→Marathon, Zahl+Name
  - [ ] P1 Distanzwort schlägt Zahl (Marathon vor 42, „5. Ettlinger Marathon")
  - [ ] P1 Walking-Wettbewerbe: keine Distanz vorbelegen
  - [ ] P1 Distanz-Select geschlossen: kein Freitext, keine neuen Distanzen
  - [ ] P1 `platz` mitlesen (nur für 6.5.5)
  - [ ] P2 `lsg_bl_ist_lsg()` (LSG **und** Karlsruhe, normalisiert)
  - [ ] P2 Block „nicht übernommene Vereine" + Vereins-Alias-Option
  - [ ] P3 Zuordnungsstufen exakt → regel → normalisiert → offen
  - [ ] P3 Tabelle `lsg_athlete_map` + Startdatensatz (171, 183, 337)
  - [ ] P3 Regel-Lookup: Modus `feld`/`egal`, leeres Feld = beliebig
  - [ ] P3 Mehrfachtreffer → `mehrdeutig`, Zeile bleibt offen
  - [ ] P3 Regel ohne Vor- und Nachname beim Anlegen ablehnen
  - [ ] P3 Nicht zugeordnete Teilnehmer anzeigen, aber **nicht** importieren
  - [ ] P3 Keine Checkbox an `offen`/`mehrdeutig`-Zeilen
  - [ ] P3 Grund im Klartext (drei Fälle), Rohdaten der Quelle an der Zeile
  - [ ] P3 Vorschlagsliste ähnlicher Athleten mit Jahrgang (nur Lesehilfe)
  - [ ] P3 Meldung über der Tabelle: „N Teilnehmer ohne Zuordnung"
  - [ ] Der Import legt **keine** Athleten an und schreibt **keine** Regeln
  - [ ] Untermenü „Zuordnungen" zum Pflegen der Regeln
  - [ ] P3 AK-Berechnung aus Jahrgang + Veranstaltungsjahr, gegen `lsg_ak` prüfen
  - [ ] P4 Abgleich Athlet + Distanz + Jahr gegen `lsg_best`
  - [ ] P4 Vergleich über `lsg_bl_parse_performance()` (Zeitläufe: größer ist besser)
  - [ ] P4 Status neu / schneller / langsamer / gleich / offen
- [ ] Gesamtsieg (Abschnitt 6.5.5) – **nur Erkennung und Markierung**
  - [ ] Platz 1 erkennen, aber ausschließlich in der Gesamtwertung
  - [ ] 🏆 in der Übernahme-Tabelle + Hinweis über der Tabelle
  - [ ] Spalten `roh_platz`, `gesamtsieg` im Log anlegen (leer nutzen)
  - [ ] **Nicht** nach `lsg_win` schreiben – späterer Ausbaustand
- [ ] Übernahme-Oberfläche (Abschnitt 6.6)
  - [ ] Checkbox je Zeile, Vorauswahl nur `neu` + `schneller`
  - [ ] Kopf-Checkbox „Alle" (`offen`-Zeilen ausgenommen)
  - [ ] Statusspalte im Klartext mit alter und neuer Zeit
  - [ ] Button mit Anzahl im Label, bei 0 Auswahl deaktiviert
- [ ] Schreiblogik (Abschnitt 6.7)
  - [ ] INSERT bei `neu`, UPDATE bei `schneller`, nichts bei `langsamer`/`gleich`
  - [ ] Statusvergleich unmittelbar vor dem Schreiben wiederholen → `konflikt`
  - [ ] Alles in einer Transaktion, `$wpdb->insert()/update()` mit Formaten
- [ ] Import-Log (Abschnitt 6.8)
  - [ ] Tabellen `lsg_import_run` + `lsg_import_log` in `lsg_bl_activate()`
  - [ ] Auch die Nicht-Aktionen protokollieren (`skip_*`, `konflikt`)
  - [ ] Log-Ansicht als `WP_List_Table`: Suche, Filter, zwei Ebenen
- [ ] REST-Routen `lsg/v1/import/*` mit `current_user_can('edit_posts')`
  - [ ] `wp_safe_remote_get()`, Host-Allowlist aus der Registry, Redirect-Prüfung
  - [ ] Rate-Limit pro Benutzer (Transient-Zähler)
  - [ ] Discovery-Cache (Transient, 15 min) – **ohne** den rotierenden `key`
  - [ ] Parse-Ergebnis in Transient (1 h), Persistenz erst bei „Übernehmen"
  - [ ] Formular-Handler und REST-Route rufen dieselbe Funktion (keine Doppel-Logik)
- [ ] Weitere Untermenüs unter `lsg-bestenliste` (6.2) – Import-Log zuerst,
      Sportler-/Bestenlisten-/Gesamtsieger-Pflege aus Phase 4 danach
- [ ] **Keine** Event-Verwaltung: Läufe kommen ausschließlich über die URL
- [x] ~~Frontend: Shortcode und/oder Block für die Bestenliste~~ – erledigt,
      die drei Blöcke stehen (Phase 3 der README)
- [x] ~~Sortierung/Filter (AK, Geschlecht, Jahr, Verein)~~ – erledigt
- [ ] i18n (`load_plugin_textdomain`)
- [ ] Fehlerbehandlung + Logging (kein `error_log` in Produktion)

### Verifikation

- [ ] Unit-Test `RaceResultAdapter` gegen Referenz-Fixture (658 Zeilen Ettlingen)
- [ ] Unit-Test `RuntixAdapter` gegen gespeicherte HTML-Fixture
- [ ] Beide Adapter → identisches Zielschema (Contract-Test)
- [ ] Zeit-Parser: `01:11:54.9` → `01:11:55`, `01:11:54.0` → `01:11:54`
      (kein Float-Rundungsfehler), `1:13:08` → `01:13:08`, `38:57` → `00:38:57`
- [ ] Zeit-Parser: DNF / DSQ / DNS / leere Zeit werden verworfen und gezählt
- [ ] Manueller Abgleich: Top 10 einer Liste gegen die Website
- [ ] Test mit leerer / noch nicht veröffentlichter Ergebnisliste
- [ ] Test mit deaktiviertem Netzwerk (Cache-Fallback greift?)
- [ ] Erkennung: Tabelle aus 6.2 als Testfälle, inkl. URL mit/ohne trailing slash,
      `#2_B45FAB`-Fragment, `runtix.com`-URL ohne `/sts/`
- [ ] Erkennung: unbekannter Host → kein Adapter, saubere Meldung statt Fehler
- [ ] SSRF: `http://127.0.0.1/`, `file://`, Redirect auf fremden Host → alle geblockt
- [ ] REST-Routen ohne Login / ohne Nonce → 401/403
- [ ] race result: Liste mit `Contest: 0` erscheint bei jedem Wettbewerb
- [ ] Runtix: Wettbewerb `"w"` überlebt den Weg durch Attribut, REST und Parser
- [ ] Wettbewerbswechsel setzt die Listenauswahl zurück (kein Geisterwert)
- [ ] Admin-Seite mit deaktiviertem JavaScript komplett durchklickbar
- [ ] Benutzer ohne `LSG_BL_CAP`: Menüpunkt weg **und** Handler/REST verweigern
- [ ] Zweimal derselbe Import → alle Zeilen `gleich`, keine Duplikate
- [ ] Namens-Splitter: „Körner, Holger", „BORGHARDT Lukas", „von Hoff Anna-Maria",
      „VAN DER BERG Jan-Peter", einteiliger Name
- [ ] Vereinsfilter: `LSG Karlsruhe`, `LSG-Karlsruhe`, `lsg karlsruhe e.V.` treffen –
      `LG Region Karlsruhe` und ein leeres Vereinsfeld treffen **nicht**
- [ ] Athletenzuordnung: `Koerner` findet `Körner`, gleicher Name mit anderem
      Jahrgang findet **nicht**
- [ ] Distanz-Mapping: `21 KM Sparkasse Kraichgau-Lauf` → `HM`,
      `42,195 km` → `Marathon`, `5. Ettlinger Marathon` → `Marathon` (nicht `5km`),
      `10 Meilen` → kein Treffer
- [ ] Regel 171: `Pfeiffer, Wolfram` + 1961 → 171; anderer Jahrgang → kein Treffer
- [ ] Regel 183: beliebiger Nachname + `Harry` + 1943 → 183
- [ ] Regel 337: `Gudrun, Meier` und `Meier, Gudrun` + 1955 → beide 337
- [ ] Zwei passende Regeln → `mehrdeutig`, Zeile bleibt offen, beide IDs genannt
- [ ] Regel nur mit Jahrgang lässt sich nicht anlegen
- [ ] Invariante: Zeilenzahl der Tabelle == LSG-Zahl aus P2, bei jedem Import
- [ ] Unbekannter Teilnehmer erscheint in der Tabelle, nicht nur in den Zahlen
- [ ] Unbekannter Teilnehmer hat keine Checkbox und wird auch von „Alle" nicht
      angehakt; nach dem Übernehmen steht kein neuer `lsg_athlete`-Datensatz da
- [ ] Nach dem Anlegen einer Regel und erneutem Import: die vorher offene Zeile
      ist zugeordnet, die übrigen stehen auf `gleich`
- [ ] Jeder `offen`-Grund erzeugt seinen eigenen Meldungstext (drei Fälle)
- [ ] Zeile ohne Jahrgang in der Quelle → „nennt keinen Jahrgang", nicht
      „kein Athlet gefunden"
- [ ] `skip_offen` steht mit Rohdaten im Log, auch wenn nichts geschrieben wurde
- [ ] AK-Berechnung: Jahrgang 1993 bei Lauf 2026 → `m30`; unter 30 → `hk`;
      Code nicht in `lsg_ak` → Warnung statt stillem Schreiben
- [ ] P4 mit Zeitlauf (`6h`): größere Strecke gilt als „schneller"
- [ ] `38:57` gegen `01:38:57` wird korrekt verglichen (kein String-Vergleich)
- [ ] Übernahme: `langsamer` angehakt → Bestand unverändert, Log-Eintrag da
- [ ] Konflikterkennung: Bestand zwischen Parsen und Übernehmen von außen geändert
- [ ] Log-Suche findet einen Athleten über die Rohschreibweise der Quelle

---

## 8. Entscheidungen und Ausbaustufen

### 8.1 Entschieden

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
      technischen Rücksichtsmaßnahmen (eigener User-Agent, 1 Abruf/Tag,
      Rate-Limit, Cache) bleiben verbindlich (3.5).
- [x] **Keine neuen Distanzen.** `lsg_bl_distance_map()` bleibt geschlossen;
      passt nichts, wird der Import abgelehnt statt die Karte erweitert (6.5.1).
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
- [x] **Weitere Untermenüs** unter `lsg-bestenliste`: Import-Log jetzt,
      Sportler-/Bestenlisten-/Gesamtsieger-Pflege in Phase 4 (6.2).

### 8.2 Später vorgemerkt

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
- [ ] **Phase 4 der README**: Pflege-Oberflächen für Sportler, Bestenliste und
      Gesamtsiege, mit eigener, engerer Capability (6.2).

### 8.3 Offen

- [ ] **Walking-Wettbewerbe** (Runtix `"w"`, „5 KM Interstick-Walk"): gehören
      Walking-Zeiten überhaupt in die Lauf-Bestenliste? Vorschlag im Plan
      (6.5.1): keine Distanz vorbelegen, Hinweis anzeigen, Import nur mit
      bewusster Auswahl. Bitte bestätigen oder ganz sperren.

---

## 9. Anhang: verifizierte Requests

```
# Runtix – Ergebnisliste (HTML, 200)
https://runtix.com/sts/10050/3152/21/total

# Runtix – Einzelergebnis
https://runtix.com/sts/10051/3152/21/1126

# race result – Config
https://my.raceresult.com/375768/results/config?lang=de&noVisitor=1&sanitize=true

# race result – Liste (658 Zeilen)
https://my4.raceresult.com/375768/results/list?key={KEY}
  &listname=01.1_Ergebnisse%7CZieleinlauf_Brutto
  &page=results&contest=2&r=all&l=0
```

### Testdaten

Die früher hier liegenden Referenzdateien `zieleinlauf.csv` / `zieleinlauf.json`
(658 Datensätze, Ettlingen 2026) sind aktuell nicht mehr im Projektordner.
→ Für die Contract-Tests neu erzeugen oder wieder einlegen.
