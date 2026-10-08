<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\ApiException;
use Znuny2Zammad\Forwarder;
use Znuny2Zammad\Logger;
use Znuny2Zammad\StateStore;
use Znuny2Zammad\ZammadClient;
use Znuny2Zammad\ZnunyClient;

final class ForwarderTest extends TestCase
{
    /** @var FakeServers */
    private $servers;

    /** @var string */
    private $stateFile;

    public function setUp(): void
    {
        $this->servers   = new FakeServers($this->fixture('ticket-get.json')['Ticket'][0]);
        $this->stateFile = $this->tempFile('.json');
        unlink($this->stateFile);
    }

    private function forwarder(array $configOverride = [], bool $readOnlyState = false): Forwarder
    {
        $config = FakeServers::config($configOverride);
        $logger = new Logger(Logger::ERROR + 1);
        $znuny  = new ZnunyClient($this->servers->http, $logger, $config['znuny']);
        $zammad = new ZammadClient($this->servers->http, $logger, $config['zammad']['url'], $config['zammad']['token']);

        return new Forwarder($znuny, $zammad, new StateStore($this->stateFile, $readOnlyState), $logger, $config);
    }

    /**
     * @return array<string,mixed>
     */
    private function state(): array
    {
        return json_decode((string) file_get_contents($this->stateFile), true)['4711'];
    }

    public function testForwardsCompleteTicket(): void
    {
        $result = $this->forwarder(['zammad' => ['dynamic_field_map' => ['Kundennummer' => 'customer_number']]])->forward(4711);

        $this->assertSame('forwarded', $result['status']);
        $this->assertSame('31001', $result['zammad_ticket_number']);
        $this->assertSame('https://zammad.test/#ticket/zoom/55', $result['url']);
        $this->assertFalse($result['source_update_failed']);

        // Ticket in Zammad
        $creates = $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~');
        $this->assertCount(1, $creates);
        $ticket = FakeHttpClient::body($creates[0]);
        $this->assertSame('Drucker druckt nicht', $ticket['title']);
        $this->assertSame(3, $ticket['group_id'], 'Queue "Support::1st Level" passt auf "Support::*"');
        $this->assertSame(2, $ticket['state_id']);
        $this->assertSame(3, $ticket['priority_id'], '4 high -> 3 high');
        $this->assertSame(['email' => 'max.muster@acme.example', 'firstname' => 'Max', 'lastname' => 'Muster'], $ticket['customer']);
        $this->assertSame('znuny,znuny-2024031210000017', $ticket['tags']);
        $this->assertSame('K-1001', $ticket['customer_number']);
        $this->assertFalse(isset($ticket['owner_id']));
        $this->assertSame('email', $ticket['article']['type']);
        $this->assertSame('Customer', $ticket['article']['sender']);
        $this->assertSame(false, $ticket['article']['preferences']['send-auto-response']);

        // Weitere Artikel + Info-Notiz, jeweils ohne Agenten-Benachrichtigung
        $articles = $this->servers->http->requestsMatching('POST', '~/api/v1/ticket_articles$~');
        $this->assertCount(5, $articles);
        $ids = [];
        foreach ($articles as $request) {
            $this->assertSame('true', $request['headers']['X-Zammad-Suppress-Notifications'] ?? null);
            $body  = FakeHttpClient::body($request);
            $ids[] = $body['preferences']['znuny_article_id'] ?? 'info';
            $this->assertSame(55, $body['ticket_id']);
        }
        $this->assertSame([102, 103, 104, 105, 'info'], $ids);
        $info = FakeHttpClient::body($articles[4]);
        $this->assertTrue($info['internal']);
        $this->assertStringContains('Ticket#2024031210000017', $info['body']);
        $this->assertStringContains('Berlin, Hamburg', $info['body']);
        $this->assertStringContains('https://znuny.test/znuny/index.pl?Action=AgentTicketZoom;TicketID=4711', $info['body']);

        // Sicherheitsregel: niemals E-Mail-Artikel mit Absender Agent/System (Zammad wuerde sie versenden).
        foreach ($this->servers->sentArticles() as $article) {
            $this->assertFalse(($article['type'] ?? '') === 'email' && ($article['sender'] ?? '') !== 'Customer', 'Artikel wuerde versendet: ' . json_encode($article));
        }

        // Status in Zammad wurde nach den Kunden-Artikeln wieder auf den Zielstatus gesetzt.
        $this->assertCount(0, $this->servers->http->requestsMatching('PUT', '~/api/v1/tickets/55$~'), 'Zielstatus "open" = Status nach Kundenartikel');

        // Znuny: interne Notiz, dann Status
        $updates = $this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~');
        $this->assertCount(2, $updates);
        $note = FakeHttpClient::body($updates[0])['Article'];
        $this->assertSame(0, $note['IsVisibleForCustomer']);
        $this->assertSame('Internal', $note['CommunicationChannel']);
        $this->assertSame('text/plain; charset=utf-8', $note['ContentType']);
        $this->assertStringContains('Zammad-Ticket: #31001', $note['Body']);
        $this->assertStringContains('https://zammad.test/#ticket/zoom/55', $note['Body']);
        $this->assertSame(['State' => 'closed successful', 'PendingTime' => ['Diff' => 1440]], FakeHttpClient::body($updates[1])['Ticket']);
        $this->assertSame('application/json; charset=utf-8', $updates[0]['headers']['Content-Type']);

        // Zugangsdaten nie in der URL
        foreach ($this->servers->http->requests as $request) {
            $this->assertStringNotContains('p%40ss', $request['url']);
            $this->assertStringNotContains('Password', $request['url']);
            $this->assertFalse(isset($request['headers']['From']), 'Zammad wertet "From" als Impersonation');
        }

        $state = $this->state();
        $this->assertTrue($state['complete']);
        $this->assertTrue($state['source_updated']);
        $this->assertSame([101, 102, 103, 104, 105], $state['articles_done']);
    }

