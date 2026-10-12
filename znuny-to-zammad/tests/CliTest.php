<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\Cli;

final class CliTest extends TestCase
{
    /** @var string */
    private $configFile;

    /** @var string */
    private $stateFile;

    /** @var FakeServers */
    private $servers;

    public function setUp(): void
    {
        $this->servers    = new FakeServers($this->fixture('ticket-get.json')['Ticket'][0]);
        $this->stateFile  = $this->tempFile('.json');
        unlink($this->stateFile);
        $this->configFile = $this->tempFile('.php');
        $this->writeConfig([]);
    }

    public function tearDown(): void
    {
        @unlink($this->stateFile . '.lock');
        @unlink($this->stateFile . '.writelock');
        parent::tearDown();
    }

    /**
     * @param string[] $args
     */
    /** @var string */
    private $output = '';

    private function run(array $args): int
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $cli = new Cli($this->servers->http, $this->configFile, $out, $err);
        try {
            return $cli->run(array_merge(['znuny2zammad.php'], $args));
        } finally {
            rewind($out);
            rewind($err);
            $this->output = stream_get_contents($out) . stream_get_contents($err);
            fclose($out);
            fclose($err);
        }
    }

    public function testParseArguments(): void
    {
        [$options, $args] = Cli::parseArguments(['-n', '--group=Support', '2024031210000017', '--queue', 'A', '--queue=B', '-v', '--', '--kein-option']);
        $this->assertSame(['dry-run' => true, 'group' => 'Support', 'queue' => ['A', 'B'], 'verbose' => true], $options);
        $this->assertSame(['2024031210000017', '--kein-option'], $args);

        $this->assertThrows(\InvalidArgumentException::class, static function (): void {
            Cli::parseArguments(['--gibtsnicht']);
        }, 'Unbekannte Option');
        $this->assertThrows(\InvalidArgumentException::class, static function (): void {
            Cli::parseArguments(['--group']);
        }, 'braucht einen Wert');
        $this->assertThrows(\InvalidArgumentException::class, static function (): void {
            Cli::parseArguments(['--force=ja']);
        }, 'erwartet keinen Wert');
    }

    public function testNormalizeTicketNumber(): void
    {
        $this->assertSame('2024031210000017', Cli::normalizeTicketNumber(' Ticket#2024031210000017 '));
        $this->assertSame('2024031210000017', Cli::normalizeTicketNumber('#2024031210000017'));
        $this->assertSame('', Cli::normalizeTicketNumber('2024*'), 'keine Platzhalter an die Znuny-Suche');
        $this->assertSame('', Cli::normalizeTicketNumber('1 || 2'));
    }

    public function testForwardByTicketNumber(): void
    {
        $this->assertSame(Cli::EXIT_OK, $this->run(['Ticket#2024031210000017']));
        $this->assertStringContains('Znuny-Ticket 2024031210000017 -> Zammad #31001 (https://zammad.test/#ticket/zoom/55)', $this->output);
        $this->assertCount(1, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'));
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertTrue($state['4711']['complete']);

        // Zweiter Aufruf: nichts zu tun, trotzdem Erfolg.
        $this->assertSame(Cli::EXIT_OK, $this->run(['2024031210000017']));
        $this->assertCount(1, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'));
    }

    public function testUnknownTicketNumberFails(): void
    {
        $this->assertSame(Cli::EXIT_ERROR, $this->run(['1111']));
        $this->assertStringContains('FEHLER: Znuny-Ticket mit Nummer 1111 nicht gefunden', $this->output);
        $this->assertCount(0, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'));
    }

    public function testDryRunAndBatch(): void
    {
        $this->assertSame(Cli::EXIT_OK, $this->run(['--dry-run', '--batch']));
        $this->assertStringContains('Trockenlauf fuer Znuny-Ticket 2024031210000017', $this->output);
        $this->assertStringContains('Znuny-Artikel 103 -> note/Agent', $this->output);
        $this->assertCount(0, $this->servers->http->requestsMatching('POST', '~zammad~'));
        $this->assertFalse(is_file($this->stateFile));

        $search = $this->servers->http->requestsMatching('POST', '~/Ticket/Search$~');
        $this->assertSame(['An Zammad'], FakeHttpClient::body($search[0])['Queues']);
        $this->assertSame(['new', 'open'], FakeHttpClient::body($search[0])['StateType']);

        // Im Batch-Betrieb muessen Tickets die Queue verlassen.
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--queue=Andere Queue', '--no-source-update']));
        $this->assertStringContains('--no-source-update ist im Batch-Betrieb nicht moeglich', $this->output);

        $this->assertSame(Cli::EXIT_OK, $this->run(['--queue=Andere Queue', '--queue=Zweite']));
        $searches = $this->servers->http->requestsMatching('POST', '~/Ticket/Search$~');
        $this->assertSame(['Andere Queue'], FakeHttpClient::body($searches[1])['Queues'], 'jede Queue einzeln');
        $this->assertSame(['Zweite'], FakeHttpClient::body($searches[2])['Queues']);
        $this->assertCount(1, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'), 'Ticket nur einmal, obwohl in beiden Suchen');
        $this->assertCount(2, $this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'));
    }

    public function testBatchRequiresAfterForwardStateOrQueue(): void
    {
        $this->writeConfig(['znuny' => ['after_forward' => ['state' => null, 'queue' => null]]]);
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--batch']));
        $this->assertStringContains('znuny.after_forward.state', $this->output);
        $this->assertCount(0, $this->servers->http->requests);
        // Trockenlauf ist trotzdem erlaubt.
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch', '--dry-run']));
    }

    public function testBatchDoesNotStarveOnForwardedTicketsStillInQueue(): void
    {
        // Eigener Status, der in Wahrheit vom Typ "offen" ist (vorab nicht pruefbar):
        // Tickets bleiben nach der Weiterleitung in der Queue.
        $this->writeConfig(['batch' => ['queues' => ['An Zammad'], 'limit' => 1], 'znuny' => ['after_forward' => ['state' => 'bei Zammad']]]);
        $this->servers->addZnunyTicket(4712);
        $this->servers->addZnunyTicket(4713);

        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
        $this->assertStringContains('2 bereits weitergeleitete(s) Ticket(s) wieder in der Batch-Queue', $this->output);

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        unset($state['_meta']);
        $this->assertSame(['4711', '4712', '4713'], array_map('strval', array_keys($state)));
        $this->assertCount(3, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'));
    }

    public function testBatchProcessesOldestFirstByCreationTime(): void
    {
        $this->writeConfig(['batch' => ['queues' => ['An Zammad'], 'limit' => 1]]);
        $this->servers->addZnunyTicket(4712);
        $this->servers->addZnunyTicket(4713);
        // Erstellzeit folgt nicht der ID-Reihenfolge (z. B. importierte Tickets).
        $this->servers->znunyTicket['Created']            = '2024-03-12 09:15:00';
        $this->servers->moreZnunyTickets[4712]['Created'] = '2024-03-15 08:00:00';
        $this->servers->moreZnunyTickets[4713]['Created'] = '2024-03-01 08:00:00';
        // Fruehere Weiterleitungen vergroessern das Suchfenster ueber batch.limit hinaus.
        $done = ['complete' => true, 'source_updated' => true, 'zammad_ticket_id' => 1];
        file_put_contents($this->stateFile, json_encode(['9001' => $done, '9002' => $done]));

        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        unset($state['_meta'], $state['9001'], $state['9002']);
        $this->assertSame(['4713'], array_map('strval', array_keys($state)), 'aeltestes Ticket zuerst');
    }

    public function testBatchRejectsAfterForwardThatKeepsTicketsInSearch(): void
    {
        // Ziel-Queue ist selbst eine Batch-Queue.
        $this->writeConfig(['znuny' => ['after_forward' => ['state' => null, 'queue' => 'An Zammad']]]);
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--batch']));
        $this->assertStringContains('nimmt weitergeleitete Tickets nicht aus der Batch-Suche', $this->output);

        // Ziel-Status gehoert zu den gesuchten Statustypen (new, open).
        $this->writeConfig(['znuny' => ['after_forward' => ['state' => 'open', 'queue' => null]]]);
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--batch']));

        // --queue ersetzt batch.queues - auch dagegen pruefen.
        $this->writeConfig(['znuny' => ['after_forward' => ['state' => null, 'queue' => 'Sonder']]]);
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--queue=Sonder']));
        $this->assertCount(0, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'));

        // Andere Queue bzw. Status ausserhalb der Statustypen: erlaubt.
        $this->writeConfig(['znuny' => ['after_forward' => ['state' => 'open', 'queue' => 'Weitergeleitet']]]);
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch', '--dry-run']));
        $this->writeConfig(['znuny' => ['after_forward' => ['state' => 'pending reminder']]]);
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
    }

    public function testBatchForwardsCustomerReplyAsFollowUp(): void
    {
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
        // Kundenantwort oeffnet das Ticket in Znuny wieder - es liegt erneut in der Batch-Queue.
        $this->servers->znunyTicket['Closed'] = false;
        $this->servers->znunyTicket['Article'][] = [
            'ArticleID' => '120', 'SenderType' => 'customer', 'CommunicationChannel' => 'Email', 'IsVisibleForCustomer' => '1',
            'From' => 'max.muster@acme.example', 'To' => 'support@firma.example', 'Subject' => 'Re', 'Body' => 'Noch eine Frage',
        ];
        $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']));
        $this->assertStringContains('Nachtrag mit 1 Artikel(n) an Zammad #31001', $this->output);
        $this->assertCount(1, $this->servers->tickets);
        $this->assertTrue(!empty($this->servers->znunyTicket['Closed']), 'in Znuny wieder geschlossen');
    }

    public function testUnwritableStateFileStopsBeforeZammad(): void
    {
        $this->writeConfig(['state_file' => '/proc/znuny2zammad/state.json']);
        $this->assertSame(Cli::EXIT_ERROR, $this->run(['2024031210000017']));
        $this->assertStringContains('kann nicht angelegt werden', $this->output);
        $this->assertCount(0, $this->servers->http->requestsMatching('POST', '~zammad~'));

        $this->assertSame(Cli::EXIT_ERROR, $this->run(['--check']));
        $this->assertStringContains('FEHLER: Status:', $this->output);
    }

    public function testLockFileProblemsFailClosed(): void
    {
        $this->writeConfig(['lock_file' => '/proc/gibtsnicht/znuny2zammad.lock']);
        $this->assertSame(Cli::EXIT_ERROR, $this->run(['2024031210000017']));
        $this->assertStringContains('Sperrdatei', $this->output);
        $this->assertCount(0, $this->servers->http->requestsMatching('POST', '~zammad~'));
    }

    public function testConfigFalseMeansNotSet(): void
    {
        $this->writeConfig(['log_file' => false, 'http' => ['ca_file' => false]]);
        $this->assertSame(Cli::EXIT_OK, $this->run(['--check']));
        $this->writeConfig(['log_file' => 123]);
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--check']));
        $this->assertStringContains('log_file muss ein Text', $this->output);
    }

    public function testCheckCoversGroupsDerivedFromBatchQueues(): void
    {
        $this->writeConfig(['zammad' => ['group_from_queue' => true], 'batch' => ['queues' => ['Archiv', 'Ohne Gruppe']]]);
        $this->assertSame(Cli::EXIT_ERROR, $this->run(['--check']));
        $this->assertStringContains('Gruppe fuer Batch-Queue "Archiv" "Archiv" ist deaktiviert', $this->output);
        $this->assertStringContains('keine Gruppe "Ohne Gruppe" - Tickets aus dieser Batch-Queue gehen in die Standardgruppe', $this->output);
    }

    public function testCheckWarnsAboutMissingGroupRights(): void
    {
        $this->servers->groupAccess = ['1' => ['create']];
        $this->assertSame(Cli::EXIT_OK, $this->run(['--check']));
        $this->assertStringContains('direkt nur die Rechte [create]', $this->output);
        $this->assertStringContains('Batch-Queue "An Zammad": 1 Ticket(s)', $this->output);
    }

    /**
     * @param array<string,mixed> $override
     */
    private function writeConfig(array $override): void
    {
        $config = FakeServers::config(\Znuny2Zammad\Config::merge([
            'state_file' => $this->stateFile,
            'lock_file'  => $this->stateFile . '.lock',
            'batch'      => ['queues' => ['An Zammad']],
        ], $override));
        file_put_contents($this->configFile, '<?php return ' . var_export($config, true) . ';');
    }

    public function testUsageErrors(): void
    {
        $this->assertSame(Cli::EXIT_OK, $this->run(['--help']));
        $this->assertStringContains('Aufruf:', $this->output);
        $this->assertSame(Cli::EXIT_USAGE, $this->run([]));
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--batch', '123']));
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--articles=alle', '123']));
        $this->assertSame(Cli::EXIT_USAGE, $this->run(['--config=/gibt/es/nicht.php', '123']));
        $this->assertStringContains('nicht gefunden', $this->output);
    }

    public function testCheck(): void
    {
        $this->assertSame(Cli::EXIT_OK, $this->run(['--check']));
        $this->assertStringContains('Znuny:  OK', $this->output);
        $this->assertStringContains('Zammad: OK', $this->output);
        $this->servers->groups = [];
        $this->assertSame(Cli::EXIT_ERROR, $this->run(['--check']));
        $this->assertStringContains('Standardgruppe "Users" nicht gefunden', $this->output);
    }

    public function testLockPreventsParallelRuns(): void
    {
        $handle = fopen($this->stateFile . '.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->assertSame(Cli::EXIT_ERROR, $this->run(['2024031210000017']));
            $this->assertSame(Cli::EXIT_OK, $this->run(['--batch']), 'Cron: einfach beim naechsten Mal');
            $this->assertCount(0, $this->servers->http->requests);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
