<?php

declare(strict_types=1);

namespace Znuny2Zammad;

/**
 * Leitet ein Znuny-Ticket an Zammad weiter:
 *  1. Ticket samt Artikeln/Anhaengen aus Znuny lesen
 *  2. Ticket mit dem ersten Artikel in Zammad anlegen, weitere Artikel anhaengen
 *  3. interne Info-Notiz mit den Znuny-Daten in Zammad anlegen
 *  4. in Znuny eine interne Notiz schreiben und ggf. Status/Queue aendern
 *
 * Jeder Schritt wird in der Statusdatei festgehalten; ein abgebrochener Lauf
 * wird beim naechsten Aufruf fortgesetzt statt ein zweites Ticket anzulegen.
 */
final class Forwarder
{
    /** @var ZnunyClient */
    private $znuny;

    /** @var ZammadClient */
    private $zammad;

    /** @var StateStore */
    private $state;

    /** @var Logger */
    private $logger;

    /** @var array<string,mixed> */
    private $config;

    /**
     * @param array<string,mixed> $config vollstaendige Konfiguration (siehe Config)
     */
    public function __construct(ZnunyClient $znuny, ZammadClient $zammad, StateStore $state, Logger $logger, array $config)
    {
        $this->znuny  = $znuny;
        $this->zammad = $zammad;
        $this->state  = $state;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * @param array<string,mixed> $options dry_run, force, group, customer, update_source
     *
     * @return array<string,mixed> Ergebnis: status (forwarded|resumed|skipped|dry-run|source-updated), zammad_ticket_id, zammad_ticket_number, url, warnings
     */
    public function forward(int $znunyTicketId, array $options = []): array
    {
        $dryRun       = !empty($options['dry_run']);
        $force        = !empty($options['force']);
        $updateSource = $options['update_source'] ?? true;
        $key          = (string) $znunyTicketId;
        $entry        = $this->state->get($key);

        if ($entry !== null && $force) {
            $this->logger->warn(sprintf(
                'Znuny-Ticket %s wurde bereits als Zammad #%s weitergeleitet - --force legt ein weiteres Ticket an.',
                $entry['znuny_ticket_number'] ?? $key,
                $entry['zammad_ticket_number'] ?? '?'
            ));
            $entry = null;
        }

        if ($entry !== null && !empty($entry['complete'])) {
            $result = $this->result('skipped', $entry);
            if ($updateSource && empty($entry['source_updated'])) {
                if ($dryRun) {
                    $this->logger->info(sprintf('Trockenlauf: Znuny-Ticket %s wuerde nachtraeglich aktualisiert.', $entry['znuny_ticket_number'] ?? $key));

                    return $this->result('dry-run', $entry);
                }
                $this->logger->info(sprintf('Znuny-Ticket %s: Weiterleitung war erfolgreich, aktualisiere Znuny-Ticket nachtraeglich.', $entry['znuny_ticket_number'] ?? $key));
                $result['warnings']             = $this->updateSource($znunyTicketId, $entry);
                $result['source_update_failed'] = $result['warnings'] !== [];
                $result['status']               = $result['warnings'] === [] ? 'source-updated' : 'skipped';

                return $result;
            }
            $this->logger->info(sprintf(
                'Znuny-Ticket %s wurde bereits weitergeleitet (Zammad #%s) - uebersprungen. Mit --force erneut weiterleiten.',
                $entry['znuny_ticket_number'] ?? $key,
                $entry['zammad_ticket_number'] ?? '?'
            ));

            return $result;
        }

        $this->checkMemory($znunyTicketId);
        $ticket   = $this->znuny->getTicket($znunyTicketId);
        $number   = (string) ($ticket['TicketNumber'] ?? $key);
        $forward  = $this->config['forward'];
        $warnings = [];

        $converter = new ArticleConverter(
            $forward,
            (string) ($this->config['znuny']['timezone'] ?? 'UTC'),
            (string) ($forward['display_timezone'] ?? 'Europe/Berlin')
        );
        $allArticles = array_values(array_filter((array) ($ticket['Article'] ?? []), 'is_array'));
        $articles    = $converter->selectArticles($ticket);
        if ($articles === []) {
            throw new \RuntimeException(sprintf('Znuny-Ticket %s: keine Artikel zum Weiterleiten gefunden.', $number));
        }

        // Zielgruppe, Status, Prioritaet, Besitzer, Kunde bestimmen.
        $group = $this->resolveGroup($ticket, isset($options['group']) ? (string) $options['group'] : null);
        $groupHasEmail = !empty($group['email_address_id']);
        [$zammadState, $pendingTime] = $this->resolveState($ticket);
        $priority = $this->resolvePriority($ticket, $warnings);
        $ownerId  = $this->resolveOwner($ticket, $warnings);
        $customer = $this->resolveCustomer($ticket, $allArticles, isset($options['customer']) ? (string) $options['customer'] : null);

        $converted = [];
        foreach ($articles as $article) {
            $item = $converter->convert($article, $groupHasEmail);
            foreach ($item['warnings'] as $warning) {
                $warnings[] = $warning;
            }
            $converted[] = ['id' => (int) ($article['ArticleID'] ?? 0), 'payload' => $item['payload']];
        }

        $ticketPayload = [
            'title'       => $this->title($ticket),
            'group_id'    => (int) $group['id'],
            'state_id'    => (int) $zammadState['id'],
            'priority_id' => (int) $priority['id'],
        ];
        if ($pendingTime !== null) {
            $ticketPayload['pending_time'] = $pendingTime;
        }
        if ($ownerId !== null) {
            $ticketPayload['owner_id'] = $ownerId;
        }
        if (isset($customer['id'])) {
            $ticketPayload['customer_id'] = (int) $customer['id'];
        } else {
            // Zammad sucht den Kunden per E-Mail und legt ihn bei Bedarf an.
            $ticketPayload['customer'] = array_filter([
                'email'     => $customer['email'],
                'firstname' => $customer['firstname'],
                'lastname'  => $customer['lastname'],
            ], 'strlen');
        }
        $tags = $this->tags($number);
        if ($tags !== '') {
            $ticketPayload['tags'] = $tags;
        }
        $ticketPayload += $this->dynamicFields($ticket, $warnings);

        foreach ($warnings as $warning) {
            $this->logger->warn(sprintf('Znuny-Ticket %s: %s', $number, $warning));
        }

        if ($dryRun) {
            $this->printPlan($ticket, $group, $zammadState, $priority, $customer, $converted, $ticketPayload);

            return ['status' => 'dry-run', 'znuny_ticket_number' => $number, 'warnings' => $warnings];
        }

        // Anlegen bzw. abgebrochenen Lauf fortsetzen.
        if ($entry !== null && empty($entry['zammad_ticket_id'])) {
            // Der letzte Versuch brach beim Anlegen ab (z. B. Zeitueberschreitung) - vielleicht
            // hat Zammad das Ticket trotzdem angelegt. Dann dieses Ticket uebernehmen.
            $entry = $this->adoptInterruptedCreate($key, $number, $entry, (string) $group['name']);
        }
        $resumed = $entry !== null;
        $expectedTags = $tags !== '' ? explode(',', $tags) : [];
        if ($resumed) {
            $zammadTicketId = (int) $entry['zammad_ticket_id'];
            $this->logger->info(sprintf('Znuny-Ticket %s: setze abgebrochene Weiterleitung fort (Zammad #%s).', $number, $entry['zammad_ticket_number'] ?? $zammadTicketId));
            $existing = $this->articlesInZammad($zammadTicketId);
            $done     = $existing['articles'];
            foreach ((array) ($entry['articles_done'] ?? []) as $id) {
                $done[(int) $id] = true;
            }
            if ($existing['info_note']) {
                $entry['info_note_done'] = true;
            }
        } else {
            $first = array_shift($converted);
            $ticketPayload['article'] = $first['payload'];

            // Vor dem Anlegen vermerken: bricht die Anfrage ab, sucht der naechste Lauf erst in Zammad.
            $this->state->set($key, [
                'znuny_ticket_number' => $number,
                'create_started_at'   => date('c'),
                'complete'            => false,
            ]);
            $created = $this->createTicket($ticketPayload, $number, $key);
            $zammadTicketId = (int) $created['id'];
            $entry = [
                'znuny_ticket_number'  => $number,
                'zammad_ticket_id'     => $zammadTicketId,
                'zammad_ticket_number' => (string) ($created['number'] ?? ''),
                'zammad_group'         => (string) $group['name'],
                'articles_done'        => [$first['id']],
                'info_note_done'       => false,
                'complete'             => false,
                'source_updated'       => false,
                'forwarded_at'         => date('c'),
            ];
            $this->state->set($key, $entry);
            $done = [$first['id'] => true];
            $this->logger->debug(sprintf('Zammad-Ticket #%s angelegt (ID %d).', $entry['zammad_ticket_number'], $zammadTicketId));
        }

        $suppress = (bool) ($forward['suppress_notifications'] ?? true);
        foreach ($converted as $item) {
            if (isset($done[$item['id']])) {
                continue;
            }
            $this->zammad->createArticle(['ticket_id' => $zammadTicketId] + $item['payload'], $suppress);
            $done[$item['id']] = true;
            $entry['articles_done'][] = $item['id'];
            $this->state->set($key, $entry);
        }

        if (!empty($forward['info_note']) && empty($entry['info_note_done'])) {
            $this->zammad->createArticle([
                'ticket_id'    => $zammadTicketId,
                'type'         => 'note',
                'sender'       => 'Agent',
                'internal'     => true,
                'subject'      => 'Aus Znuny weitergeleitet: Ticket#' . $number,
                'body'         => $this->infoNote($ticket, count($articles), count($allArticles)),
                'content_type' => 'text/html',
                'preferences'  => ['znuny_info_note' => true, 'send-auto-response' => false],
            ], $suppress);
            $entry['info_note_done'] = true;
            $this->state->set($key, $entry);
        }

        // Abschlusskontrollen: Status, Tags, von Zammad erzeugte E-Mails.
        $checks = $this->ensureState($zammadTicketId, $zammadState, $pendingTime);
        $checks = array_merge($checks, $this->checkTags($zammadTicketId, $expectedTags));
        $checks = array_merge($checks, $this->detectAutomaticMails($zammadTicketId));
        foreach ($checks as $warning) {
            $warnings[] = $warning;
            $this->logger->warn(sprintf('Znuny-Ticket %s: %s', $number, $warning));
        }

        $entry['complete']     = true;
        $entry['completed_at'] = date('c');
        $this->state->set($key, $entry);

        $result = $this->result($resumed ? 'resumed' : 'forwarded', $entry);
        $result['articles'] = count($articles);
        if ($updateSource) {
            $sourceWarnings = $this->updateSource($znunyTicketId, $entry);
            $result['source_update_failed'] = $sourceWarnings !== [];
            $result['warnings'] = $sourceWarnings;
        }
        $result['warnings'] = array_merge($warnings, $result['warnings']);

        return $result;
    }

    /**
     * Schreibt in Znuny eine interne Notiz und setzt ggf. Queue/Status.
     *
     * @param array<string,mixed> $entry
     *
     * @return string[] Warnungen
     */
    private function updateSource(int $znunyTicketId, array $entry): array
    {
        $after    = (array) ($this->config['znuny']['after_forward'] ?? []);
        $key      = (string) $znunyTicketId;
        $number   = (string) ($entry['znuny_ticket_number'] ?? $key);
        $url      = $this->zammad->ticketUrl((int) $entry['zammad_ticket_id']);
        $warnings = [];

        try {
            if (!empty($after['note']) && empty($entry['source_note_done'])) {
                $body = sprintf(
                    "Dieses Ticket wurde an Zammad weitergeleitet.\n\nZammad-Ticket: #%s\nGruppe: %s\nLink: %s\nZeitpunkt: %s\n",
                    $entry['zammad_ticket_number'] ?? '?',
                    $entry['zammad_group'] ?? '?',
                    $url,
                    $this->formatTime('now', date_default_timezone_get())
                );
                $subject = (string) ($after['note_subject'] ?? 'Ticket an Zammad weitergeleitet');
                $this->znuny->addInternalNote($znunyTicketId, $subject, $body, !empty($after['no_agent_notify']));
                $entry['source_note_done'] = true;
                $this->state->set($key, $entry);
            }

            $fields = [];
            if (!empty($after['queue'])) {
                $fields['Queue'] = (string) $after['queue'];
            }
            if (!empty($after['state'])) {
                $fields['State'] = (string) $after['state'];
                // Znuny verlangt bei Status vom Typ "pending*" eine Zeit (sonst Teil-Update:
                // Queue geaendert, Status nicht). Bei anderen Status wird sie ignoriert.
                $fields['PendingTime'] = ['Diff' => max(1, (int) ($after['pending_diff_minutes'] ?? 1440))];
            }
            if ($fields !== []) {
                $this->znuny->updateTicket($znunyTicketId, $fields);
            }

            $entry['source_updated'] = true;
            $this->state->set($key, $entry);
        } catch (ApiException $e) {
            $warning = sprintf(
                'Znuny-Ticket %s konnte nicht aktualisiert werden (%s). Beim naechsten Aufruf wird es erneut versucht.',
                $number,
                $e->getMessage()
            );
            $this->logger->warn($warning);
            $warnings[] = $warning;
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function createTicket(array $payload, string $number, string $key): array
    {
        try {
            try {
                $created = $this->zammad->createTicket($payload);
            } catch (ApiException $e) {
                // Besitzer ohne Zugriff auf die Gruppe: ohne Besitzer erneut versuchen.
                if (isset($payload['owner_id']) && $e->getCode() === 422 && stripos($e->getMessage(), 'owner') !== false) {
                    $this->logger->warn(sprintf('Znuny-Ticket %s: Besitzer in Zammad nicht zulaessig (%s), lege Ticket ohne Besitzer an.', $number, $e->getMessage()));
                    unset($payload['owner_id']);
                    $created = $this->zammad->createTicket($payload);
                } else {
                    throw $e;
                }
            }
        } catch (ApiException $e) {
            // Bei 4xx hat Zammad sicher nichts angelegt: Vermerk wieder entfernen.
            // Bei Zeitueberschreitung/5xx bleibt er stehen (siehe adoptInterruptedCreate()).
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                $this->state->remove($key);
            }
            throw $e;
        }
        if (empty($created['id'])) {
            throw new ApiException('Zammad hat beim Anlegen keine Ticket-ID geliefert.');
        }

        return $created;
    }

    /**
     * Sucht nach einem abgebrochenen Anlegen das Ticket in Zammad (ueber den Tag znuny-<Nummer>).
     *
     * @param array<string,mixed> $entry Vermerk ohne zammad_ticket_id
     *
     * @return array<string,mixed>|null Eintrag des gefundenen Tickets oder null (= neu anlegen)
     */
    private function adoptInterruptedCreate(string $key, string $number, array $entry, string $groupName): ?array
    {
        $since = (string) ($entry['create_started_at'] ?? '?');
        if (empty($this->config['zammad']['tag_ticket_number'])) {
            throw new \RuntimeException(sprintf(
                'Das Anlegen in Zammad wurde am %s unterbrochen. Bitte in Zammad pruefen, ob das Ticket existiert, '
                . 'und dann mit --force erneut weiterleiten (oder zammad.tag_ticket_number aktivieren).',
                $since
            ));
        }

        $ids = $this->zammad->findTicketIdsByTag('znuny-' . $number);
        if (count($ids) > 1) {
            throw new \RuntimeException(sprintf(
                'In Zammad gibt es mehrere Tickets mit dem Tag znuny-%s (IDs %s). Bitte pruefen und ggf. mit --force erneut weiterleiten.',
                $number,
                implode(', ', $ids)
            ));
        }
        if ($ids === []) {
            $this->logger->info(sprintf('Znuny-Ticket %s: das unterbrochene Anlegen vom %s hat kein Zammad-Ticket hinterlassen, lege neu an.', $number, $since));

            return null;
        }

        $ticket = $this->zammad->getTicket($ids[0]);
        $this->logger->info(sprintf('Znuny-Ticket %s: Zammad-Ticket #%s aus dem unterbrochenen Lauf gefunden, setze fort.', $number, $ticket['number'] ?? $ids[0]));
        $adopted = [
            'znuny_ticket_number'  => $number,
            'zammad_ticket_id'     => $ids[0],
            'zammad_ticket_number' => (string) ($ticket['number'] ?? ''),
            'zammad_group'         => $groupName,
            'articles_done'        => [],
            'info_note_done'       => false,
            'complete'             => false,
            'source_updated'       => false,
            'forwarded_at'         => $since,
        ];
        $this->state->set($key, $adopted);

        return $adopted;
    }

    /**
     * Schaetzt vorab den Speicherbedarf: Znuny liefert alle Anhaenge in einer Antwort.
     * So gibt es einen verstaendlichen Fehler fuer dieses Ticket statt eines
     * PHP-Absturzes (der im Batch-Betrieb jeden weiteren Lauf blockieren wuerde).
     */
    private function checkMemory(int $znunyTicketId): void
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return;
        }
        $meta  = $this->znuny->getTicket($znunyTicketId, false);
        $total = 0;
        foreach ((array) ($meta['Article'] ?? []) as $article) {
            foreach ((array) ($article['Attachment'] ?? []) as $attachment) {
                $total += (int) ($attachment['FilesizeRaw'] ?? 0);
            }
        }
        // Base64 in der Znuny-Antwort, dekodiert, erneut als JSON fuer Zammad: grob Faktor 3.
        $needed = memory_get_usage() + 3 * $total + 16 * 1024 * 1024;
        if ($needed > $limit) {
            throw new \RuntimeException(sprintf(
                'Ticket zu gross fuer memory_limit %s (Anhaenge %s, benoetigt ca. %s). memory_limit in config.php erhoehen.',
                ini_get('memory_limit'),
                ArticleConverter::humanSize($total),
                ArticleConverter::humanSize($needed)
            ));
        }
    }

