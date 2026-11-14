# IT-Verwaltung – lokale Entwicklung

Ein erster Prototyp für Geräteinventar und Ausgabe an Schülerinnen. **Nur für
lokale Entwicklung und Testdaten:** Die Anwendung ist nicht für Produktivbetrieb
oder den Einsatz mit echten personenbezogenen Daten freigegeben.

## Aktueller Umfang

- Das Dashboard ist die Startseite und bietet Einstiege in die vorhandenen
  Bereiche. Die Geräteübersicht ist eine eigene Seite. Eine spätere
  Personalisierung und Ticketnachrichten sind vorgesehen, aber noch nicht
  implementiert.
- Geräte mit Inventarnummer und den Feldern zusätzliche Bezeichnung, Produktname,
  Kategorie, Seriennummer, Modell, Hersteller, Standort, Kaufdatum, Kaufpreis,
  Grafikkarte, Prozessor, Speicher, RAM, OS und Bemerkung anlegen und bearbeiten.
  Der bisherige Zustand bleibt zusätzlich erhalten. Bei manueller Vergabe ist
  nur die Inventarnummer Pflicht; Speicher und RAM sind getrennte Freitextfelder. Der Kaufpreis wird
  inklusive Mehrwertsteuer ohne Währungsangabe mit bis zu zwei Nachkommastellen gespeichert. Der Standort
  ist der hinterlegte feste Standort und wird durch eine Ausgabe nicht automatisch
  geändert. Ein Excel-Import ist noch nicht implementiert.
- Bereich, Typ, Gruppe und Kategorie werden in den Inventareinstellungen
  verwaltet und beim Gerät ausgewählt. Jede Kategorie ist einem Typ zugeordnet;
  dessen Auswahl wird anhand der Kategorie angezeigt. Die Gruppe dient der
  Zuordnung und beeinflusst die Inventarnummer nicht. Standardtypen und
  -kategorien sind bereits angelegt, Bereiche und Gruppen werden selbst
  ergänzt. Das Löschen erfordert eine Bestätigung und ist nur möglich, wenn
  der Eintrag nicht verwendet wird.
- Die Inventarnummer entspricht der eindeutigen Bezeichnung aus der
  Inventarisierung. Beim Anlegen kann sie manuell eingegeben oder automatisch
  aus dem Kürzel des ausgewählten Bereichs bzw. Raums, optionaler Charge,
  Typkürzel und laufender Nummer erzeugt werden, zum Beispiel `LNB1PC01` oder
  `E01PC01`. Bei automatischer Vergabe sind Bereich und Kategorie erforderlich;
  bei manueller Vergabe bleibt die Inventarnummer das einzige Pflichtfeld.
  Bereichskürzel bestehen aus 2 bis 12 Buchstaben oder Ziffern, Typkürzel aus
  zwei Buchstaben und die optionale Charge aus einer positiven Zahl mit
  höchstens drei Ziffern.
  Die laufende Nummer wird je Kombination aus Bereich/Raum, Charge und Typ von
  01 bis 99 vergeben. 00 wird nicht automatisch vergeben. Für Zubehör lässt
  sich ein vorhandener PC desselben Bereichs bzw. Raums und derselben Charge
  auswählen; seine Positionsnummer wird übernommen. Ist diese Nummer für den
  Zubehörtyp schon vergeben, wird das Speichern abgewiesen. Die Inventarnummer
  bleibt beim Bearbeiten eines Standorts unverändert.
  Typkürzel: PC (Laptop, Rechner, iMac), TB (iPad/Tablet), BE (Beamer),
  DK (Dokumentenkamera), MO (Monitor), KB (Tastatur), MA (Maus), TV (Fernseher).
  iMacs verwenden wie andere PCs das Raumkürzel und werden über ihre Kategorie
  als iMac erfasst. Bestehende abweichende Nummern können weiterhin manuell
  erfasst werden. Bisherige Freitextkategorien bleiben bei vorhandenen Geräten
  erhalten, bis sie ausdrücklich geändert werden. Änderungen an Einstellungen
  oder Standorten ändern bestehende Inventarnummern nicht.
