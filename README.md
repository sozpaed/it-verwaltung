# IT-Verwaltung – lokale Entwicklung

Ein erster Prototyp für Geräteinventar und Ausgabe an Schülerinnen. **Nur für
lokale Entwicklung und Testdaten:** Die Anwendung ist nicht für Produktivbetrieb
oder den Einsatz mit echten personenbezogenen Daten freigegeben.

## Aktueller Umfang

- Das Dashboard ist die Startseite und bietet Einstiege in die vorhandenen
  Bereiche. Die Geräteübersicht ist eine eigene Seite. Eine spätere
  Personalisierung und Ticketnachrichten sind vorgesehen, aber noch nicht
  implementiert.
- Geräte mit Inventarnummer, Art, Modell, Seriennummer, Standort und Zustand
  anlegen und bearbeiten. Der Standort ist der hinterlegte feste Standort und
  wird durch eine Ausgabe nicht automatisch geändert.
- Ausleihe mit optionaler Rückgabefrist oder dauerhafte Überlassung an eine
  bestehende Schülerinnenkennung und Klasse erfassen. Eine offene Ausgabe pro
  Gerät; Rücknahme mit Datum und Zustand, einschließlich Verlauf.
- Konten mit Inventarzugriff können Geräte anlegen und bearbeiten. Nur Konten
  mit zusätzlichem Ausgaberecht können Schülerinnenkennungen und den Verlauf
  sehen sowie Ausgaben und Rücknahmen erfassen.
- Administratorkonten können im Browser weitere Konten anlegen und dabei
  Administrator- und Ausgaberecht separat vergeben. Ein Administratorkonto hat
  nicht automatisch Ausgaberecht, kann aber Konten mit diesem Recht anlegen.
  Bestehende Konten können noch nicht im Browser geändert oder gelöscht werden.
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

Benötigt werden PHP 8.3 mit `pdo_mysql` und eine lokale MySQL-8.4-Datenbank.
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
diesem Recht anlegen. Bestehende Konten können noch nicht im Browser bearbeitet
werden. Benutzernamen dürfen Buchstaben, Ziffern, Punkt, Unterstrich und
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