    /**
     * @return int Bytes, 0 = unbegrenzt
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * @param array<string,mixed> $ticket
     *
     * @return array<string,mixed>
     */
    private function resolveGroup(array $ticket, ?string $override): array
    {
        $zconf = $this->config['zammad'];
        $queue = (string) ($ticket['Queue'] ?? '');

        $candidates = [];
        if ($override !== null && $override !== '') {
            $candidates[] = $override;
        } else {
            $mapped = self::mapValue((array) ($zconf['group_map'] ?? []), $queue);
            if ($mapped !== null) {
                $candidates[] = $mapped;
            }
            if (!empty($zconf['group_from_queue']) && $queue !== '') {
                $candidates[] = $queue;
            }
            $candidates[] = (string) $zconf['default_group'];
        }

        foreach ($candidates as $index => $name) {
            $group = $this->zammad->findGroup($name);
            if ($group === null) {
                // Explizit angegebene oder zugeordnete Gruppen muessen existieren.
                if ($index === 0 && ($override !== null || self::mapValue((array) ($zconf['group_map'] ?? []), $queue) !== null)) {
                    throw new \RuntimeException(sprintf('Zammad-Gruppe "%s" nicht gefunden.', $name));
                }
                continue;
            }
            if (array_key_exists('active', $group) && !$group['active']) {
                throw new \RuntimeException(sprintf('Zammad-Gruppe "%s" ist deaktiviert.', $name));
            }

            return $group;
        }

        throw new \RuntimeException(sprintf('Zammad-Gruppe "%s" (zammad.default_group) nicht gefunden.', $zconf['default_group']));
    }

