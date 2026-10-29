# Protokolldatenbank für die Fachschaft Medizin

Webseite, auf der Studierende **Gedächtnisprotokolle** zu ihren Prüfungen (Physikum/M1, M2, M3 …) einsehen und herunterladen können – mit Bezahlung über **Stripe**, automatischer **Rückerstattung bei eigenem Protokoll** und einem umfangreichen **Adminbereich**.

Läuft auf **normalem Webhosting mit PHP und MySQL** – ohne Composer, Node.js, Kommandozeile oder Cronjobs.

---

## Inhalt

1. [Funktionen im Überblick](#funktionen-im-überblick)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation (Schritt für Schritt)](#installation-schritt-für-schritt)
4. [Erste Schritte nach der Installation](#erste-schritte-nach-der-installation)
5. [Stripe einrichten](#stripe-einrichten)
6. [E-Mail-Versand](#e-mail-versand)
7. [Bestehende Protokolle migrieren](#bestehende-protokolle-migrieren)
8. [So funktionieren Kauf und Rückerstattung](#so-funktionieren-kauf-und-rückerstattung)
9. [Rollen im Team](#rollen-im-team)
10. [Sicherheit & Datenschutz](#sicherheit--datenschutz)
11. [Wartung, Backup, Updates](#wartung-backup-updates)
12. [Aufbau des Codes](#aufbau-des-codes)
13. [Häufige Probleme](#häufige-probleme)

---

## Funktionen im Überblick

**Für Studierende**
- Registrierung nur mit **studentischer E-Mail-Adresse** (erlaubte Domains einstellbar) und Bestätigung per Link
- Prüfungen (M1, M2, M3 – beliebig erweiterbar) mit ihren Prüferinnen und Prüfern, gruppiert nach Fach, mit Suche
- **Freischaltung**: bis zu 4 Prüfer (pro Prüfung einstellbar) für 30 € (einstellbar) – auch alle künftigen Protokolle dieser Prüfer sind inklusive
- Freie Plätze können später **kostenlos nachgetragen** werden
- Protokolle online lesen (mit dezentem Wasserzeichen), PDFs/Bilder direkt im Browser ansehen, Dateien einzeln oder **alle als ZIP** herunterladen
- **Protokoll hochladen** als Text und/oder Dateien (PDF, Word, Bilder …); mehrere Prüfer einer Prüfungskommission in einem Durchgang
- Ist der Prüfer noch nicht vorhanden, wird er **automatisch angelegt** (mit Erkennung ähnlicher Schreibweisen)
- Nach dem Upload **automatische Rückerstattung von 29 €** (einstellbar) über Stripe
- „Mein Bereich“: Freischaltungen, eigene Protokolle mit Status, Erstattungen, Passwort ändern, Konto löschen

**Für die Fachschaft (Adminbereich)**
- Dashboard mit Kennzahlen, offenen Aufgaben und Warnhinweisen
- Prüfungen anlegen/bearbeiten (Name, Kürzel, Farbe, Preis, Erstattung, max. Prüferzahl, Reihenfolge, aktiv/inaktiv)
- Prüfer verwalten, **doppelte Prüfer zusammenführen**, neu angelegte Prüfer prüfen
- Protokolle anlegen, bearbeiten, Dateien austauschen, **moderieren** (freigeben/ablehnen mit Begründung per E-Mail)
- **Import/Migration** bestehender Sammlungen: ZIP-Upload, FTP-Ordner (auch tausende Dateien) oder CSV
- Nutzer verwalten (Rollen, sperren, bestätigen, manuell anlegen, **Zugang manuell gewähren** z. B. bei Barzahlung)
- Käufe & Erstattungen: Filter, Summen, **CSV-Export für die Kassenprüfung**, manuelle Erstattung, Zugang entziehen, Prüfer ändern
- Zahlungen: **Simulationsmodus** ↔ **Stripe** per Klick, Webhook wird automatisch eingerichtet
- E-Mail: PHP mail(), SMTP oder „nur protokollieren“ (Testbetrieb), Test-E-Mail, Protokoll aller E-Mails
- Texte: Startseite, FAQ, Impressum, Datenschutz, Nutzungsbedingungen (mit Platzhaltern wie `{preis}`)
- Aktivitätslog und Abruf-/Download-Protokoll (z. B. zum Erkennen von Massen-Downloads)
- System & Wartung: Systemprüfung, Speicherschutz-Test, Aufräumen, Demodaten anlegen/entfernen

---

## Voraussetzungen

- Webhosting mit **PHP 8.0 oder neuer** (empfohlen 8.2+) und einer **MySQL- (ab 5.7) oder MariaDB-Datenbank (ab 10.3)**
- PHP-Erweiterungen: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json` (bei fast allen Hostern Standard); optional `zip` (ZIP-Import/-Download) und `fileinfo`
- **SSL/HTTPS** für die Domain (bei Hostern meist kostenlos per Let’s Encrypt) – Pflicht für Stripe
- FTP-Zugang zum Hochladen der Dateien

---

## Installation (Schritt für Schritt)

1. **Datenbank anlegen:** Im Kundenmenü des Hosters eine neue MySQL-Datenbank erstellen und Name, Benutzer, Passwort und Server notieren.
2. **Dateien hochladen:** Den gesamten Inhalt dieses Ordners per FTP in das Webverzeichnis der (Sub-)Domain hochladen, z. B. nach `protokolle.eure-fachschaft.de/`. Versteckte Dateien (`.htaccess`, `.user.ini`) mit hochladen!
3. **Schreibrechte:** Die Ordner `storage/` (mit allen Unterordnern) und `config/` müssen für PHP beschreibbar sein (bei den meisten Hostern automatisch, sonst per FTP auf `755` bzw. `775` setzen).
4. **Installer aufrufen:** `https://eure-domain.de/install/` im Browser öffnen.
   - Voraussetzungen werden geprüft
   - Datenbank-Zugangsdaten eintragen
   - Name der Webseite, Fachschaft und die **erlaubten studentischen E-Mail-Domains** (z. B. `stud.uni-beispiel.de`) angeben
   - Admin-Konto anlegen
   - Optional **Demodaten**: je Prüfung 3–4 Testprüfer mit je 2 Protokollen (teils mit PDF) sowie ein Demo-Studierendenkonto mit simuliertem Kauf
5. **Ordner `install/` löschen** (per FTP). Der Installer sperrt sich zwar selbst, Löschen ist aber sicherer.

Danach unter `https://eure-domain.de/index.php?p=login` mit dem Admin-Konto anmelden. Über „Admin“ in der Navigation gelangt ihr in den Adminbereich.

> Kann der Installer die Datei `config/config.php` nicht schreiben, zeigt er ihren Inhalt an. Dann die Datei per FTP anlegen und die Seite neu laden. Eine Vorlage liegt in `config/config.sample.php`.

---

## Erste Schritte nach der Installation

1. **Ausprobieren im Simulationsmodus:** Mit dem Demo-Studierendenkonto (Zugangsdaten unter *System & Wartung*) einloggen, Prüfer freischalten (Testzahlung), Protokolle lesen und ein eigenes Protokoll hochladen – die simulierte Erstattung erscheint sofort.
2. **Einstellungen → Registrierung:** erlaubte Uni-Domains prüfen.
3. **Einstellungen → Preise & Erstattung:** Preis, Erstattungsbetrag, Prüferanzahl, Erstattungsregeln.
4. **Einstellungen → Uploads & Moderation:** sofort veröffentlichen (vollautomatisch) oder vorher freigeben.
5. **Texte & Rechtliches:** Impressum, Datenschutzerklärung und Nutzungsbedingungen anpassen (Platzhalter in eckigen Klammern ersetzen – am besten rechtlich prüfen lassen).
6. **E-Mail** einrichten und eine Test-E-Mail an eine Uni-Adresse schicken.
7. **Bestehende Protokolle importieren** (siehe unten).
8. **Stripe einrichten** und vom Simulationsmodus auf Stripe umschalten.
9. **System & Wartung → Demodaten entfernen** und unter *Zahlungen / Stripe* die simulierten Käufe löschen.

---

## Stripe einrichten

Ein reiner Link zum Stripe-Konto genügt technisch nicht: Die Webseite muss Zahlungen automatisch bestätigen und Erstattungen auslösen und braucht dafür den **Geheimschlüssel (Secret Key)**. Die Einrichtung dauert trotzdem nur wenige Minuten:

1. Stripe-Konto unter <https://dashboard.stripe.com/register> anlegen und die Angaben zur Fachschaft vervollständigen (Bankverbindung, vertretungsberechtigte Person).
2. Im Stripe-Dashboard unter **Entwickler → API-Schlüssel** den **Geheimschlüssel** kopieren (`sk_live_…`; zum Testen zuerst den Testmodus-Schlüssel `sk_test_…`).
3. Im Adminbereich **Zahlungen / Stripe → Schritt 2** einfügen. Die Verbindung wird sofort geprüft.
4. **„Webhook automatisch einrichten“** klicken – der Webhook (`https://eure-domain.de/stripe-webhook.php`) wird mit allen nötigen Ereignissen im Stripe-Konto angelegt.
5. **Zahlungsmodus auf „Stripe“** stellen. Fertig.

Im Stripe-Testmodus kann mit der Testkarte `4242 4242 4242 4242` (beliebiges zukünftiges Datum, beliebige Prüfziffer) bezahlt werden. Danach den Live-Schlüssel eintragen und den Webhook erneut automatisch einrichten.

Hinweise:
- Bezahlt wird über die von Stripe gehostete Zahlungsseite (Stripe Checkout). Karten, Apple Pay, Google Pay, SEPA usw. lassen sich im Stripe-Dashboard aktivieren.
- Die Freischaltung funktioniert auch ohne Webhook (die Zahlung wird bei der Rückkehr geprüft) – für verzögerte Zahlungsarten wie SEPA wird der Webhook aber benötigt.
- Erstattungen im Stripe-Dashboard werden über den Webhook automatisch übernommen.
- **Gebühren:** Stripe behält die Transaktionsgebühr auch bei Erstattungen in der Regel ein. Bei 30 € Preis und 29 € Erstattung bleibt der Fachschaft nach Gebühren nur ein kleiner Betrag – ggf. den Erstattungsbetrag anpassen.
- Der Geheimschlüssel wird verschlüsselt in der Datenbank gespeichert (Schlüssel `app_key` in `config/config.php`).

---

## E-Mail-Versand

E-Mails werden für Bestätigungslinks, Passwort-Reset, Kaufbestätigungen, Erstattungen und Moderationsentscheidungen verschickt. Unter **E-Mail** im Adminbereich:

- **PHP mail()** – funktioniert bei den meisten Hostern sofort.
- **SMTP** – zuverlässiger (weniger Spam): z. B. ein Postfach `noreply@eure-domain.de` beim Hoster anlegen und dessen Zugangsdaten eintragen (STARTTLS Port 587 oder SSL Port 465).
- **Nur protokollieren** – Testbetrieb: nichts wird verschickt, die E-Mails stehen im Protokoll; nach der Registrierung wird der Bestätigungslink direkt angezeigt.

Tipp: Uni-Mailserver filtern streng. Absenderadresse der eigenen Domain verwenden und SMTP nutzen.

---

## Bestehende Protokolle migrieren

Unter **Import / Migration** gibt es drei Wege:

### 1. ZIP-Archiv (für kleinere Sammlungen)
Ordnerstruktur `Prüfung/Prüfer (Fach)/Datei`:

```
M1/
  Prof. Dr. Anna Beispiel (Anatomie)/
    Protokoll 2023-03-14.pdf
    Herbst 2022.docx
  PD Dr. Clara Probe (Physiologie)/
    14.09.2024 Gedächtnisprotokoll.pdf
    notizen.txt
M3/
  Prof. Dr. Ingo Schablone (Chirurgie)/
    ...
```

- Oberste Ebene = Prüfung (Kurzname wie `M1`, `M2`, `M3` oder der Name).
- Zweite Ebene = Prüfer, das Fach optional in Klammern.
- Jede Datei wird ein Protokoll; der Dateiname wird zum Titel, ein Datum im Namen (`2023-03-14` oder `14.03.2023`) zum Prüfungsdatum. `.txt` wird als lesbarer Text übernommen.
- Wird beim Import eine feste Prüfung gewählt, entfällt die oberste Ebene.
- Vor dem Import zeigt eine **Vorschau**, was angelegt wird und welche Dateien übersprungen werden.
- Umlaute in ZIPs aus Windows werden korrekt erkannt.

### 2. FTP-Ordner (für große Sammlungen)
Dieselbe Struktur per FTP nach `storage/import/` hochladen und im Adminbereich „Ordner analysieren“ klicken. Der Import läuft in Etappen (kein Timeout-Problem) und verschiebt importierte Dateien nach `storage/import/_importiert/`.

### 3. CSV (Text-Protokolle aus Tabellen)
Kopfzeile mit `Prüfung; Prüfer; Fach; Datum; Titel; Text; Note` (nur *Prüfer* und *Text* sind Pflicht). Trennzeichen `;` oder `,`, Excel-Exporte (Windows-1252) werden erkannt.

Bei allen Wegen gilt: Bestehende Prüfer werden auch bei abweichender Schreibweise erkannt („Prof. Dr. Müller“ = „Müller“), unbekannte automatisch angelegt, **Duplikate übersprungen** – ein Import kann gefahrlos wiederholt werden. Doppelte Prüfer lassen sich danach unter *Prüfer → Zusammenführen* bereinigen.

---

## So funktionieren Kauf und Rückerstattung

1. Studierende wählen eine Prüfung und bis zu 4 Prüfer und bezahlen 30 €.
2. Nach erfolgreicher Zahlung sind alle Protokolle dieser Prüfer freigeschaltet (optional zeitlich begrenzt, Einstellung „Zugang gültig (Tage)“).
3. Nach der Prüfung laden sie ihr eigenes Protokoll hoch.
4. Sobald es veröffentlicht ist (sofort oder nach Freigabe durch das Team), werden automatisch **29 €** auf das ursprüngliche Zahlungsmittel erstattet.

Regeln (einstellbar):
- Pro Kauf höchstens **eine** Erstattung; ein Upload mit mehreren Prüfern zählt als ein Protokoll.
- Standardmäßig nur für Protokolle **derselben Prüfung** wie der Kauf.
- Optional eine Frist (Tage nach Kauf).
- Modus „Nach manueller Prüfung“: Das Team löst die Erstattung per Klick aus (das Dashboard zeigt offene Fälle).
- Fehlgeschlagene Erstattungen werden im Dashboard angezeigt, per E-Mail gemeldet und können erneut ausgelöst oder als manuell erledigt markiert werden.
- Bei manuell gewährten Zugängen mit Barzahlung wird die Erstattung als „manuell auszuzahlen“ markiert.

---

## Rollen im Team

| Rolle | Darf |
|---|---|
| **Studierende** | Protokolle kaufen, lesen, hochladen |
| **Moderation** | zusätzlich: Dashboard, Prüfer, Protokolle (freigeben/ablehnen/bearbeiten), Import |
| **Admin** | alles, inkl. Prüfungen, Nutzer, Käufe/Erstattungen, Einstellungen, Stripe, E-Mail, Texte, System |

Rollen werden unter **Nutzer → Konto** vergeben. Konten für Teammitglieder ohne Uni-Adresse können dort manuell angelegt werden.

---

## Sicherheit & Datenschutz

- Passwörter mit `password_hash()`, Schutz gegen Brute-Force bei Login, Registrierung, Passwort-Reset und Uploads
- CSRF-Schutz für alle Formulare, sichere Sitzungs-Cookies, Content-Security-Policy, Prepared Statements gegen SQL-Injection
- Hochgeladene Dateien liegen unter zufälligen Namen in `storage/` und werden nur nach Rechteprüfung über PHP ausgeliefert. Dateityp-Whitelist und Prüfung der Dateisignatur.
- Stripe-Webhook mit Signaturprüfung; bei der Rückkehr von der Zahlungsseite wird die Zahlung zusätzlich direkt bei Stripe abgefragt
- Wasserzeichen mit E-Mail-Adresse beim Lesen, Protokollierung aller Abrufe, ZIP-Downloads mit Hinweis auf die Person
- Keine externen Schriftarten, Skripte oder Tracker (DSGVO-freundlich); nur ein technisch notwendiges Sitzungs-Cookie
- Konto löschen anonymisiert die Daten; Buchungsdaten bleiben für die Kassenprüfung erhalten

**Wichtig bei nginx-Servern:** Die `.htaccess`-Dateien schützen `app/`, `config/` und `storage/` nur unter Apache (Standard bei fast allen deutschen Hostern). Unter *System & Wartung → Schutz des Speicherordners testen* lässt sich das prüfen. Falls der Test fehlschlägt, den Ordner `storage/` außerhalb des Webverzeichnisses ablegen und in `config/config.php` den Pfad `storage_path` anpassen.

---

## Wartung, Backup, Updates

- **Backup:** regelmäßig die Datenbank (z. B. Export per phpMyAdmin im Hoster-Menü) und den Ordner `storage/uploads/` sichern. `config/config.php` ebenfalls sicher aufbewahren (enthält den Schlüssel für gespeicherte Geheimnisse).
- **Aufräumen:** läuft teils automatisch; zusätzlich unter *System & Wartung* (offene Käufe, temporäre Dateien, alte Logs). Cronjobs sind nicht nötig.
- **Updates:** neue Dateien per FTP hochladen (ohne `config/` und `storage/` zu überschreiben), danach *System & Wartung → Datenbankstruktur prüfen* klicken.
- **Größere Uploads:** Die mitgelieferte `.user.ini` erhöht das Upload-Limit auf 64 MB (wirkt bei PHP-FPM/CGI). Das tatsächliche Limit steht unter *System & Wartung*.
- **Fehlersuche:** In `config/config.php` `'debug' => true` setzen (danach wieder auf `false`). Fehler werden in `storage/logs/` protokolliert.

---

## Aufbau des Codes

```
index.php              Front-Controller (alle Seiten über index.php?p=…)
stripe-webhook.php     Stripe-Webhook
install/               Web-Installer (nach der Installation löschen)
app/
  bootstrap.php        Initialisierung
  schema.sql           Datenbankschema
  lib/                 Logik: DB, Settings, Auth, Mailer, Stripe, Payments, Protocols, Importer, Demo
  pages/               Seiten (öffentlich) und pages/admin/ (Adminbereich)
  views/               Layouts
assets/                CSS, JavaScript, Icon (keine externen Abhängigkeiten)
config/                config.php (wird vom Installer erstellt)
storage/               Uploads, Import-Ordner, temporäre Dateien, Logs, Sitzungen
```

Reines PHP 8 ohne Framework und ohne Abhängigkeiten – bewusst einfach gehalten, damit es auf jedem Webhosting läuft und auch spätere Fachschaftsgenerationen den Code verstehen.

---

## Häufige Probleme

| Problem | Lösung |
|---|---|
| Bestätigungs-E-Mails kommen nicht an | Spam-Ordner prüfen, unter *E-Mail* SMTP einrichten und Test-E-Mail senden. Das E-Mail-Protokoll zeigt Fehler. |
| „Die gesendeten Daten sind zu groß“ | Upload-Limit des Servers erhöhen (`.user.ini` bzw. Hoster-Menü) oder FTP-Import nutzen |
| Stripe: „Webhook konnte nicht angelegt werden“ | Die Seite muss öffentlich per HTTPS erreichbar sein; ggf. Webhook manuell im Stripe-Dashboard anlegen |
| Zahlung erfolgreich, aber nicht freigeschaltet | Unter *Käufe* den Kauf öffnen; im Notfall „Als bezahlt markieren“. Webhook-Status unter *Zahlungen / Stripe* prüfen. |
| Weiße Seite / Fehler 500 | `debug` in `config/config.php` kurz aktivieren, PHP-Version (≥ 8.0) prüfen, Log in `storage/logs/` ansehen |
| Doppelte Prüfer nach Uploads | *Prüfer → Prüfer öffnen → Zusammenführen* |
