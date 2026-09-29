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
4. In Znuny kommt eine interne Notiz dazu („an Zammad weitergeleitet, #31001, Link“), über die Znuny die
   zuständigen Agenten wie bei jeder Notiz benachrichtigt (abschaltbar mit `no_agent_notify`).
   Optional wird der Status bzw. die Queue geändert.

> **Ohne Skript?** Für einen Einzelfall reicht in Znuny auch *Weiterleiten* an die E-Mail-Adresse
> von Zammad. Dann kommt aber nur dieser eine Artikel an, und Absender ist Znuny statt des Kunden.

## Voraussetzungen

* PHP 7.4 oder neuer (CLI) mit den Erweiterungen `curl`, `json` und `mbstring`; `intl` ist optional
  (für E-Mail-Adressen mit Umlaut-Domain).
* Znuny 6.5 oder 7.x mit aktiviertem GenericInterface.
* Zammad mit einem API-Token (Berechtigung `ticket.agent`).
* Kein Composer nötig.

Znuny liefert alle Anhänge eines Tickets in einer einzigen Antwort. Deshalb hebt das Skript
`memory_limit` bei Bedarf auf 1024 MB an (Einstellung `memory_limit`). Ein Ticket, das auch dafür zu
groß ist, wird vorab mit einer Fehlermeldung abgelehnt.

## Einrichtung

### 1. Installieren und Dateirechte setzen

Das Skript sollte immer unter **demselben Systembenutzer** laufen: für den Cronjob genauso wie für
Aufrufe von Hand. Dieser Benutzer muss `config.php` lesen und in `var/` schreiben können. In `var/`
liegen die Statusdatei (`state.json`) und die Sperrdatei.

```sh
sudo useradd -r -s /usr/sbin/nologin zammadbridge
sudo git clone https://github.com/Luunii/php-scripts.git /opt/php-scripts
cd /opt/php-scripts/znuny-to-zammad
sudo cp config.example.php config.php
sudo chown zammadbridge: config.php var/
sudo chmod 600 config.php          # enthält Passwort und Token
```

Ist die Statusdatei nicht beschreibbar, bricht das Skript ab, bevor es in Zammad etwas anlegt.
`--check` zeigt das ebenfalls an.

### 2. Znuny: Webservice anlegen

*Admin → Webservices → Webservice hinzufügen → Webservice importieren* und die Datei
[`znuny/Znuny2Zammad.yml`](znuny/Znuny2Zammad.yml) auswählen. Alternativ auf der Konsole (die
YAML-Datei muss für den Znuny-Benutzer lesbar sein):

```sh
# Znuny 7.x
cd /opt/znuny && su -c "bin/znuny.Console.pl Admin::WebService::Add --name Znuny2Zammad --source-path /tmp/Znuny2Zammad.yml" -s /bin/bash znuny
# Znuny 6.x
cd /opt/otrs && su -c "bin/otrs.Console.pl Admin::WebService::Add --name Znuny2Zammad --source-path /tmp/Znuny2Zammad.yml" -s /bin/bash otrs
```

Der Webservice stellt `SessionCreate`, `TicketGet`, `TicketSearch` und `TicketUpdate` bereit. Wenn es
schon den Beispiel-Webservice `GenericTicketConnectorREST` gibt, kann auch dieser genutzt werden
(`'webservice' => 'GenericTicketConnectorREST'`).

Außerdem wird ein Agent gebraucht, z. B. `zammad-bridge`, mit folgenden Rechten auf den betroffenen Queues:

* `ro` genügt, wenn nur gelesen wird (`--no-source-update`),
* `rw`, wenn das Skript danach eine Notiz schreibt und den Status ändert (`note` allein reicht dem Webservice nicht),
* zusätzlich `move_into` auf die Ziel-Queue, wenn es das Ticket verschieben soll.

> Den *Debug-Level* des Webservice auf `error` lassen. Bei `debug` speichert Znuny Passwörter und
> komplette Anhänge im Debug-Log in der Datenbank.

### 3. Zammad: API-Token, Gruppenrechte, Trigger

* Beim Benutzer, unter dem die Tickets angelegt werden: *Profil → Token-Zugriff → Token erstellen* mit der
  Berechtigung **`ticket.agent`**.
* In den Zielgruppen braucht dieser Benutzer die Rechte **Lesen, Erstellen und Ändern** (oder *Voll*).
  *Erstellen* allein reicht nicht, weil das Skript nach dem Anlegen weitere Artikel hinzufügt. Benutzer
  aus `owner_map` brauchen *Voll* in der Gruppe, sonst wird das Ticket ohne Besitzer angelegt.
* **Trigger:** Das Skript kennzeichnet jeden übertragenen Artikel mit `send-auto-response: false`. Damit
  überspringt Zammad alle Trigger, die an den Kunden oder den letzten Absender schreiben, darunter die
  Standard-Eingangsbestätigung *auto reply (on new tickets)*. Trigger mit anderen Empfängern (feste
  Adressen, Webhooks, SMS) laufen trotzdem. Deshalb am besten in allen Triggern, die nach außen wirken,
  die Bedingung *Ticket → Tags → enthält eins nicht → `znuny`* ergänzen.
* **Tags:** Ist in Zammad die Einstellung *Neue Tags* (*Admin → Verwalten → Tags*) ausgeschaltet, den
  Tag `znuny` dort vorher anlegen. Sonst fehlen die Tags und damit auch die Trigger-Ausnahme. Das Skript
  warnt in diesem Fall.
* Kunden-E-Mails bleiben in Zammad nur dann echte E-Mail-Artikel (mit „Antworten“-Knopf), wenn die
  Zielgruppe eine E-Mail-Adresse hat. Ohne Adresse werden sie als Notiz übernommen.

### 4. Konfiguration

In `config.php` mindestens diese Werte anpassen: `znuny.base_url`, `znuny.user`, `znuny.password`,
`zammad.url`, `zammad.token` und `zammad.default_group`. Alle Optionen sind in
[`config.example.php`](config.example.php) kommentiert.

Wichtig:

| Einstellung | Bedeutung |
|---|---|
| `znuny.base_url` | Adresse inkl. Script-Alias: Znuny 7 `https://host/znuny`, Znuny 6 `https://host/otrs` |
| `zammad.group_map` | Znuny-Queue → Zammad-Gruppe, `*` als Platzhalter (`'Support::*' => 'Support'`) |
| `zammad.state` | Status des neuen Zammad-Tickets (`'open'`), `null` = aus dem Znuny-Status ableiten |
| `znuny.after_forward` | Notiz, neuer Status (`'closed successful'`) bzw. neue Queue in Znuny |
| `forward.articles` | `all`, `first` (nur die erste Nachricht) oder `last` |

### 5. Verbindung testen

```sh
sudo -u zammadbridge php bin/znuny2zammad.php --check
```

Geprüft werden: die Anmeldung bei Znuny und Zammad, die Zielgruppen samt Rechten, die Batch-Queues und
ob die Statusdatei beschreibbar ist.

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

Exit-Codes: `0` = alles in Ordnung (oder nichts zu tun), `1` = mindestens ein Ticket oder eine
Verbindung ist fehlgeschlagen, `2` = falscher Aufruf oder fehlerhafte Konfiguration.

### Weiterleiten per Queue (Cronjob)

Einfach für Agenten: In Znuny eine Queue anlegen, z. B. *An Zammad weiterleiten*. Agenten verschieben
Tickets dorthin, ein Cronjob leitet sie weiter und schließt sie in Znuny.

```php
// config.php
'batch' => ['queues' => ['An Zammad weiterleiten'], 'state_types' => ['new', 'open'], 'limit' => 50],
'znuny' => [
    // ...
    'after_forward' => ['note' => true, 'state' => 'closed successful'],
],
'log_file' => __DIR__ . '/var/znuny2zammad.log',
```

```cron
*/5 * * * * zammadbridge php /opt/php-scripts/znuny-to-zammad/bin/znuny2zammad.php --batch --quiet
```

* Weitergeleitete Tickets müssen die Batch-Suche verlassen. Deshalb verlangt `--batch` einen Zielstatus
  oder eine Ziel-Queue in `after_forward`; `--no-source-update` ist im Batch-Betrieb nicht erlaubt.
* Jede Queue wird einzeln durchsucht. Ein falscher Queue-Name blockiert also nicht die anderen
  (`--check` zeigt leere Queues an).
* Pro Lauf werden höchstens `batch.limit` Tickets verarbeitet, die ältesten zuerst.
* Eine Sperrdatei neben der Statusdatei verhindert, dass zwei Läufe gleichzeitig arbeiten. Läuft schon
  einer, endet der nächste ohne Fehler.

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

Oben in jedem Artikel steht ein kleiner Kopf mit Originaldatum und Absender, bei Notizen auch mit
Von/An/Cc (abschaltbar mit `article_header`). Über die Zammad-API lassen sich die ursprünglichen
Zeitstempel nicht setzen.

## Sicherheit und Nebenwirkungen

* **Keine doppelten Mails an Kunden:** Zammad verschickt jeden Artikel vom Typ E-Mail, dessen Absender
  *Agent* oder *System* ist, sofort, auch wenn er intern ist. Deshalb legt das Skript alte Agenten-Mails
  immer als Notiz an.
* **Trigger:** Alle übertragenen Artikel tragen `send-auto-response: false` (siehe Einrichtung, Schritt 3).
  Das lässt sich mit `forward.customer_auto_reply` abschalten. Hat ein Trigger trotzdem eine Mail
  erzeugt, gibt das Skript eine Warnung aus.
* **Benachrichtigungen:** Für das neue Ticket bekommen die Agenten der Zielgruppe die übliche
  Benachrichtigung. Für die übrigen Artikel wird sie ab Zammad 7.2 unterdrückt (`suppress_notifications`).
* **Bcc** wird nicht übertragen.
* **Zugangsdaten** gehen als HTTP-Header und nicht in der URL an Znuny, damit sie nicht in
  Webserver-Logs landen. Pro Lauf meldet sich das Skript nur einmal an. Nach einer Passwortänderung den
  Cronjob anhalten, bis `config.php` angepasst ist, sonst sperrt Znuny den Agenten nach einigen Läufen
  (*PasswordMaxLoginFailed*).

## Doppelte Tickets und Abbrüche

In `var/state.json` hält das Skript fest, welche Tickets schon weitergeleitet wurden:

* Ein zweiter Aufruf legt kein weiteres Zammad-Ticket an. Steht nur noch die Aktualisierung in Znuny aus,
  weil sie fehlgeschlagen ist oder weil der erste Aufruf `--no-source-update` hatte, wird sie dabei
  nachgeholt. Mit `--force` wird trotzdem ein neues Zammad-Ticket angelegt.
* Bricht eine Weiterleitung ab (Netzwerk, zu großer Anhang …), setzt der nächste Aufruf dort fort.
  Welche Artikel schon in Zammad sind, erkennt das Skript an `preferences.znuny_article_id`.
* Bricht das Anlegen selbst ab (z. B. Zeitüberschreitung, obwohl Zammad das Ticket schon gespeichert
  hat), sucht der nächste Aufruf zuerst in Zammad nach dem Tag `znuny-<Nummer>` und übernimmt das
  gefundene Ticket. Ohne diesen Tag (`tag_ticket_number => false`) bittet es um eine manuelle Prüfung.

## Bekannte Einschränkungen

* Erstellzeitpunkt und Autor der Artikel sind in Zammad „jetzt“ bzw. der API-Benutzer. Das Originaldatum
  steht im Artikelkopf. Beides ließe sich nur im Zammad-Importmodus setzen, und der gilt für das ganze
  System.
* Soll das Zammad-Ticket im Status `new` bleiben, kann ein öffentlicher Telefonartikel eines Agenten es
  auf `open` setzen. `new` lässt sich danach per API nicht wiederherstellen; das Skript meldet das als
  Warnung.
* Externe Bilder (`<img src="https://...">`) entfernt Zammad aus HTML-Texten.
* Ein Anfrage-Body ist bei Zammad (nginx) meist auf 50 MB begrenzt. Deshalb gelten
  `max_attachment_size` (20 MB) und `max_article_attachments_size` (35 MB pro Artikel), auch für
  eingebettete Bilder.
* Zammad-Gruppen werden über den Namen gefunden. Verschachtelte Gruppen schreibt man `Eltern::Kind`,
  `Eltern › Kind` geht ebenfalls.

## Tests

```sh
php tests/run.php
```

Die Tests simulieren Znuny und Zammad und brauchen kein Netzwerk.