    /**
     * @param array<string,mixed> $ticket
     *
     * @return array{0: array<string,mixed>, 1: string|null} Zammad-Status und ggf. pending_time
     */
    private function resolveState(array $ticket): array
    {
        $zconf     = $this->config['zammad'];
        $state     = (string) ($ticket['State'] ?? '');
        $stateType = (string) ($ticket['StateType'] ?? '');

        $name = null;
        if (!empty($zconf['state'])) {
            $name = (string) $zconf['state'];
        } else {
            $name = self::mapValue((array) ($zconf['state_map'] ?? []), $state);
            if ($name === null) {
                $byType = [
                    'new'              => 'new',
                    'open'             => 'open',
                    'pending reminder' => 'pending reminder',
                    'pending auto'     => 'pending close',
                    'closed'           => 'closed',
                ];
                $name = $byType[$stateType] ?? (string) $zconf['default_state'];
            }
        }

        $zammadState = $this->zammad->findState($name);
        if ($zammadState === null) {
            throw new \RuntimeException(sprintf('Zammad-Status "%s" nicht gefunden (Znuny-Status "%s").', $name, $state));
        }
        if (in_array($zammadState['name'] ?? '', ['merged'], true)) {
            throw new \RuntimeException('Tickets koennen in Zammad nicht im Status "merged" angelegt werden.');
        }
        if (array_key_exists('active', $zammadState) && !$zammadState['active']) {
            throw new \RuntimeException(sprintf('Zammad-Status "%s" ist deaktiviert.', $zammadState['name']));
        }

        $pendingTime = null;
        $type        = (string) ($zammadState['state_type'] ?? '');
        if (strpos($type, 'pending') === 0 || strpos((string) $zammadState['name'], 'pending') === 0) {
            $until = (int) ($ticket['RealTillTimeNotUsed'] ?? 0);
            if ($until <= time()) {
                $until = time() + 86400;
            }
            $pendingTime = gmdate('Y-m-d\TH:i:s\Z', $until);
        }

        return [$zammadState, $pendingTime];
    }

