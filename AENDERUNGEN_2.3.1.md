# Änderungen 2.3.1 (05.08.2026)

## Fehlerbehebung

- **Weboberfläche zeigte ein Verzeichnislisting** („Index of
  /admin/plugins/smartmeter-classic") statt der Oberfläche, wenn das Plugin
  ohne Dateinamen aufgerufen wurde — also auch beim Klick im LoxBerry-Menü.
  Ursache: Die aus dem Original übernommene `htaccess` setzte
  `DirectoryIndex index_legacy.cgi`; diese Datei gibt es seit dem PHP-Umbau
  in 2.3.0 nicht mehr, Apache fand daher keine Startdatei. Die `htaccess`
  zeigt jetzt auf `index.php`.
- Aufräumen: verwaistes `show.cgi` entfernt (seit dem PHP-Umbau ohne Aufrufer).
