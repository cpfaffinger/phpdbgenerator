# Standalone PHP DB Generator

Ein schlankes Tool zur automatischen Generierung von PHP-Datenbankklassen (Models, Controller und App-Klassen) direkt aus einer MySQL-Datenbankstruktur.

### Features

- **Standalone**: Alles was benötigt wird, ist die `generate.php`. Keine externen Abhängigkeiten oder `lib`-Ordner.
- **Drei-Schichten-Modell**: Pro Tabelle werden drei spezialisierte Dateien generiert:
    - `schema\<Name>Model.class.php`: Das Datenmodell mit expliziten Feldern, Gettern und Settern sowie CRUD-Logik (`insert`, `update`, `save`, `spawn`).
    - `app\<Name>.class.php`: Eine initial leere Klasse für individuelle Geschäftslogik, die vom Model erbt. Inklusive einer `delete()` Methode für das Objekt selbst.
    - `controller\<Name>Controller.class.php`: Statische Methoden für Massenoperationen (`getAll`, `getByField`, `create`) und eine statische `delete($instance)` Methode.
- **Keine Namespaces**: Einfache Integration durch klare Namenskonventionen.
- **Typisierung**: Automatische Erkennung von SQL-Typen und entsprechende Typ-Annotationen in den Gettern.
- **Flexible Konfiguration**: Verbindung über Umgebungsvariablen oder Kommandozeilenargumente.

### Ordnerstruktur

Nach der Generierung sieht die Struktur wie folgt aus:
- `controller/`: Enthält die Controller-Klassen.
- `schema/`: Enthält die Model-Klassen (Datenbank-Schema).
- `app/`: Enthält die App-Klassen für Ihre Logik.

### Installation & Verwendung

1. Kopieren Sie die `generate.php` in Ihr Projektverzeichnis.
2. Führen Sie das Skript über die Kommandozeile aus:

```bash
# Verwendung mit Argumenten
php generate.php [host] [user] [pass] [dbname]

# Beispiel
php generate.php localhost root password my_database
```

Alternativ können Umgebungsvariablen verwendet werden:
```bash
export DB_HOST=localhost
export DB_USER=root
export DB_PASS=password
export DB_NAME=my_database
php generate.php
```

### Voraussetzungen

- PHP 7.4 oder höher
- MySQL/MariaDB Datenbank
- `mysqli` Erweiterung aktiviert