    /**
     * @param array<string,mixed> $ticket
     * @param string[]            $warnings
     *
     * @return array<string,mixed>
     */
    private function resolvePriority(array $ticket, array &$warnings): array
    {
        $zconf   = $this->config['zammad'];
        $source  = (string) ($ticket['Priority'] ?? '');
        $name    = self::mapValue((array) ($zconf['priority_map'] ?? []), $source) ?? (string) $zconf['default_priority'];
        $priority = self::activeOnly($this->zammad->findPriority($name));
        if ($priority === null && $name !== (string) $zconf['default_priority']) {
            $warnings[] = sprintf('Zammad-Prioritaet "%s" nicht gefunden oder deaktiviert, verwende "%s".', $name, $zconf['default_priority']);
            $priority = self::activeOnly($this->zammad->findPriority((string) $zconf['default_priority']));
        }
        if ($priority === null) {
            throw new \RuntimeException(sprintf('Zammad-Prioritaet "%s" nicht gefunden oder deaktiviert.', $zconf['default_priority']));
        }

        return $priority;
    }

    /**
     * @param array<string,mixed> $ticket
     * @param string[]            $warnings
     */
    private function resolveOwner(array $ticket, array &$warnings): ?int
    {
        $map   = (array) ($this->config['zammad']['owner_map'] ?? []);
        $owner = (string) ($ticket['Owner'] ?? '');
        if ($map === [] || $owner === '') {
            return null;
        }
        $target = self::mapValue($map, $owner);
        if ($target === null || $target === '') {
            return null;
        }
        $user = $this->zammad->findUserByLoginOrEmail($target);
        if ($user === null) {
            $warnings[] = sprintf('Zammad-Benutzer "%s" (Besitzer) nicht gefunden, Ticket bleibt ohne Besitzer.', $target);

            return null;
        }

        return (int) $user['id'];
    }