    public function testSecondRunSkipsAlreadyForwardedTicket(): void
    {
        $this->forwarder()->forward(4711);
        $requests = count($this->servers->http->requests);

        $result = $this->forwarder()->forward(4711);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('31001', $result['zammad_ticket_number']);
        // Nur in Znuny nachgesehen, ob neue Artikel da sind - sonst nichts.
        foreach (array_slice($this->servers->http->requests, $requests) as $request) {
            $this->assertTrue(strpos($request['url'], FakeServers::ZNUNY) === 0 && $request['method'] !== 'PATCH', $request['method'] . ' ' . $request['url']);
        }

        $result = $this->forwarder()->forward(4711, ['force' => true]);
        $this->assertSame('forwarded', $result['status']);
        $this->assertSame('31002', $result['zammad_ticket_number']);
    }

    public function testResumesAfterFailure(): void
    {
        $this->servers->failArticleCall[2] = static function () {
            return FakeServers::error(500, 'Datenbank weg');
        };
        $this->assertThrows(ApiException::class, function (): void {
            $this->forwarder()->forward(4711);
        }, 'Datenbank weg');

        $state = $this->state();
        $this->assertFalse($state['complete']);
        $this->assertSame([101, 102], $state['articles_done']);
        $this->assertCount(0, $this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'), 'Znuny unveraendert bei Fehler');

        $result = $this->forwarder()->forward(4711);
        $this->assertSame('resumed', $result['status']);
        $this->assertCount(1, $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~'), 'kein zweites Ticket');

        $ids = [];
        foreach ($this->servers->sentArticles() as $article) {
            $ids[] = $article['preferences']['znuny_article_id'] ?? 'info';
        }
        $this->assertSame([101, 102, 103, 104, 105, 'info'], $ids, 'jeder Artikel genau einmal');
        $this->assertTrue($this->state()['complete']);
    }

    public function testResumeUsesArticlesAlreadyInZammad(): void
    {
        // Absturz nach dem Anlegen eines Artikels, aber vor dem Speichern der Statusdatei.
        $this->servers->failArticleCall[2] = static function () {
            return FakeServers::error(500, 'weg');
        };
        try {
            $this->forwarder()->forward(4711);
        } catch (ApiException $e) {
        }
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $state['4711']['articles_done'] = [101];
        file_put_contents($this->stateFile, json_encode($state));

        $this->forwarder()->forward(4711);
        $ids = array_map(static function (array $a) {
            return $a['preferences']['znuny_article_id'] ?? 'info';
        }, $this->servers->sentArticles());
        $this->assertSame([101, 102, 103, 104, 105, 'info'], $ids);
    }

    public function testDryRunChangesNothing(): void
    {
        $result = $this->forwarder([], true)->forward(4711, ['dry_run' => true]);
        $this->assertSame('dry-run', $result['status']);
        foreach ($this->servers->http->requests as $request) {
            $isRead = $request['method'] === 'GET' || preg_match('~/(Session|Ticket/Search)$~', $request['url']);
            $this->assertTrue((bool) $isRead, 'Trockenlauf darf nichts aendern: ' . $request['method'] . ' ' . $request['url']);
        }
        $this->assertFalse(is_file($this->stateFile));
    }

    public function testExistingCustomerIsUsedById(): void
    {
        $this->servers->users = [
            ['id' => 10, 'email' => 'xmax.muster@acme.example', 'active' => true],
            ['id' => 9, 'email' => 'Max.Muster@acme.example', 'active' => true],
        ];
        $this->forwarder()->forward(4711);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0]);
        $this->assertSame(9, $ticket['customer_id']);
        $this->assertFalse(isset($ticket['customer']));
    }

