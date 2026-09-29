# znuny2zammad – Tickets von Znuny an Zammad weiterleiten

Das PHP-Skript überträgt ein Ticket aus **Znuny** (6.5 LTS und 7.x) mit allen Artikeln, Anhängen und
Inline-Bildern nach **Zammad**. Danach schreibt es in Znuny eine interne Notiz mit Link auf das neue
Zammad-Ticket und kann das Znuny-Ticket schließen oder in eine andere Queue verschieben.

```
$ php bin/znuny2zammad.php 2024031210000017
Znuny-Ticket 2024031210000017 -> Zammad #31001 (https://zammad.example.com/#ticket/zoom/55)
```

So läuft eine Weiterleitung ab:

1. Das Ticket wird über den Znuny-Webservice gelesen (REST, `TicketGet`).
2. In Zammad wird das Ticket mit dem ersten Artikel angelegt. Die übrigen Artikel folgen in chronologischer Reihenfolge.
3. Am Ende steht in Zammad eine interne Notiz mit den Znuny-Daten (Ticketnummer, Queue, Kunde, dynamische Felder, Link).
4. In Znuny kommt eine interne Notiz dazu („an Zammad weitergeleitet, #31001, Link“). Optional wird der Status bzw. die Queue geändert.

> **Ohne Skript?** Für einen Einzelfall reicht in Znuny auch *Weiterleiten* an die E-Mail-Adresse
> von Zammad. Dann kommt aber nur dieser eine Artikel an, und Absender ist Znuny statt des Kunden.

## Voraussetzungen

* PHP 7.4 oder neuer (CLI) mit den Erweiterungen `curl`, `json` und `mbstring`; `intl` ist optional
  (für E-Mail-Adressen mit Umlaut-Domain).
* Znuny 6.5 oder 7.x mit aktiviertem GenericInterface.
* Zammad mit einem API-Token (Berechtigung `ticket.agent`).
* Kein Composer nötig.

## Einrichtung

### 1. Znuny: Webservice anlegen

*Admin → Webservices → Webservice hinzufügen → Webservice importieren* und die Datei
[`znuny/Znuny2Zammad.yml`](znuny/Znuny2Zammad.yml) auswählen. Alternativ auf der Konsole:

```sh
# Znuny 7.x
su -c "bin/znuny.Console.pl Admin::WebService::Add --name Znuny2Zammad --source-path /pfad/zu/Znuny2Zammad.yml" -s /bin/bash znuny
# Znuny 6.x: bin/otrs.Console.pl, Benutzer otrs
```

Der Webservice stellt `SessionCreate`, `TicketGet`, `TicketSearch` und `TicketUpdate` bereit. Wenn es
schon den Beispiel-Webservice `GenericTicketConnectorREST` gibt, kann auch dieser genutzt werden
(`'webservice' => 'GenericTicketConnectorREST'`).

Außerdem wird ein Agent gebraucht, z. B. `zammad-bridge`, mit folgenden Rechten auf den betroffenen Queues:

* `ro` genügt, wenn nur gelesen wird (`--no-source-update`),
* `rw`, wenn das Skript danach eine Notiz schreibt und den Status ändert,
* zusätzlich `move_into` auf die Ziel-Queue, wenn es das Ticket verschieben soll.

> Den *Debug-Level* des Webservice auf `error` lassen. Bei `debug` speichert Znuny Passwörter und
> komplette Anhänge im Debug-Log in der Datenbank.

### 2. Zammad: API-Token und Trigger

* Beim Benutzer, unter dem die Tickets angelegt werden: *Profil → Token-Zugang → Token erstellen* mit der
  Berechtigung **`ticket.agent`**. Der Benutzer braucht in den Zielgruppen das Recht *Erstellen*.
* **Eingangsbestätigung:** Der Standard-Trigger *auto reply (on new tickets)* schreibt Kunden bei neuen
  Tickets an. Das Skript unterdrückt das pro Artikel (`send-auto-response: false`). Eigene Trigger, die
  an andere Empfänger schreiben (z. B. Webhooks oder feste Adressen), greifen trotzdem. Sicherer ist es
  deshalb, in den Triggern die Bedingung *Ticket → Tags → enthält nicht → `znuny`* zu ergänzen.
* Kunden-E-Mails bleiben in Zammad nur dann echte E-Mail-Artikel (mit „Antworten“-Knopf), wenn die
  Zielgruppe eine E-Mail-Adresse hat. Ohne Adresse werden sie als Notiz übernommen.

### 3. Konfiguration

```sh
cp config.example.php config.php
chmod 600 config.php      # enthält Passwort und Token
```

Mindestens diese Werte anpassen: `znuny.base_url`, `znuny.user`, `znuny.password`, `zammad.url`,
`zammad.token` und `zammad.default_group`. Alle Optionen sind in
[`config.example.php`](config.example.php) kommentiert.

Wichtig:

| Einstellung | Bedeutung |
|---|---|
| `znuny.base_url` | Adresse inkl. Script-Alias: Znuny 7 `https://host/znuny`, Znuny 6 `https://host/otrs` |
| `zammad.group_map` | Znuny-Queue → Zammad-Gruppe, `*` als Platzhalter (`'Support::*' => 'Support'`) |
| `zammad.state` | Status des neuen Zammad-Tickets (`'open'`), `null` = aus dem Znuny-Status ableiten |
| `znuny.after_forward` | Notiz, neuer Status (`'closed successful'`) bzw. neue Queue in Znuny |
| `forward.articles` | `all`, `first` (nur die erste Nachricht) oder `last` |

### 4. Verbindung testen

```sh
php bin/znuny2zammad.php --check
```

## Benutzung

```sh
# Ein Ticket weiterleiten (Ticketnummer wie in Znuny angezeigt, "Ticket#" darf dabei sein)
php bin/znuny2zammad.php 2024031210000017

# Vorher ansehen, was passieren würde (ändert nichts)
php bin/znuny2zammad.php --dry-run 2024031210000017

# Zielgruppe oder Kunden abweichend festlegen
php bin/znuny2zammad.php --group="Support::2nd Level" --customer=max@example.com 2024031210000017

# Nur die erste Nachricht, ohne interne Notizen
php bin/znuny2zammad.php --articles=first --no-internal 2024031210000017

# Mit TicketID statt Ticketnummer
php bin/znuny2zammad.php --id 4711
```

Alle Optionen: `php bin/znuny2zammad.php --help`

### Weiterleiten per Queue (Cronjob)

Einfach für Agenten: In Znuny eine Queue anlegen, z. B. *An Zammad weiterleiten*. Agenten verschieben
Tickets dorthin, ein Cronjob leitet sie weiter und schließt sie in Znuny.

```php
// config.php
'batch' => ['queues' => ['An Zammad weiterleiten'], 'state_types' => ['new', 'open']],
'znuny' => [
    // ...
    'after_forward' => ['note' => true, 'state' => 'closed successful'],
],
'log_file' => __DIR__ . '/var/znuny2zammad.log',
```

```cron
*/5 * * * * www-data php /opt/znuny-to-zammad/bin/znuny2zammad.php --batch --quiet
```

Ein Sperrmechanismus verhindert, dass zwei Läufe gleichzeitig arbeiten.

## Was wird wie übertragen?

| Znuny | Zammad |
|---|---|
| Kunden-E-Mail | E-Mail-Artikel, Absender *Kunde* (Absender/Empfänger/Message-ID bleiben erhalten) |
| E-Mail eines Agenten | **Notiz**, Absender *Agent*. Von/An/Cc stehen im Text |
| Systemartikel (Auto-Antwort, Benachrichtigung) | Notiz, Absender *System* |
| Telefonartikel | Telefon-Artikel |
| Kundenportal (Web, bzw. „Internal“ in Znuny 6) | Web-Artikel |
| Interne Notiz / nicht für Kunden sichtbar | interne Notiz |
| Chat | Notiz mit dem Chatverlauf |
| HTML-Text mit Inline-Bildern | HTML-Artikel, Bilder eingebettet |
| Anhänge | Anhänge (größer als `max_attachment_size` → ausgelassen und im Text vermerkt) |
| Queue / Status / Priorität / Besitzer | Gruppe / Status / Priorität / Besitzer laut Zuordnung |
| Kunde | Zammad-Kunde mit derselben E-Mail-Adresse; wird bei Bedarf angelegt |
| Dynamische Felder | Info-Notiz; per `dynamic_field_map` auch in eigene Zammad-Attribute |
| Ticketnummer | Tag `znuny-<Nummer>` und Info-Notiz mit Link |

Oben in jedem Artikel steht ein kleiner Kopf mit Originaldatum und Absender (abschaltbar mit
`article_header`), denn über die Zammad-API lassen sich die ursprünglichen Zeitstempel nicht setzen.

## Sicherheit und Nebenwirkungen

* **Keine doppelten Mails an Kunden:** Zammad verschickt jeden Artikel vom Typ E-Mail, dessen Absender
  *Agent* oder *System* ist, sofort, auch wenn er intern ist. Deshalb legt das Skript alte Agenten-Mails
  immer als Notiz an.
* **Eingangsbestätigung:** Kunden-Artikel tragen `send-auto-response: false`, damit Zammad den Kunden
  nicht anschreibt. Das lässt sich mit `forward.customer_auto_reply` abschalten. Hat ein Trigger trotzdem
  eine Mail erzeugt, gibt das Skript eine Warnung aus.
* **Benachrichtigungen:** Für das neue Ticket bekommen die Agenten der Zielgruppe die übliche
  Benachrichtigung. Für die übrigen Artikel wird sie ab Zammad 7.2 unterdrückt (`suppress_notifications`).
* **Bcc** wird nicht übertragen.
* **Zugangsdaten** gehen als HTTP-Header und nicht in der URL an Znuny, damit sie nicht in
  Webserver-Logs landen. Nach einer fehlgeschlagenen Anmeldung versucht das Skript es kein zweites Mal
  (sonst droht die Kontosperre durch *PasswordMaxLoginFailed*).

## Doppelte Tickets und Abbrüche

In `var/state.json` hält das Skript fest, welche Tickets schon weitergeleitet wurden:

* Ein zweiter Aufruf für dasselbe Ticket tut nichts. Mit `--force` wird trotzdem ein neues Zammad-Ticket angelegt.
* Bricht eine Weiterleitung ab (Netzwerk, zu großer Anhang …), setzt der nächste Aufruf dort fort, statt
  ein zweites Ticket anzulegen. Welche Artikel schon in Zammad sind, erkennt das Skript an
  `preferences.znuny_article_id`.
* Schlägt nur die Aktualisierung in Znuny fehl (z. B. fehlende Rechte), wird sie beim nächsten Aufruf
  nachgeholt, ohne dass etwas erneut an Zammad geht.

## Bekannte Einschränkungen

* Erstellzeitpunkt und Autor der Artikel sind in Zammad „jetzt“ bzw. der API-Benutzer. Das Originaldatum
  steht im Artikelkopf. Beides ließe sich nur im Zammad-Importmodus setzen, und der gilt für das ganze
  System.
* Externe Bilder (`<img src="https://...">`) entfernt Zammad aus HTML-Texten.
* Ein Anfrage-Body ist bei Zammad (nginx) meist auf 50 MB begrenzt. Deshalb gelten
  `max_attachment_size` (20 MB) und `max_article_attachments_size` (35 MB pro Artikel).
* Zammad-Gruppen werden über den Namen gefunden. Verschachtelte Gruppen schreibt man `Eltern::Kind`,
  `Eltern › Kind` geht ebenfalls.

## Tests

```sh
php tests/run.php
```

Die Tests simulieren Znuny und Zammad und brauchen kein Netzwerk.