    /**
     * @param array<string,mixed>            $ticket
     * @param array<int,array<string,mixed>> $articles
     *
     * @return array<string,mixed> email, firstname, lastname und ggf. id (vorhandener Zammad-Benutzer)
     */
    private function resolveCustomer(array $ticket, array $articles, ?string $override): array
    {
        $zconf = $this->config['zammad'];

        if ($override !== null && $override !== '') {
            $parsed = EmailAddress::first($override);
            if ($parsed === null) {
                throw new \RuntimeException(sprintf('"%s" ist keine gueltige E-Mail-Adresse.', $override));
            }
            [$first, $last] = EmailAddress::splitName($parsed['name']);
            $customer = ['email' => $parsed['email'], 'firstname' => $first, 'lastname' => $last];
        } else {
            $customer = ArticleConverter::customerFromTicket($ticket, $articles);
        }
        if ($customer === null && !empty($zconf['fallback_customer_email'])) {
            $customer = ['email' => mb_strtolower((string) $zconf['fallback_customer_email']), 'firstname' => '', 'lastname' => ''];
        }
        if ($customer === null) {
            throw new \RuntimeException(sprintf(
                'Kunden-E-Mail fuer Znuny-Ticket %s nicht ermittelbar (CustomerUserID "%s"). Mit --customer=E-MAIL angeben oder zammad.fallback_customer_email setzen.',
                $ticket['TicketNumber'] ?? '?',
                $ticket['CustomerUserID'] ?? ''
            ));
        }

        $existing = $this->zammad->findUserByEmail($customer['email']);
        if ($existing !== null) {
            $customer['id'] = (int) $existing['id'];
        } elseif (empty($zconf['create_customers'])) {
            throw new \RuntimeException(sprintf(
                'Kunde %s existiert in Zammad nicht und zammad.create_customers ist deaktiviert.',
                $customer['email']
            ));
        }

        return $customer;
    }