- Ausleihe mit optionaler Rückgabefrist oder dauerhafte Überlassung an eine
  bestehende Schülerinnenkennung und Klasse erfassen. Eine offene Ausgabe pro
  Gerät; Rücknahme mit Datum und Zustand, einschließlich Verlauf.
- Konten mit Inventarzugriff können Geräte anlegen und bearbeiten. Nur Konten
  mit zusätzlichem Ausgaberecht können Schülerinnenkennungen und den Verlauf
  sehen sowie Ausgaben und Rücknahmen erfassen.
- Administratorkonten können im Browser weitere Konten anlegen und dabei
  Administrator-, Ausgabe- und Inventareinstellungsrecht separat vergeben.
  Das Inventareinstellungsrecht kann auch bei bestehenden Konten angepasst
  werden; Administratorkonten haben unabhängig davon Zugang. Ein Administratorkonto hat
  nicht automatisch Ausgaberecht, kann aber Konten mit diesem Recht anlegen.
  Andere Kontoeigenschaften können noch nicht im Browser geändert und Konten
  noch nicht gelöscht werden.
- Über das Profilmenü oben rechts können angemeldete Benutzer ihr Passwort
  unter Angabe des bisherigen Passworts ändern oder sich abmelden. Nach einer
  Passwortänderung werden andere Sitzungen beim nächsten Aufruf abgemeldet.
  Nach fünf falschen Eingaben des bisherigen Passworts ist die Passwortänderung
  für 15 Minuten gesperrt; die Anmeldung bleibt davon unabhängig.
  Bereits bestehende Sitzungen müssen sich nach dieser Aktualisierung einmalig
  erneut anmelden.

Nicht enthalten sind unter anderem eine Verbindung zur Schulverwaltung,
Tickets, Netzwerkscan, Lösch- und Aufbewahrungskonzept, Protokollierung von
Änderungen, Bearbeitung bestehender Benutzerkonten, Zwei-Faktor-Anmeldung und ein
geprüftes Betriebskonzept. Vor einem produktiven Einsatz sind Sicherheit,
Datenschutz, Berechtigungen und Datensicherung gesondert zu prüfen.

## Voraussetzungen und Einrichtung

Benötigt werden PHP 8.3 mit `pdo_mysql`, ein Browser mit JavaScript und eine lokale MySQL-8.4-Datenbank.
Der Prototyp ist ausdrücklich auf `127.0.0.1:3307`, Datenbank `itdoku_dev`
und den eingeschränkten Benutzer `itdoku_app` festgelegt. Diese Festlegung
verhindert eine versehentliche Verbindung zur Schulverwaltungsdatenbank.

Die Datenbank `itdoku_dev` und der Datenbankbenutzer `itdoku_app` müssen vorher
angelegt sein. Für die Einrichtung benötigt der Benutzer die Rechte `SELECT`,
`INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `INDEX` und `REFERENCES` auf
diese Datenbank. Keine echten Zugangsdaten ins Repository schreiben.

### Neue Installation mit dem Assistenten

Der Assistent funktioniert nur für eine **leere** Datenbank und wenn noch
keine `config.php` vorhanden ist. Eine bestehende Installation einschließlich
Administratorkonto wird nicht verändert. Das Projektverzeichnis muss für den
lokalen PHP-Prozess beschreibbar sein. Nur `public` darf als Webverzeichnis
verwendet werden; Konfiguration und Token liegen außerhalb davon.

```powershell
php bin\create-install-token.php
php -S 127.0.0.1:8000 -t public
```

Das einmalig angezeigte Token geheim halten und getrennt vom Serverstart
notieren. Unter `http://127.0.0.1:8000/setup.php` das Token, das Passwort des
Datenbankbenutzers und die Daten des ersten Administratorkontos eingeben.
Nicht das MySQL-Root-Passwort verwenden. Der Assistent erstellt die Tabellen,
das Administratorkonto und `config.php`; anschließend entfernt er das Token.
Nur der Passwort-Hash wird für das Administratorkonto gespeichert.

