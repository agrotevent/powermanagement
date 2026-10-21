# Mitgelieferte Kataloge

Katalog-Pakete (`*.json`, Format `verteiler-typen`) in diesem Ordner stehen
unter *Standard-Verteiler → Importieren …* zur Auswahl und lassen sich mit
`php bin/power katalog:import resources/kataloge/<datei>.json` einspielen.
Format und Ablauf: [docs/bedienung.md](../../docs/bedienung.md#katalog-importieren).

Jede Datei hier prüft `tests/katalog-import.php`, jede für sich in einer
eigenen, leeren Installation (nur der Grundbestand an Steckern): gültiges
Paket mit Quelle, eindeutige Schlüssel, jeder Stecker auflösbar, der Import
legt alle Typen an, und danach steht jeder Typ „schon da“.

| Datei | Inhalt |
|-------|--------|
| `bss-strombibel.json` | BSS Strombibel (Stand 18.03.2025): 36 Stromverteiler, 9 Generatoren (mit Verbrauch je Last, wo die Strombibel ihn nennt) und die Kraftstofftanks QUADRO-AG 1000/2000/3000 (Seite 20, nur Eigenschaften). Was beim Einlesen gedeutet wurde, steht in den Notizen des Typs („bitte prüfen“). |