    /**
     * @param array<string,mixed> $ticket
     */
    private function title(array $ticket): string
    {
        $number = (string) ($ticket['TicketNumber'] ?? '');
        $title  = trim((string) ($ticket['Title'] ?? ''));
        if ($title === '') {
            $title = 'Znuny-Ticket ' . $number;
        }
        $prefix = str_replace('{number}', $number, (string) ($this->config['zammad']['title_prefix'] ?? ''));
        $title  = (string) preg_replace('/\s+/u', ' ', $prefix . $title);

        return mb_strlen($title) > 250 ? mb_substr($title, 0, 250) : $title;
    }

    private function tags(string $number): string
    {
        $tags = [];
        foreach ((array) ($this->config['zammad']['tags'] ?? []) as $tag) {
            $tags[] = (string) $tag;
        }
        if (!empty($this->config['zammad']['tag_ticket_number'])) {
            $tags[] = 'znuny-' . $number;
        }
        // Zammad erwartet einen einzigen, kommagetrennten String (kein Array!).
        $tags = array_filter(array_map(static function (string $tag): string {
            return trim(str_replace(',', ' ', $tag));
        }, $tags), 'strlen');

        return implode(',', array_unique($tags));
    }

    /**
     * @param array<string,mixed> $ticket
     * @param string[]            $warnings
     *
     * @return array<string,mixed>
     */
    private function dynamicFields(array $ticket, array &$warnings): array
    {
        $map = (array) ($this->config['zammad']['dynamic_field_map'] ?? []);
        if ($map === []) {
            return [];
        }
        $values = [];
        foreach ((array) ($ticket['DynamicField'] ?? []) as $field) {
            if (is_array($field) && isset($field['Name'])) {
                $values[(string) $field['Name']] = $field['Value'] ?? null;
            }
        }
        $result = [];
        foreach ($map as $znunyName => $zammadAttribute) {
            if (!array_key_exists((string) $znunyName, $values) || $values[$znunyName] === null || $values[$znunyName] === '') {
                continue;
            }
            if (in_array($zammadAttribute, ['title', 'group_id', 'state_id', 'priority_id', 'customer_id', 'owner_id', 'article', 'tags'], true)) {
                $warnings[] = sprintf('Dynamisches Feld %s darf nicht auf "%s" abgebildet werden.', $znunyName, $zammadAttribute);
                continue;
            }
            $result[(string) $zammadAttribute] = $values[$znunyName];
        }

        return $result;
    }

