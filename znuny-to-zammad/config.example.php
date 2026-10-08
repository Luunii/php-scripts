<?php

// Konfiguration fuer znuny2zammad.
// Kopieren nach config.php und anpassen:  cp config.example.php config.php && chmod 600 config.php
// Nicht angegebene Werte werden mit Standardwerten belegt (siehe src/Config.php).

return [
    'znuny' => [
        // Adresse von Znuny inkl. Script-Alias:
        //   Znuny 7.x:  https://znuny.example.com/znuny
        //   Znuny 6.x:  https://znuny.example.com/otrs
        'base_url'   => 'https://znuny.example.com/znuny',

        // Name des Webservice (Admin > Webservices). Die Vorlage liegt in znuny/Znuny2Zammad.yml.
        // Ein bereits vorhandener "GenericTicketConnectorREST" funktioniert ebenfalls.
        'webservice' => 'Znuny2Zammad',

        // Nur noetig, wenn die Routen des Webservice von der Vorlage abweichen, z. B. bei der
        // Variante aus development/webservices:  'TicketSearch' => 'GET /Ticket'
        // 'routes' => [],

        // Agent fuer die Schnittstelle. Rechte auf den betroffenen Queues:
        //   "ro"  genuegt nur mit --no-source-update (Znuny-Ticket wird nicht angefasst),
        //   "rw"  fuer Notiz und Status-/Queue-Aenderung ("note" allein reicht dem Webservice nicht),
        //   zusaetzlich "move_into" auf die Ziel-Queue, wenn after_forward.queue gesetzt ist.
        'user'       => 'zammad-bridge',
        'password'   => 'GEHEIM',

        // Zeitzone der Znuny-Zeitstempel (SysConfig "OTRSTimeZone", Standard UTC).
        'timezone'   => 'UTC',

        // Was nach erfolgreicher Weiterleitung in Znuny passiert:
        // Im Batch-Betrieb muss state oder queue gesetzt sein, damit Tickets die Queue verlassen.
        'after_forward' => [
            'note'            => true,                // interne Notiz mit Zammad-Ticketnummer und Link
            'note_subject'    => 'Ticket an Zammad weitergeleitet',
            'no_agent_notify' => false,               // true = Znuny-Agenten nicht ueber die Notiz benachrichtigen
            'state'           => 'closed successful', // null = Status nicht aendern
            'queue'           => null,                // z. B. 'Weitergeleitet'; null = Queue nicht aendern
            'pending_diff_minutes' => 1440,           // Wartezeit, falls "state" ein Warte-Status ist
        ],
    ],

    'zammad' => [
        'url'   => 'https://zammad.example.com',
        // Profil > Token-Zugriff, Berechtigung "ticket.agent". Der Benutzer braucht in den
        // Zielgruppen die Rechte Lesen, Erstellen und Aendern (oder Voll).
        'token' => 'ZAMMAD-API-TOKEN',

        // Zielgruppe in Zammad, wenn keine Zuordnung passt.
        'default_group' => 'Users',

        // Znuny-Queue => Zammad-Gruppe ("*" als Platzhalter erlaubt).
        // Verschachtelte Zammad-Gruppen als "Eltern::Kind" angeben.
        'group_map' => [
            // 'Support::1st Level' => 'Support',
            // 'Vertrieb*'          => 'Sales',
        ],
        // true = gibt es in Zammad eine Gruppe mit dem Namen der Znuny-Queue, wird diese verwendet.
        'group_from_queue' => false,

        // Status des neuen Tickets in Zammad. null = aus dem Znuny-Status ableiten
        // (new -> new, open -> open, pending reminder -> pending reminder, pending auto -> pending close).
        'state' => 'open',
        // Eigene Zuordnungen Znuny-Status => Zammad-Status:
        // 'state_map' => ['in Bearbeitung' => 'open'],
        // Status, auf den ein geschlossenes Zammad-Ticket bei einem Nachtrag des Kunden gesetzt wird.
        'followup_state' => 'open',

        // Znuny-Prioritaet => Zammad-Prioritaet (Standard: 1+2 -> 1 low, 3 -> 2 normal, 4+5 -> 3 high)
        // 'priority_map' => ['3 normal' => '2 normal'],

        // Znuny-Besitzer (Login) => Zammad-Benutzer (Login oder E-Mail). Leer = Ticket ohne Besitzer.
        // Der Zammad-Benutzer braucht das Recht "Voll" in der Gruppe, sonst wird ohne Besitzer angelegt.
        'owner_map' => [
            // 'mmustermann' => 'max.mustermann@example.com',
        ],

        // Wird genutzt, wenn aus dem Znuny-Ticket keine Kunden-E-Mail ermittelt werden kann.
        'fallback_customer_email' => '',
        // Unbekannte Kunden in Zammad anlegen (sonst Abbruch).
        'create_customers' => true,

        // Tags fuer das neue Ticket; zusaetzlich "znuny-<Ticketnummer>", wenn tag_ticket_number = true.
        // Tipp: Den Tag "znuny" in Zammad-Triggern ausschliessen (siehe README).
        // tag_ticket_number an lassen: darueber findet das Skript ein Ticket nach einem Abbruch wieder.
        'tags'              => ['znuny'],
        'tag_ticket_number' => true,

        // Praefix fuer den Titel, {number} = Znuny-Ticketnummer, z. B. '[Znuny#{number}] '
        'title_prefix' => '',

        // Dynamische Felder aus Znuny => eigene Ticket-Attribute in Zammad
        'dynamic_field_map' => [
            // 'Kundennummer' => 'customer_number',
        ],
    ],

    'forward' => [
        'articles'             => 'all',   // all | first | last
        'include_internal'     => true,    // interne Znuny-Artikel als interne Notizen uebernehmen
        'include_system'       => true,    // Systemartikel (z. B. Auto-Antworten) uebernehmen
        'keep_customer_emails' => true,    // Kunden-E-Mails als E-Mail-Artikel (sonst Notiz)
        'customer_auto_reply'  => false,   // true = Zammad-Trigger duerfen den Kunden anschreiben
        'html_body'            => true,    // HTML-Text inkl. Inline-Bilder uebernehmen
        'attachments'          => true,
        'max_attachment_size'          => 20 * 1024 * 1024, // groessere Anhaenge werden ausgelassen
        'max_article_attachments_size' => 35 * 1024 * 1024, // Summe pro Artikel (Zammad/nginx: 50 MB pro Anfrage)
        'article_header'       => true,    // Originaldatum/Absender/Empfaenger oben im Artikel
        'info_note'            => true,    // interne Notiz mit den Znuny-Ticketdaten am Ende
        'suppress_notifications' => true,  // keine Agenten-Benachrichtigung pro uebernommenem Artikel (ab Zammad 7.2)
        'display_timezone'     => 'Europe/Berlin',
    ],

    // Fuer den Batch-Betrieb (--batch, z. B. per Cron): alle Tickets dieser Queues weiterleiten.
    'batch' => [
        'queues'      => ['An Zammad weiterleiten'],
        'state_types' => ['new', 'open'],
        'limit'       => 50,
    ],

    // Merkt sich bereits weitergeleitete Tickets (verhindert Duplikate). Muss fuer den Benutzer,
    // unter dem das Skript laeuft, beschreibbar sein. __DIR__ = Ordner dieser Konfigurationsdatei.
    'state_file' => __DIR__ . '/var/state.json',
    // Sperrdatei gegen parallele Laeufe; null = znuny2zammad.lock neben der Statusdatei.
    'lock_file'  => null,
    // Logdatei (zusaetzlich zur Ausgabe), z. B. fuer Cronjobs. null = keine.
    'log_file'   => null,
    // Znuny liefert alle Anhaenge eines Tickets in einer Antwort; der Wert wird bei Bedarf angehoben.
    'memory_limit' => '1024M',

    'http' => [
        'timeout'    => 120,
        'verify_ssl' => true,
        'ca_file'    => null, // eigenes CA-Zertifikat, z. B. '/etc/ssl/certs/firma-ca.pem'
    ],
];