    public function testCustomerOverrideAndMissingCustomer(): void
    {
        $this->forwarder()->forward(4711, ['customer' => 'Erika Muster <erika@example.com>']);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0]);
        $this->assertSame(['email' => 'erika@example.com', 'firstname' => 'Erika', 'lastname' => 'Muster'], $ticket['customer']);

        $this->servers->znunyTicket['CustomerUserID'] = 'login-ohne-mail';
        $this->servers->znunyTicket['Article'] = [$this->servers->znunyTicket['Article'][0]]; // nur Agenten-Artikel
        $this->assertThrows(\RuntimeException::class, function (): void {
            $this->forwarder()->forward(4711, ['force' => true]);
        }, '--customer');

        $this->forwarder(['zammad' => ['create_customers' => false]]);
        $this->assertThrows(\RuntimeException::class, function (): void {
            $this->forwarder(['zammad' => ['create_customers' => false]])->forward(4711, ['force' => true, 'customer' => 'neu@example.com']);
        }, 'create_customers');
    }

    public function testGroupResolution(): void
    {
        // CLI-Option hat Vorrang, verschachtelte Gruppe in UI-Schreibweise
        $this->forwarder()->forward(4711, ['group' => 'Support › 2nd Level']);
        $this->assertSame(4, FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0])['group_id']);

        // Ohne Zuordnung: Standardgruppe (ohne E-Mail-Adresse -> Kunden-E-Mails als Notiz)
        $this->forwarder(['zammad' => ['group_map' => []]])->forward(4711, ['force' => true]);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[1]);
        $this->assertSame(1, $ticket['group_id']);
        $this->assertSame('note', $ticket['article']['type']);

        $this->assertThrows(\RuntimeException::class, function (): void {
            $this->forwarder()->forward(4711, ['force' => true, 'group' => 'Gibtsnicht']);
        }, 'Gibtsnicht');
        $this->assertThrows(\RuntimeException::class, function (): void {
            $this->forwarder()->forward(4711, ['force' => true, 'group' => 'Archiv']);
        }, 'deaktiviert');
    }

    public function testStateMappingWithPendingTime(): void
    {
        $until = time() + 7200;
        $this->servers->znunyTicket['State']               = 'pending reminder';
        $this->servers->znunyTicket['StateType']           = 'pending reminder';
        $this->servers->znunyTicket['RealTillTimeNotUsed'] = (string) $until;

        $this->forwarder(['zammad' => ['state' => null]])->forward(4711);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0]);
        $this->assertSame(3, $ticket['state_id']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $until), $ticket['pending_time']);

        // Kunden-Artikel aendern den Status in Zammad nicht -> keine Korrektur noetig.
        $this->assertCount(0, $this->servers->http->requestsMatching('PUT', '~/api/v1/tickets/55$~'));
    }

    public function testOwnerMappingFallsBackWithoutOwner(): void
    {
        $this->servers->users = [['id' => 666, 'login' => 'max.agent', 'email' => 'max.agent@firma.example', 'active' => true]];
        $this->forwarder(['zammad' => ['owner_map' => ['agent1' => 'max.agent']]])->forward(4711);
        $creates = $this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~');
        $this->assertCount(2, $creates);
        $this->assertSame(666, FakeHttpClient::body($creates[0])['owner_id']);
        $this->assertFalse(isset(FakeHttpClient::body($creates[1])['owner_id']));
    }

    public function testSourceUpdateFailureIsRetriedOnNextRun(): void
    {
        $this->servers->znunyUpdateHandler = static function (array $request) {
            if (isset(FakeHttpClient::body($request)['Ticket'])) {
                return ['Error' => ['ErrorCode' => 'TicketUpdate.InvalidParameter', 'ErrorMessage' => 'TicketUpdate: Ticket->State is invalid!']];
            }

            return null;
        };
        $result = $this->forwarder()->forward(4711);
        $this->assertSame('forwarded', $result['status']);
        $this->assertTrue($result['source_update_failed']);
        $this->assertStringContains('Ticket->State is invalid', implode("\n", $result['warnings']));
        $this->assertFalse($this->state()['source_updated']);
        $this->assertTrue($this->state()['complete']);

        $this->servers->znunyUpdateHandler = null;
        $zammadRequests = count($this->servers->http->requestsMatching('POST', '~zammad~'));
        $result = $this->forwarder()->forward(4711);
        $this->assertSame('source-updated', $result['status']);
        $this->assertCount($zammadRequests, $this->servers->http->requestsMatching('POST', '~zammad~'), 'Zammad nicht erneut');
        $updates = $this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~');
        $this->assertCount(3, $updates, 'Notiz nur einmal, Status zweimal versucht');
        $this->assertTrue($this->state()['source_updated']);
    }

    public function testPendingStateInZnunyGetsPendingTime(): void
    {
        $this->forwarder(['znuny' => ['after_forward' => ['state' => 'pending reminder', 'queue' => 'Weitergeleitet', 'note' => false]]])->forward(4711);
        $updates = $this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~');
        $this->assertCount(1, $updates);
        $this->assertSame(['Queue' => 'Weitergeleitet', 'State' => 'pending reminder', 'PendingTime' => ['Diff' => 1440]], FakeHttpClient::body($updates[0])['Ticket']);
    }

    public function testWarnsAboutAutomaticMailsFromTriggers(): void
    {
        $this->servers->failArticleCall[5] = function (array $request) {
            // Info-Notiz anlegen und zusaetzlich einen "Trigger" simulieren.
            $body = FakeHttpClient::body($request);
            $this->servers->articles[] = ['id' => 1, 'ticket_id' => 55, 'type' => 'email', 'sender' => 'System', 'to' => 'max.muster@acme.example', 'preferences' => []];
            $this->servers->articles[] = ['id' => 2, 'ticket_id' => 55] + $body;

            return FakeHttpClient::json(['id' => 2], 201);
        };
        $result = $this->forwarder()->forward(4711);
        $this->assertStringContains('automatisch eine E-Mail an "max.muster@acme.example"', implode("\n", $result['warnings']));
    }

    public function testArticleModeFirstAndNoInternal(): void
    {
        $this->forwarder(['forward' => ['articles' => 'first', 'info_note' => false]])->forward(4711);
        $this->assertCount(0, $this->servers->http->requestsMatching('POST', '~/api/v1/ticket_articles$~'));

        $this->forwarder(['forward' => ['include_internal' => false, 'include_system' => false]])->forward(4711, ['force' => true]);
        $ids = array_map(static function (array $a) {
            return $a['preferences']['znuny_article_id'] ?? 'info';
        }, array_slice($this->servers->sentArticles(), 1));
        $this->assertSame([101, 103, 105, 'info'], $ids);
    }

    public function testInterruptedCreateIsAdoptedInsteadOfDuplicated(): void
    {
        // Zammad legt das Ticket an, die Antwort geht aber verloren (Zeitueberschreitung/Proxy).
        $this->servers->ticketCreateHandler = function (array $request) {
            $this->servers->ticketCreateHandler = null;
            $this->servers->storeTicket(FakeHttpClient::body($request));

            return FakeServers::error(504, 'Gateway Timeout');
        };
        $this->assertThrows(ApiException::class, function (): void {
            $this->forwarder()->forward(4711);
        }, '504');
        $this->assertTrue(isset($this->state()['create_started_at']), 'Vermerk vor dem Anlegen');

        $result = $this->forwarder()->forward(4711);
        $this->assertSame('resumed', $result['status']);
        $this->assertSame('31001', $result['zammad_ticket_number']);
        $this->assertCount(1, $this->servers->tickets, 'kein zweites Zammad-Ticket');
        $ids = array_map(static function (array $a) {
            return $a['preferences']['znuny_article_id'] ?? 'info';
        }, $this->servers->sentArticles());
        $this->assertSame([101, 102, 103, 104, 105, 'info'], $ids);
        $this->assertTrue($this->state()['complete']);
    }

    public function testRejectedCreateLeavesNoMarker(): void
    {
        $this->servers->ticketCreateHandler = function () {
            $this->servers->ticketCreateHandler = null;

            return FakeServers::error(422, 'Missing required value for field');
        };
        $this->assertThrows(ApiException::class, function (): void {
            $this->forwarder()->forward(4711);
        }, '422');
        $this->assertFalse(isset(json_decode((string) file_get_contents($this->stateFile), true)['4711']));

        $this->assertSame('forwarded', $this->forwarder()->forward(4711)['status']);
        $this->assertCount(1, $this->servers->tickets);
    }

    public function testInterruptedCreateWithoutTagNeedsManualCheck(): void
    {
        file_put_contents($this->stateFile, json_encode(['4711' => ['znuny_ticket_number' => '2024031210000017', 'create_started_at' => '2026-09-29T10:00:00+02:00', 'complete' => false]]));
        $this->assertThrows(\RuntimeException::class, function (): void {
            $this->forwarder(['zammad' => ['tag_ticket_number' => false]])->forward(4711);
        }, '--force');
        $this->assertCount(0, $this->servers->tickets);
    }

    public function testNewStateResetByPublicAgentArticleIsOnlyAWarning(): void
    {
        $this->servers->znunyTicket['Article'][] = [
            'ArticleID' => '106', 'SenderType' => 'agent', 'CommunicationChannel' => 'Phone', 'IsVisibleForCustomer' => '1',
            'Subject' => 'Rueckruf', 'Body' => 'Kunde angerufen', 'CreateTime' => '2024-03-13 12:00:00',
        ];
        $result = $this->forwarder(['zammad' => ['state' => 'new']])->forward(4711);
        $this->assertSame('forwarded', $result['status']);
        $this->assertStringContains('"new" laesst sich nicht wiederherstellen', implode("\n", $result['warnings']));
        $this->assertCount(0, $this->servers->http->requestsMatching('PUT', '~/api/v1/tickets/~'), 'Zammad wuerde state_id=1 ablehnen');
        $this->assertTrue($this->state()['complete']);
        $this->assertTrue($this->state()['source_updated']);
    }

    public function testInfoNoteIsNotDuplicatedOnResume(): void
    {
        // Info-Notiz wird gespeichert, die Antwort geht verloren.
        $this->servers->failArticleCall[5] = function (array $request) {
            $body = FakeHttpClient::body($request);
            $this->servers->articles[] = ['id' => 2000, 'ticket_id' => 55] + $body;

            return FakeServers::error(504, 'Gateway Timeout');
        };
        try {
            $this->forwarder()->forward(4711);
        } catch (ApiException $e) {
        }
        $this->forwarder()->forward(4711);
        $infoNotes = array_filter($this->servers->sentArticles(), static function (array $a): bool {
            return !empty($a['preferences']['znuny_info_note']);
        });
        $this->assertCount(1, $infoNotes);
        $this->assertSame(false, array_values($infoNotes)[0]['preferences']['send-auto-response']);
    }

    public function testWarnsWhenZammadDroppedTags(): void
    {
        $this->servers->ticketCreateHandler = function (array $request) {
            $body = FakeHttpClient::body($request);
            unset($body['tags']); // wie bei ausgeschaltetem "Neue Tags erlauben"

            return FakeHttpClient::json($this->servers->storeTicket($body), 201);
        };
        $result = $this->forwarder()->forward(4711);
        $this->assertStringContains('Tags fehlen im Zammad-Ticket: znuny, znuny-2024031210000017', implode("\n", $result['warnings']));
    }

    public function testInactivePriorityFallsBackToDefault(): void
    {
        $this->servers->priorities[2]['active'] = false; // "3 high"
        $result = $this->forwarder()->forward(4711);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0]);
        $this->assertSame(2, $ticket['priority_id']);
        $this->assertStringContains('"3 high" nicht gefunden oder deaktiviert', implode("\n", $result['warnings']));
    }

    public function testTooLargeTicketFailsCleanlyBeforeDownload(): void
    {
        $this->servers->znunyTicket['Article'][4]['Attachment'][1]['FilesizeRaw'] = (string) (4 * 1024 * 1024 * 1024);
        $old = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        try {
            $this->assertThrows(\RuntimeException::class, function (): void {
                $this->forwarder()->forward(4711);
            }, 'memory_limit');
        } finally {
            ini_set('memory_limit', (string) $old);
        }
        $gets = $this->servers->http->requestsMatching('GET', '~/Ticket/4711\?~');
        $this->assertCount(1, $gets, 'nur die Metadaten wurden geladen');
        $this->assertStringContains('GetAttachmentContents=0', $gets[0]['url']);
    }

    public function testCustomerWithPunycodeDomainMatchesUnicodeUser(): void
    {
        $this->servers->znunyTicket['CustomerUserID'] = 'max@xn--mller-kva.de';
        $this->servers->users = [['id' => 12, 'email' => 'max@müller.de', 'active' => true]];
        $this->forwarder()->forward(4711);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0]);
        $this->assertSame(12, $ticket['customer_id']);
    }

    public function testFollowUpArticlesAreAppendedToExistingTicket(): void
    {
        $this->forwarder()->forward(4711);
        $this->servers->tickets[55]['state_id'] = 4; // in Zammad inzwischen geschlossen

        // Kundenantwort in Znuny (oeffnet das Ticket dort wieder) und unsere eigene Notiz (ArticleID 900).
        $this->servers->znunyTicket['Article'][] = [
            'ArticleID' => '110', 'SenderType' => 'customer', 'CommunicationChannel' => 'Email', 'IsVisibleForCustomer' => '1',
            'From' => 'max.muster@acme.example', 'To' => 'support@firma.example', 'Subject' => 'Re: Drucker', 'Body' => 'Geht wieder nicht.',
            'CreateTime' => '2024-03-20 08:00:00',
        ];
        $this->servers->znunyTicket['Article'][] = [
            'ArticleID' => '900', 'SenderType' => 'agent', 'CommunicationChannel' => 'Internal', 'IsVisibleForCustomer' => '0',
            'Subject' => 'Ticket an Zammad weitergeleitet', 'Body' => 'Dieses Ticket wurde an Zammad weitergeleitet.', 'CreateTime' => '2024-03-14 08:00:00',
        ];
        $patches = count($this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'));

        $result = $this->forwarder()->forward(4711);
        $this->assertSame('followup', $result['status']);
        $this->assertSame(1, $result['articles']);
        $this->assertCount(1, $this->servers->tickets, 'kein neues Zammad-Ticket');
        $last = $this->servers->sentArticles()[count($this->servers->sentArticles()) - 1];
        $this->assertSame(110, $last['preferences']['znuny_article_id']);
        $this->assertSame('email', $last['type']);
        $this->assertSame(2, $this->servers->tickets[55]['state_id'], 'geschlossenes Zammad-Ticket wieder geoeffnet');

        $updates = array_slice($this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'), $patches);
        $this->assertCount(2, $updates);
        $this->assertStringContains('Nachtrag: 1 neue(r) Artikel', FakeHttpClient::body($updates[0])['Article']['Body']);
        $this->assertSame('closed successful', FakeHttpClient::body($updates[1])['Ticket']['State']);

        // Dritter Lauf: nichts Neues mehr.
        $this->assertSame('skipped', $this->forwarder()->forward(4711)['status']);
        $this->assertCount(1, $this->servers->tickets);
    }

    public function testReapplyAfterForwardWithoutNewArticles(): void
    {
        $this->forwarder()->forward(4711);
        $patches = count($this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'));
        $result = $this->forwarder()->forward(4711, ['reapply_after_forward' => true]);
        $this->assertSame('source-updated', $result['status']);
        $updates = array_slice($this->servers->http->requestsMatching('PATCH', '~/Ticket/4711$~'), $patches);
        $this->assertCount(1, $updates, 'nur Status, keine weitere Notiz');
        $this->assertTrue(isset(FakeHttpClient::body($updates[0])['Ticket']));
    }

    public function testRejectedForceKeepsEarlierForward(): void
    {
        $this->forwarder()->forward(4711);
        $this->servers->ticketCreateHandler = function () {
            $this->servers->ticketCreateHandler = null;

            return FakeServers::error(422, 'Invalid value');
        };
        $this->assertThrows(ApiException::class, function (): void {
            $this->forwarder()->forward(4711, ['force' => true]);
        }, '422');
        $this->assertSame(55, $this->state()['zammad_ticket_id'], 'fruehere Weiterleitung wiederhergestellt');
        $this->assertTrue($this->state()['complete']);
        $this->assertSame('skipped', $this->forwarder()->forward(4711)['status']);
        $this->assertCount(1, $this->servers->tickets);
    }

    public function testInterruptedForceDoesNotAdoptEarlierTicket(): void
    {
        $this->forwarder()->forward(4711);
        $this->servers->ticketCreateHandler = function () {
            $this->servers->ticketCreateHandler = null;

            return FakeServers::error(504, 'Gateway Timeout'); // nichts angelegt
        };
        try {
            $this->forwarder()->forward(4711, ['force' => true]);
        } catch (ApiException $e) {
        }
        $this->assertSame([55], $this->state()['exclude_ticket_ids']);

        $result = $this->forwarder()->forward(4711);
        $this->assertSame('forwarded', $result['status'], 'neues Ticket statt das alte zu uebernehmen');
        $this->assertCount(2, $this->servers->tickets);
        $this->assertSame([55], $this->state()['earlier_zammad_ticket_ids']);
        $this->assertSame(4, count(array_filter($this->servers->sentArticles(), static function (array $a): bool {
            return $a['ticket_id'] === 55 && ($a['preferences']['znuny_article_id'] ?? 0) >= 102;
        })), 'altes Ticket unveraendert');
    }

    public function testMissingNumberTagBlocksBlindRecreate(): void
    {
        // Zammad verwirft Tags (Einstellung "Neue Tags" aus).
        $this->servers->ticketCreateHandler = function (array $request) {
            $body = FakeHttpClient::body($request);
            unset($body['tags']);

            return FakeHttpClient::json($this->servers->storeTicket($body), 201);
        };
        $this->forwarder()->forward(4711);

        $this->servers->addZnunyTicket(4712);
        $this->servers->ticketCreateHandler = function (array $request) {
            $body = FakeHttpClient::body($request);
            unset($body['tags']);
            $this->servers->storeTicket($body);

            return FakeServers::error(504, 'Gateway Timeout'); // angelegt, Antwort verloren
        };
        try {
            $this->forwarder()->forward(4712);
        } catch (ApiException $e) {
        }
        $this->servers->ticketCreateHandler = null;
        $this->assertThrows(\RuntimeException::class, function (): void {
            $this->forwarder()->forward(4712);
        }, '"Neue Tags"');
        $this->assertCount(2, $this->servers->tickets, 'kein drittes Ticket');
    }

    public function testCustomerStoredWithPunycodeIsFound(): void
    {
        $this->servers->znunyTicket['CustomerUserID'] = 'max@müller.de';
        $this->servers->users = [['id' => 13, 'email' => 'max@xn--mller-kva.de', 'active' => true]];
        $this->forwarder()->forward(4711);
        $ticket = FakeHttpClient::body($this->servers->http->requestsMatching('POST', '~/api/v1/tickets$~')[0]);
        $this->assertSame(13, $ticket['customer_id']);
    }

    public function testMapValue(): void
    {
        $map = ['Support::1st Level' => 'A', 'Support::*' => 'B', 'Vertrieb*' => 'C', 'Leer' => null];
        $this->assertSame('A', Forwarder::mapValue($map, 'Support::1st Level'));
        $this->assertSame('B', Forwarder::mapValue($map, 'Support::2nd Level'));
        $this->assertSame('C', Forwarder::mapValue($map, 'vertrieb Nord'));
        $this->assertSame(null, Forwarder::mapValue($map, 'Leer'));
        $this->assertSame(null, Forwarder::mapValue($map, 'Raw'));
    }
}
