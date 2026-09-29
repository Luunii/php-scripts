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
        $config = FakeServers::config([
            'state_file' => $this->stateFile,
            'lock_file'  => $this->stateFile . '.lock',
            'batch'      => ['queues' => ['An Zammad']],
        ]);
        file_put_contents($this->configFile, '<?php return ' . var_export($config, true) . ';');
    }

    public function tearDown(): void
    {
        @unlink($this->stateFile . '.lock');
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

        $this->assertSame(Cli::EXIT_OK, $this->run(['--queue=Andere Queue', '--no-source-update']));
        $this->assertSame(['Andere Queue'], FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/Ticket/Search$~')[1])['Queues']);
        $this->assertCount(1, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'));
        $this->assertCount(0, $this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'));
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
