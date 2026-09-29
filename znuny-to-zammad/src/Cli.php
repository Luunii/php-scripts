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

            $level  = !empty($options['verbose']) ? Logger::DEBUG : (!empty($options['quiet']) ? Logger::WARN : Logger::INFO);
            $logger = new Logger($level, $config['log_file'] ?? null, $this->stdout, $this->stderr);
            $http   = $this->http ?? new CurlHttpClient(
                (int) $config['http']['timeout'],
                (bool) $config['http']['verify_ssl'],
                $config['http']['ca_file'] ?? null
            );
            $znuny  = new ZnunyClient($http, $logger, $config['znuny']);
            $zammad = new ZammadClient($http, $logger, (string) $config['zammad']['url'], (string) $config['zammad']['token']);
        } catch (\RuntimeException $e) {
            fwrite($this->stderr, 'FEHLER: ' . $e->getMessage() . "\n");

            return self::EXIT_USAGE;
        }
        $dryRun = !empty($options['dry-run']);

        if (!empty($options['check'])) {
            return $this->check($znuny, $zammad, $logger, $config);
        }

        // Parallele Laeufe (z. B. ueberlappende Cronjobs) verhindern.
        $lock = null;
        if (!$dryRun) {
            $lock = $this->acquireLock($config, (string) ($options['config'] ?? $this->defaultConfigFile), $logger);
            if ($lock === false) {
                return $batch ? self::EXIT_OK : self::EXIT_ERROR;
            }
        }

        try {
            $stateFile = $config['state_file'] ?? dirname(__DIR__) . '/var/state.json';
            // Beim Trockenlauf wird die Statusdatei nur gelesen.
            $state     = new StateStore($stateFile, $dryRun);
            $forwarder = new Forwarder($znuny, $zammad, $state, $logger, $config);

            $ids = $batch
                ? $this->batchTicketIds($znuny, $config, $options, $logger)
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
                if (!ctype_digit($value)) {
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
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     *
     * @return array<string,int>
     */
    private function batchTicketIds(ZnunyClient $znuny, array $config, array $options, Logger $logger): array
    {
        $queues = !empty($options['queue']) ? (array) $options['queue'] : (array) $config['batch']['queues'];
        if ($queues === []) {
            throw new \RuntimeException('Keine Queue angegeben (--queue=NAME oder batch.queues in der Konfiguration).');
        }
        $criteria = [
            'Queues'  => array_values($queues),
            'Limit'   => max(1, (int) $config['batch']['limit']),
            'SortBy'  => 'Age',
            'OrderBy' => 'Up',
        ];
        // Hinweis: StateType "Open"/"Closed" funktioniert im GenericInterface nicht, daher echte Statustypen.
        if (!empty($config['batch']['state_types'])) {
            $criteria['StateType'] = array_values((array) $config['batch']['state_types']);
        }
        $ids = $znuny->searchTicketIds($criteria);
        $logger->info(sprintf('%d Ticket(s) in Queue(s) %s gefunden.', count($ids), implode(', ', $queues)));

        $result = [];
        foreach ($ids as $id) {
            $result['ID ' . $id] = $id;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function check(ZnunyClient $znuny, ZammadClient $zammad, Logger $logger, array $config): int
    {
        $ok = true;
        try {
            $znuny->searchTicketIds(['TicketNumber' => '0', 'Limit' => 1]);
            $logger->info(sprintf('Znuny:  OK (%s, Benutzer %s)', ZnunyClient::buildWebserviceUrl($config['znuny']), $config['znuny']['user']));
        } catch (\Throwable $e) {
            $ok = false;
            $logger->error('Znuny:  ' . $e->getMessage());
        }

        try {
            $me = $zammad->me();
            $logger->info(sprintf('Zammad: OK (%s, Benutzer %s)', $config['zammad']['url'], $me['login'] ?? $me['email'] ?? '?'));
            $group = $zammad->findGroup((string) $config['zammad']['default_group']);
            if ($group === null) {
                $ok = false;
                $logger->error(sprintf('Zammad: Standardgruppe "%s" nicht gefunden.', $config['zammad']['default_group']));
            } else {
                $logger->info(sprintf(
                    'Zammad: Standardgruppe "%s" gefunden%s.',
                    $group['name'],
                    empty($group['email_address_id']) ? ' (ohne E-Mail-Adresse: Kunden-E-Mails werden als Notizen uebernommen)' : ''
                ));
            }
            foreach ((array) $config['zammad']['group_map'] as $queue => $groupName) {
                if ($groupName !== null && $zammad->findGroup((string) $groupName) === null) {
                    $ok = false;
                    $logger->error(sprintf('Zammad: Gruppe "%s" (fuer Queue "%s") nicht gefunden.', $groupName, $queue));
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            $logger->error('Zammad: ' . $e->getMessage());
        }

        return $ok ? self::EXIT_OK : self::EXIT_ERROR;
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return resource|false|null
     */
    private function acquireLock(array $config, string $configFile, Logger $logger)
    {
        $file = $config['lock_file'] ?? sys_get_temp_dir() . '/znuny2zammad-' . md5((string) realpath($configFile)) . '.lock';
        $handle = @fopen((string) $file, 'c');
        if ($handle === false) {
            $logger->warn(sprintf('Sperrdatei "%s" kann nicht angelegt werden, fahre ohne Sperre fort.', $file));

            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $logger->warn('Es laeuft bereits eine andere Weiterleitung - Abbruch.');

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
      --no-source-update   Znuny-Ticket danach nicht aendern (keine Notiz, kein Status)
  -n, --dry-run            Nur anzeigen, was passieren wuerde
      --force              Erneut weiterleiten, auch wenn schon geschehen
  -v, --verbose            Ausfuehrliche Ausgabe
  -q, --quiet              Nur Warnungen und Fehler ausgeben
  -h, --help               Diese Hilfe

TXT;
    }
}
