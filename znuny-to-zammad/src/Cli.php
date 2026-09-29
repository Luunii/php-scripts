<?php

declare(strict_types=1);

namespace Znuny2Zammad;

use Znuny2Zammad\Http\CurlHttpClient;
use Znuny2Zammad\Http\HttpClient;

/**
 * Kommandozeilen-Oberflaeche von znuny2zammad.
 */
final class Cli
{
    public const EXIT_OK     = 0;
    public const EXIT_ERROR  = 1;
    public const EXIT_USAGE  = 2;

    private const VALUE_OPTIONS = ['config', 'queue', 'group', 'customer', 'articles'];
    private const FLAG_OPTIONS  = ['id', 'batch', 'no-attachments', 'no-internal', 'no-source-update', 'dry-run', 'force', 'check', 'verbose', 'quiet', 'help'];
    private const SHORT_OPTIONS = ['c' => 'config', 'n' => 'dry-run', 'v' => 'verbose', 'q' => 'quiet', 'h' => 'help'];

    /** @var HttpClient|null */
    private $http;

    /** @var string */
    private $defaultConfigFile;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(?HttpClient $http = null, ?string $defaultConfigFile = null, $stdout = null, $stderr = null)
    {
        $this->http              = $http;
        $this->defaultConfigFile = $defaultConfigFile ?? dirname(__DIR__) . '/config.php';
        $this->stdout            = $stdout ?? STDOUT;
        $this->stderr            = $stderr ?? STDERR;
    }

    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        try {
            [$options, $arguments] = self::parseArguments(array_slice($argv, 1));
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'FEHLER: ' . $e->getMessage() . "\n\n" . self::usage());

