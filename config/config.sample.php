<?php
// Beispiel-Konfiguration. Wird normalerweise automatisch vom Installer (install/) erzeugt.
// Bei manueller Einrichtung: als config.php speichern und Werte anpassen.
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'datenbankname',
        'user' => 'benutzer',
        'pass' => 'passwort',
    ],
    // Öffentliche Adresse der Webseite (mit / am Ende)
    'base_url' => 'https://protokolle.eure-fachschaft.de/',
    // Zufälliger Schlüssel (64 Hex-Zeichen), z. B. erzeugt mit: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => 'HIER_ZUFAELLIGEN_SCHLUESSEL_EINTRAGEN',
    // Speicherort für Uploads (kann außerhalb des Webverzeichnisses liegen, z. B. '/home/kunde/protokolle-storage')
    'storage_path' => dirname(__DIR__) . '/storage',
    'debug' => false,
];