    /**
     * Setzt den Zielstatus erneut, falls Zammad ihn geaendert hat: ein oeffentlicher
     * Agenten-Artikel (z. B. Telefon) setzt ein Ticket im Status "new" auf "open".
     *
     * @param array<string,mixed> $zammadState
     *
     * @return string[] Warnungen
     */
    private function ensureState(int $zammadTicketId, array $zammadState, ?string $pendingTime): array
    {
        try {
            $ticket = $this->zammad->getTicket($zammadTicketId);
            if ((int) ($ticket['state_id'] ?? 0) === (int) $zammadState['id']) {
                return [];
            }
            if (($zammadState['state_type'] ?? $zammadState['name']) === 'new') {
                // "new" kann Zammad bei bestehenden Tickets nicht mehr setzen.
                return [sprintf('Zammad hat den Status von "%s" auf einen anderen Status geaendert (oeffentlicher Agenten-Artikel); "new" laesst sich nicht wiederherstellen.', $zammadState['name'])];
            }
            $payload = ['state_id' => (int) $zammadState['id']];
            if ($pendingTime !== null) {
                $payload['pending_time'] = $pendingTime;
            }
            $this->zammad->updateTicket($zammadTicketId, $payload);
        } catch (ApiException $e) {
            return [sprintf('Status "%s" konnte in Zammad nicht gesetzt werden: %s', $zammadState['name'], $e->getMessage())];
        }

        return [];
    }

    /**
     * Warnt, wenn Tags fehlen (z. B. Zammad-Einstellung "Neue Tags erlauben" aus) -
     * davon haengen Trigger-Ausnahmen und das Wiederfinden nach Abbruechen ab.
     *
     * @param string[] $expected
     *
     * @return string[]
     */
    private function checkTags(int $zammadTicketId, array $expected): array
    {
        if ($expected === []) {
            return [];
        }
        try {
            $actual = array_map('mb_strtolower', $this->zammad->ticketTags($zammadTicketId));
        } catch (ApiException $e) {
            return [];
        }
        $missing = array_values(array_filter($expected, static function (string $tag) use ($actual): bool {
            return !in_array(mb_strtolower($tag), $actual, true);
        }));

        return $missing === [] ? [] : [sprintf(
            'Tags fehlen im Zammad-Ticket: %s. Tags in Zammad anlegen oder die Einstellung "Neue Tags" aktivieren (siehe README).',
            implode(', ', $missing)
        )];
    }

    /**
     * Welche Znuny-Artikel (und ob die Info-Notiz) schon im Zammad-Ticket sind - fuer das Fortsetzen.
     *
     * @return array{articles: array<int,bool>, info_note: bool}
     */
    private function articlesInZammad(int $zammadTicketId): array
    {
        $done = ['articles' => [], 'info_note' => false];
        foreach ($this->zammad->ticketArticles($zammadTicketId) as $article) {
            $id = (int) ($article['preferences']['znuny_article_id'] ?? 0);
            if ($id > 0) {
                $done['articles'][$id] = true;
            }
            if (!empty($article['preferences']['znuny_info_note'])) {
                $done['info_note'] = true;
            }
        }

        return $done;
    }