            return self::EXIT_USAGE;
        }
        if (!empty($options['help'])) {
            fwrite($this->stdout, self::usage());

            return self::EXIT_OK;
        }

        $batch = !empty($options['batch']) || !empty($options['queue']);
        if (empty($options['check']) && !$batch && $arguments === []) {
            fwrite($this->stderr, "FEHLER: Bitte eine Ticketnummer angeben (oder --batch / --queue / --check).\n\n" . self::usage());

            return self::EXIT_USAGE;
        }
        if ($batch && $arguments !== []) {
            fwrite($this->stderr, "FEHLER: Ticketnummern und --batch/--queue koennen nicht kombiniert werden.\n");

            return self::EXIT_USAGE;
        }

        try {
            $config = Config::load((string) ($options['config'] ?? $this->defaultConfigFile));
            if (isset($options['articles'])) {
                if (!in_array($options['articles'], ['all', 'first', 'last'], true)) {
                    throw new \RuntimeException('--articles muss all, first oder last sein.');
                }
                $config['forward']['articles'] = $options['articles'];
            }
            if (!empty($options['no-attachments'])) {
                $config['forward']['attachments'] = false;
            }
            if (!empty($options['no-internal'])) {
                $config['forward']['include_internal'] = false;
            }
            $dryRun = !empty($options['dry-run']);
            if ($batch && !$dryRun) {
                self::assertBatchLeavesQueue($config, !empty($options['no-source-update']));
            }
            if ($config['memory_limit'] !== null) {
                self::raiseMemoryLimit($config['memory_limit']);
            }

            $level  = !empty($options['verbose']) ? Logger::DEBUG : (!empty($options['quiet']) ? Logger::WARN : Logger::INFO);
            $logger = new Logger($level, $config['log_file'], $this->stdout, $this->stderr);
            $http   = $this->http ?? new CurlHttpClient(
                (int) $config['http']['timeout'],
                (bool) $config['http']['verify_ssl'],
                $config['http']['ca_file']
            );
            $znuny  = new ZnunyClient($http, $logger, $config['znuny']);
            $zammad = new ZammadClient($http, $logger, (string) $config['zammad']['url'], (string) $config['zammad']['token']);
        } catch (\RuntimeException | \TypeError $e) {
            fwrite($this->stderr, 'FEHLER: ' . $e->getMessage() . "\n");

            return self::EXIT_USAGE;
        }
        $stateFile = $config['state_file'] ?? dirname(__DIR__) . '/var/state.json';

        if (!empty($options['check'])) {
            return $this->check($znuny, $zammad, $logger, $config, $stateFile);
        }

        $lock = null;
        try {
            // Beim Trockenlauf wird die Statusdatei nur gelesen.
            $state = new StateStore($stateFile, $dryRun);
            if (!$dryRun) {
                // Vor der ersten Aenderung in Zammad: ohne speicherbaren Status drohen Duplikate.
                $state->assertWritable();
                // Parallele Laeufe (z. B. ueberlappende Cronjobs) verhindern.
                $lock = $this->acquireLock($config['lock_file'] ?? dirname($stateFile) . '/znuny2zammad.lock');
                if ($lock === false) {
                    $logger->warn('Es laeuft bereits eine andere Weiterleitung - Abbruch.');

                    return $batch ? self::EXIT_OK : self::EXIT_ERROR;
                }
            }
            $forwarder = new Forwarder($znuny, $zammad, $state, $logger, $config);

            $ids = $batch
                ? $this->batchTicketIds($znuny, $state, $config, $options, $logger)
                : $this->resolveTicketIds($znuny, $arguments, !empty($options['id']));

            $errors = 0;
            foreach ($ids as $label => $ticketId) {
                try {
                    $result = $forwarder->forward($ticketId, [
                        'dry_run'       => $dryRun,
                        'force'         => !empty($options['force']),
                        'group'         => $options['group'] ?? null,
                        'customer'      => $options['customer'] ?? null,
                        'update_source' => empty($options['no-source-update']),
                    ]);
                    $this->report($logger, $result);
                    if (!empty($result['source_update_failed'])) {
                        $errors++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $logger->error(sprintf('Znuny-Ticket %s: %s', $label, $e->getMessage()));
                }
            }

            if (count($ids) > 1) {
                $logger->info(sprintf('%d Ticket(s) verarbeitet, %d Fehler.', count($ids), $errors));
            }

            return $errors > 0 ? self::EXIT_ERROR : self::EXIT_OK;
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());

            return self::EXIT_ERROR;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Im Batch-Betrieb muss ein weitergeleitetes Ticket die Suche verlassen (Status/Queue),
     * sonst fuellen erledigte Tickets irgendwann jeden Lauf und neue kommen nie dran.
     *
     * @param array<string,mixed> $config
     */
    private static function assertBatchLeavesQueue(array $config, bool $noSourceUpdate): void
    {
        $after = (array) $config['znuny']['after_forward'];
        if ($noSourceUpdate) {
            throw new \RuntimeException('--no-source-update ist im Batch-Betrieb nicht moeglich: weitergeleitete Tickets wuerden in der Queue bleiben.');
        }
        if (empty($after['state']) && empty($after['queue'])) {
            throw new \RuntimeException('Fuer den Batch-Betrieb znuny.after_forward.state (z. B. "closed successful") oder znuny.after_forward.queue setzen, damit weitergeleitete Tickets die Queue verlassen.');
        }
    }

    private static function raiseMemoryLimit(string $wanted): void
    {
        $current = Forwarder::bytes((string) ini_get('memory_limit'));
        $target  = Forwarder::bytes($wanted);
        if ($current !== 0 && ($target === 0 || $target > $current)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    /**
     * @param string[] $arguments
     *
     * @return array<string,int> Anzeige-Name => TicketID
     */
    private function resolveTicketIds(ZnunyClient $znuny, array $arguments, bool $areIds): array
    {
        $ids = [];
        foreach ($arguments as $argument) {
            $value = self::normalizeTicketNumber($argument);
            if ($value === '') {
                throw new \RuntimeException(sprintf('Ungueltige Ticketnummer "%s".', $argument));
            }
            if ($areIds) {
                if (!preg_match('/^\d+$/', $value)) {
                    throw new \RuntimeException(sprintf('Ungueltige TicketID "%s".', $argument));
                }
                $ids['ID ' . $value] = (int) $value;
                continue;
            }
            $id = $znuny->findTicketIdByNumber($value);
            if ($id === null) {
                throw new \RuntimeException(sprintf('Znuny-Ticket mit Nummer %s nicht gefunden (oder keine Leserechte). Fuer TicketIDs --id verwenden.', $value));
            }
            $ids[$value] = $id;
        }

        return $ids;
    }

    /**
     * Sucht die naechsten Tickets der Batch-Queues. Jede Queue wird einzeln gesucht
     * (ein falscher Queue-Name laesst in Znuny sonst die ganze Suche leer ausgehen),
     * bereits vollstaendig erledigte Tickets werden uebersprungen.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     *
     * @return array<string,int>
     */
    private function batchTicketIds(ZnunyClient $znuny, StateStore $state, array $config, array $options, Logger $logger): array
    {
        $queues = !empty($options['queue']) ? (array) $options['queue'] : (array) $config['batch']['queues'];
        if ($queues === []) {
            throw new \RuntimeException('Keine Queue angegeben (--queue=NAME oder batch.queues in der Konfiguration).');
        }
        $limit  = max(1, (int) $config['batch']['limit']);
        // Groesseres Suchfenster, damit erledigte Tickets (die noch in der Queue liegen) nicht alles belegen.
        $window = min(500, $limit + $state->count());

        $found = [];
        foreach ($queues as $queue) {
            $criteria = [
                'Queues'  => [(string) $queue],
                'Limit'   => $window,
                'SortBy'  => 'Age',
                'OrderBy' => 'Up',
            ];
            // Hinweis: StateType "Open"/"Closed" funktioniert im GenericInterface nicht, daher echte Statustypen.
            if (!empty($config['batch']['state_types'])) {
                $criteria['StateType'] = array_values((array) $config['batch']['state_types']);
            }
            $ids = $znuny->searchTicketIds($criteria);
            if ($ids === []) {
                $logger->debug(sprintf('Queue "%s": keine Tickets (oder Queue-Name falsch).', $queue));
            }
            foreach ($ids as $id) {
                $found[$id] = true;
            }
        }
        $ids = array_keys($found);
        sort($ids);

        $done = array_values(array_filter($ids, static function (int $id) use ($state): bool {
            $entry = $state->get((string) $id);

            return $entry !== null && !empty($entry['complete']) && !empty($entry['source_updated']);
        }));
        if ($done !== []) {
            $logger->warn(sprintf(
                '%d bereits weitergeleitete(s) Ticket(s) liegen noch in der Batch-Queue (IDs %s) - znuny.after_forward pruefen.',
                count($done),
                implode(', ', array_slice($done, 0, 10))
            ));
        }
        $ids = array_slice(array_values(array_diff($ids, $done)), 0, $limit);
        $logger->info(sprintf('%d Ticket(s) in Queue(s) %s zu verarbeiten.', count($ids), implode(', ', $queues)));

        $result = [];
        foreach ($ids as $id) {
            $result['ID ' . $id] = $id;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function check(ZnunyClient $znuny, ZammadClient $zammad, Logger $logger, array $config, string $stateFile): int
    {
        $ok = true;
        try {
            $znuny->searchTicketIds(['TicketNumber' => '0', 'Limit' => 1]);
            $logger->info(sprintf('Znuny:  OK (%s, Benutzer %s)', ZnunyClient::buildWebserviceUrl($config['znuny']), $config['znuny']['user']));
            foreach ((array) $config['batch']['queues'] as $queue) {
                $count = count($znuny->searchTicketIds(['Queues' => [(string) $queue], 'Limit' => 500]));
                $logger->info(sprintf('Znuny:  Batch-Queue "%s": %d Ticket(s)%s', $queue, $count, $count === 0 ? ' (leer oder Name falsch - exakt inkl. "Eltern::Kind")' : ''));
            }
        } catch (\Throwable $e) {
            $ok = false;
            $logger->error('Znuny:  ' . $e->getMessage());
        }

        try {
            $me = $zammad->me();
            $logger->info(sprintf('Zammad: OK (%s, Benutzer %s)', $config['zammad']['url'], $me['login'] ?? $me['email'] ?? '?'));
            $groups = ['Standardgruppe' => (string) $config['zammad']['default_group']];
            foreach ((array) $config['zammad']['group_map'] as $queue => $groupName) {
                if ($groupName !== null) {
                    $groups['Gruppe fuer Queue "' . $queue . '"'] = (string) $groupName;
                }
            }
            foreach ($groups as $label => $groupName) {
                $group = $zammad->findGroup($groupName);
                if ($group === null) {
                    $ok = false;
                    $logger->error(sprintf('Zammad: %s "%s" nicht gefunden.', $label, $groupName));
                    continue;
                }
                $logger->info(sprintf(
                    'Zammad: %s "%s" gefunden%s.',
                    $label,
                    $group['name'],
                    empty($group['email_address_id']) ? ' (ohne E-Mail-Adresse: Kunden-E-Mails werden als Notizen uebernommen)' : ''
                ));
                $access = array_map('strval', (array) ($me['group_ids'][(string) $group['id']] ?? []));
                if (!in_array('full', $access, true) && array_diff(['read', 'create', 'change'], $access) !== []) {
                    // Rechte ueber Rollen sind hier nicht enthalten - daher nur Warnung.
                    $logger->warn(sprintf(
                        'Zammad: Benutzer hat in "%s" direkt nur die Rechte [%s]; benoetigt werden Lesen, Erstellen und Aendern (oder Voll).',
                        $group['name'],
                        implode(', ', $access)
                    ));
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            $logger->error('Zammad: ' . $e->getMessage());
        }

        try {
            (new StateStore($stateFile))->assertWritable();
            $logger->info(sprintf('Status: %s ist beschreibbar.', $stateFile));
        } catch (\RuntimeException $e) {
            $ok = false;
            $logger->error('Status: ' . $e->getMessage());
        }

        return $ok ? self::EXIT_OK : self::EXIT_ERROR;
    }

    /**
     * @return resource|false false = eine andere Weiterleitung laeuft bereits
     */
    private function acquireLock(string $file)
    {
        $handle = @fopen($file, 'c');
        if ($handle === false && is_file($file)) {
            // Datei eines anderen Benutzers: flock() geht auch lesend.
            $handle = @fopen($file, 'r');
        }
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Sperrdatei "%s" kann nicht angelegt werden (Rechte?). Ohne Sperre drohen doppelte Tickets.', $file));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function report(Logger $logger, array $result): void
    {
        switch ($result['status']) {
            case 'forwarded':
            case 'resumed':
                $logger->info(sprintf(
                    'Znuny-Ticket %s -> Zammad #%s (%s)%s',
                    $result['znuny_ticket_number'],
                    $result['zammad_ticket_number'],
                    $result['url'],
                    $result['status'] === 'resumed' ? ' [fortgesetzt]' : ''
                ));
                break;
            case 'source-updated':
                $logger->info(sprintf('Znuny-Ticket %s: Znuny aktualisiert (Zammad #%s).', $result['znuny_ticket_number'], $result['zammad_ticket_number']));
                break;
            default:
                break;
        }
    }

    /**
     * Akzeptiert auch "Ticket#2024..." oder "#2024..." (so wie Znuny die Nummer anzeigt).
     */
    public static function normalizeTicketNumber(string $value): string
    {
        $value = trim($value);
        $hash  = strrpos($value, '#');
        if ($hash !== false) {
            $value = substr($value, $hash + 1);
        }
        $value = trim($value);

        // Keine Platzhalter/Operatoren an die Znuny-Suche weitergeben.
        return preg_match('/^[0-9A-Za-z_-]+$/', $value) ? $value : '';
    }

    /**
     * @param string[] $args
     *
     * @return array{0: array<string,mixed>, 1: string[]}
     */
    public static function parseArguments(array $args): array
    {
        $options   = [];
        $arguments = [];
        $count     = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                $arguments = array_merge($arguments, array_slice($args, $i + 1));
                break;
            }
            if (strncmp($arg, '--', 2) === 0) {
                $name  = substr($arg, 2);
                $value = null;
                if (strpos($name, '=') !== false) {
                    [$name, $value] = explode('=', $name, 2);
                }
            } elseif (strlen($arg) === 2 && $arg[0] === '-' && isset(self::SHORT_OPTIONS[$arg[1]])) {
                $name  = self::SHORT_OPTIONS[$arg[1]];
                $value = null;
            } elseif ($arg !== '' && $arg[0] === '-' && $arg !== '-') {
                throw new \InvalidArgumentException(sprintf('Unbekannte Option "%s".', $arg));
            } else {
                $arguments[] = $arg;
                continue;
            }

            if (in_array($name, self::VALUE_OPTIONS, true)) {
                if ($value === null) {
                    if ($i + 1 >= $count) {
                        throw new \InvalidArgumentException(sprintf('Option --%s braucht einen Wert.', $name));
                    }
                    $value = $args[++$i];
                }
                if ($name === 'queue') {
                    $options['queue'][] = $value;
                } else {
                    $options[$name] = $value;
                }
            } elseif (in_array($name, self::FLAG_OPTIONS, true)) {
                if ($value !== null) {
                    throw new \InvalidArgumentException(sprintf('Option --%s erwartet keinen Wert.', $name));
                }
                $options[$name] = true;
            } else {
                throw new \InvalidArgumentException(sprintf('Unbekannte Option "--%s".', $name));
            }
        }

        return [$options, $arguments];
    }

    public static function usage(): string
    {
        return <<<'TXT'
znuny2zammad - Tickets von Znuny an Zammad weiterleiten

Aufruf:
  php bin/znuny2zammad.php [Optionen] <Ticketnummer> [<Ticketnummer> ...]
  php bin/znuny2zammad.php [Optionen] --queue="An Zammad"   (alle Tickets einer Queue, z. B. per Cron)
  php bin/znuny2zammad.php --check                          (Verbindungen testen)

Optionen:
  -c, --config=DATEI       Konfigurationsdatei (Standard: config.php im Projektordner)
      --id                 Die Argumente sind Znuny-TicketIDs statt Ticketnummern
      --queue=NAME         Tickets dieser Znuny-Queue weiterleiten (mehrfach moeglich)
      --batch              Tickets gemaess Abschnitt "batch" der Konfiguration weiterleiten
      --group=NAME         Zielgruppe in Zammad (statt Zuordnung aus der Konfiguration)
      --customer=E-MAIL    Kunde in Zammad (statt Ermittlung aus dem Znuny-Ticket)
      --articles=MODUS     all (Standard), first oder last
      --no-internal        Interne Artikel nicht uebernehmen
      --no-attachments     Keine Anhaenge uebernehmen
      --no-source-update   Znuny-Ticket diesmal nicht aendern (ein spaeterer Aufruf holt es nach)
  -n, --dry-run            Nur anzeigen, was passieren wuerde
      --force              Erneut weiterleiten, auch wenn schon geschehen
  -v, --verbose            Ausfuehrliche Ausgabe
  -q, --quiet              Nur Warnungen und Fehler ausgeben
  -h, --help               Diese Hilfe

TXT;
    }
}
