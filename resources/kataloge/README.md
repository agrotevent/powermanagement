# Mitgelieferte Kataloge

Katalog-Pakete (`*.json`, Format `verteiler-typen`) in diesem Ordner stehen
unter *Standard-Verteiler → Importieren …* zur Auswahl und lassen sich mit
`php bin/power katalog:import resources/kataloge/<datei>.json` einspielen.
Format und Ablauf: [docs/bedienung.md](../../docs/bedienung.md#katalog-importieren).

Jede Datei hier prüft `tests/katalog-import.php`: gültiges Paket mit Quelle,
eindeutige Schlüssel, jeder Stecker auflösbar, der Import in eine leere
Installation legt alle Typen an, und danach steht jeder Typ „schon da“.