    /**
     * Warnt, wenn Zammad selbst E-Mails erzeugt hat (z. B. Trigger "auto reply").
     *
     * @return string[]
     */
    private function detectAutomaticMails(int $zammadTicketId): array
    {
        $warnings = [];
        foreach ($this->zammad->ticketArticles($zammadTicketId) as $article) {
            $isOurs = isset($article['preferences']['znuny_article_id']) || isset($article['preferences']['znuny_info_note']);
            if (!$isOurs && ($article['type'] ?? '') === 'email' && ($article['sender'] ?? '') !== 'Customer') {
                $warnings[] = sprintf(
                    'Zammad hat automatisch eine E-Mail an "%s" erzeugt (Trigger?). Bitte die Trigger pruefen, siehe README.',
                    $article['to'] ?? '?'
                );
            }
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $ticket
     */
    private function infoNote(array $ticket, int $transferred, int $total): string
    {
        $znunyUrl = ZnunyClient::agentTicketUrl($this->config['znuny'], (int) ($ticket['TicketID'] ?? 0));
        $rows     = [
            'Znuny-Ticket'      => 'Ticket#' . ($ticket['TicketNumber'] ?? ''),
            'Titel'             => $ticket['Title'] ?? '',
            'Queue'             => $ticket['Queue'] ?? '',
            'Status'            => $ticket['State'] ?? '',
            'Priorität'         => $ticket['Priority'] ?? '',
            'Typ'               => $ticket['Type'] ?? '',
            'Service'           => $ticket['Service'] ?? '',
            'SLA'               => $ticket['SLA'] ?? '',
            'Kunde'             => trim(($ticket['CustomerUserID'] ?? '') . ' / ' . ($ticket['CustomerID'] ?? ''), ' /'),
            'Besitzer'          => $ticket['Owner'] ?? '',
            'Verantwortlich'    => $ticket['Responsible'] ?? '',
            'Erstellt'          => isset($ticket['Created']) ? $this->formatTime((string) $ticket['Created'], (string) ($this->config['znuny']['timezone'] ?? 'UTC')) : '',
            'Übernommene Artikel' => $transferred . ' von ' . $total,
        ];
        foreach ((array) ($ticket['DynamicField'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['Name'])) {
                continue;
            }
            $value = $field['Value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            if ((string) $value !== '') {
                $rows['Feld ' . $field['Name']] = (string) $value;
            }
        }

        $html = '<p><strong>Dieses Ticket wurde aus Znuny weitergeleitet.</strong></p><table>';
        foreach ($rows as $label => $value) {
            if ((string) $value === '') {
                continue;
            }
            $html .= '<tr><td><strong>' . self::e((string) $label) . '</strong></td><td>' . self::e((string) $value) . '</td></tr>';
        }
        $html .= '</table>';
        if ($znunyUrl !== '') {
            $html .= '<p><a href="' . self::e($znunyUrl) . '">Ticket in Znuny öffnen</a></p>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed>            $ticket
     * @param array<string,mixed>            $group
     * @param array<string,mixed>            $state
     * @param array<string,mixed>            $priority
     * @param array<string,mixed>            $customer
     * @param array<int,array<string,mixed>> $converted
     * @param array<string,mixed>            $ticketPayload
     */
    private function printPlan(array $ticket, array $group, array $state, array $priority, array $customer, array $converted, array $ticketPayload): void
    {
        $l = $this->logger;
        $l->info(sprintf('Trockenlauf fuer Znuny-Ticket %s "%s":', $ticket['TicketNumber'] ?? '?', $ticket['Title'] ?? ''));
        $l->info(sprintf('  Zammad-Gruppe:  %s%s', $group['name'], empty($group['email_address_id']) ? ' (ohne E-Mail-Adresse)' : ''));
        $l->info(sprintf('  Status:         %s -> %s%s', $ticket['State'] ?? '?', $state['name'], isset($ticketPayload['pending_time']) ? ' bis ' . $ticketPayload['pending_time'] : ''));
        $l->info(sprintf('  Prioritaet:     %s -> %s', $ticket['Priority'] ?? '?', $priority['name']));
        $l->info(sprintf('  Kunde:          %s%s', $customer['email'], isset($customer['id']) ? ' (vorhanden)' : ' (wird angelegt)'));
        if (isset($ticketPayload['owner_id'])) {
            $l->info(sprintf('  Besitzer-ID:    %d', $ticketPayload['owner_id']));
        }
        if (isset($ticketPayload['tags'])) {
            $l->info(sprintf('  Tags:           %s', $ticketPayload['tags']));
        }
        $l->info(sprintf('  Titel:          %s', $ticketPayload['title']));
        $l->info(sprintf('  Artikel:        %d', count($converted)));
        foreach ($converted as $item) {
            $p = $item['payload'];
            $l->info(sprintf(
                '    - Znuny-Artikel %d -> %s/%s%s, %s, %d Anhang/Anhaenge: %s',
                $item['id'],
                $p['type'],
                $p['sender'],
                $p['internal'] ? ' (intern)' : '',
                $p['content_type'],
                count($p['attachments'] ?? []),
                mb_substr((string) $p['subject'], 0, 60)
            ));
        }
    }

    /**
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    private function result(string $status, array $entry): array
    {
        return [
            'status'               => $status,
            'znuny_ticket_number'  => (string) ($entry['znuny_ticket_number'] ?? ''),
            'zammad_ticket_id'     => (int) ($entry['zammad_ticket_id'] ?? 0),
            'zammad_ticket_number' => (string) ($entry['zammad_ticket_number'] ?? ''),
            'url'                  => isset($entry['zammad_ticket_id']) ? $this->zammad->ticketUrl((int) $entry['zammad_ticket_id']) : '',
            'warnings'             => [],
        ];
    }

    /**
     * Sucht einen Wert in einer Zuordnungstabelle. Schluessel duerfen "*" als
     * Platzhalter enthalten (z. B. "Support::*").
     *
     * @param array<string,mixed> $map
     */
    public static function mapValue(array $map, string $value): ?string
    {
        if (array_key_exists($value, $map)) {
            return $map[$value] === null ? null : (string) $map[$value];
        }
        foreach ($map as $pattern => $target) {
            $pattern = (string) $pattern;
            if (strpos($pattern, '*') === false || $target === null) {
                continue;
            }
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/iu';
            if (preg_match($regex, $value)) {
                return (string) $target;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $item
     *
     * @return array<string,mixed>|null
     */
    private static function activeOnly(?array $item): ?array
    {
        return $item !== null && (!array_key_exists('active', $item) || $item['active']) ? $item : null;
    }

    /**
     * Zeitangabe in der Anzeige-Zeitzone (wie im Artikelkopf).
     */
    private function formatTime(string $value, string $sourceTimezone): string
    {
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone($sourceTimezone));
            $zone = new \DateTimeZone((string) ($this->config['forward']['display_timezone'] ?? 'Europe/Berlin'));
        } catch (\Exception $e) {
            return $value;
        }

        return $date->setTimezone($zone)->format('d.m.Y H:i');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