Die Datei `install.token` und `config.php` sind durch `.gitignore` ausgeschlossen.
Die lokalen Dateizugriffsrechte müssen so gesetzt werden, dass Unbefugte diese
Dateien nicht lesen können. Schlägt die Einrichtung fehl, können wegen MySQLs
sofortiger Schemaänderungen bereits Tabellen vorhanden sein. Vor einem erneuten
Versuch den Zustand prüfen; der Assistent löscht keine Tabellen. Falls das
Token nach erfolgreicher Installation nicht entfernt wurde, muss es manuell
gelöscht werden.

Bestehende Konfigurationen können mit `php bin\check-database.php` geprüft
werden. Alternativ ist weiterhin die manuelle Einrichtung über
`config.example.php` und `php bin\initialize.php` möglich.

### Bestehende Entwicklungsdatenbank aktualisieren

Für Inventareinstellungen und das zugehörige separate Recht vorab die Daten
sichern und die folgenden additiven Migrationen ausführen:

```powershell
php bin\migrate-inventory-settings-permission.php
php bin\migrate-inventory-settings.php
```

Bestehende Konten, Rechte und Geräte bleiben erhalten. Die Migrationen sind
wiederholbar; bei einem unvollständigen Schema nach einem Fehler den Zustand
vor einem erneuten Versuch prüfen. Bei Neuinstallationen sind die Strukturen
bereits enthalten. Der Zugriff auf die Inventareinstellungen ist für
Administratoren oder Konten mit dem entsprechenden separaten Recht möglich.

Vor der Bereitstellung der erweiterten Gerätefelder bestehende Daten sichern
und die additive Migration einmalig ausführen:

```powershell
php bin\migrate-device-fields.php
```

Bestehende Geräte bleiben erhalten; neue Felder sind zunächst leer. Bei einer
neuen Installation sind sie bereits im Schema enthalten. MySQL führt die
Schemaänderung unmittelbar aus. Wenn nur einige neue Spalten gefunden werden,
bricht das Skript ab; in diesem Fall den Datenbankzustand prüfen.

Bei einer bereits initialisierten Datenbank vor der Nutzung der
Benutzerverwaltung die Daten sichern und einmalig ausführen:

```powershell
php bin\migrate-add-admin.php
```

Die Migration ergänzt das Administratorrecht ohne bestehende Rechte zu ändern.
Neue Datenbanken erhalten die Spalte direkt bei der Initialisierung. MySQL führt
die Schemaänderung unmittelbar aus; nach einem Fehler den Zustand prüfen.

## Benutzerkonten

Das Administratorkonto darf weitere Konten unter **Benutzerkonten** anlegen,
erhält aber nicht automatisch Ausgaberecht. Es kann ein weiteres Konto mit
diesem Recht anlegen. Bei bestehenden Konten kann das Recht für die
Inventareinstellungen geändert werden; weitere Kontoeigenschaften noch nicht.
Benutzernamen dürfen Buchstaben, Ziffern, Punkt, Unterstrich und
Bindestrich enthalten. Passwörter benötigen 12 bis 72 Bytes. Zugangsdaten nicht
ins Repository oder in den Chat schreiben.

PowerShell-Dateien (`*.ps1`) sind durch `.gitignore` ausgeschlossen und werden
nicht mit Git übertragen. Der Installationsassistent benötigt keine solche Datei.

## Start und Tests

Im Projektverzeichnis ausführen:

```powershell
php -S 127.0.0.1:8000 -t public
```

Anschließend `http://127.0.0.1:8000/` öffnen. Das Terminal für den Server
geöffnet lassen; `Strg+C` beendet ihn. Der Prototyp verweigert Anfragen, die
nicht über den lokalen PHP-Entwicklungsserver auf Port 8000 erfolgen. Der
Entwicklungsserver darf nicht öffentlich erreichbar gemacht werden.

Der folgende Test legt ein zufällig benanntes Testgerät und Testausgaben in
`itdoku_dev` an und entfernt diese im Anschluss:

```powershell
php tests\inventory.php
php tests\users.php
php tests\setup.php
```

Ein erfolgreicher Test ersetzt keine Prüfung für den Produktivbetrieb.
