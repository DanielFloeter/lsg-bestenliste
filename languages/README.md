# languages

Hier liegen die Übersetzungen. Heute ist der Ordner leer, und das ist kein
Versehen: die deutschen Texte stehen als Vorgabe im Code, jeder Aufruf geht
durch `__()`, und ohne `.mo`-Datei zeigt WordPress genau diese Vorgabe. Der
Ordner ist der Ort, an dem eine Übersetzung landen würde – `load_plugin_textdomain()`
in `lsg-bestenliste.php` zeigt hierher.

Eine Vorlage erzeugt man mit WP-CLI:

    wp i18n make-pot . languages/lsg-bestenliste.pot

Daraus wird eine `lsg-bestenliste-<locale>.po`, und aus der eine `.mo` – nur
die `.mo` liest WordPress.
