<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\ArticleConverter;
use Znuny2Zammad\Config;

final class ArticleConverterTest extends TestCase
{
    /** @var array<string,mixed> */
    private $ticket;

    public function setUp(): void
    {
        $this->ticket = $this->fixture('ticket-get.json')['Ticket'][0];
    }

    private function converter(array $options = []): ArticleConverter
    {
        return new ArticleConverter($options + Config::defaults()['forward'], 'UTC', 'Europe/Berlin');
    }

    /**
     * @return array<string,mixed>
     */
    private function article(string $id): array
    {
        foreach ($this->ticket['Article'] as $article) {
            if ($article['ArticleID'] === $id) {
                return $article;
            }
        }
        throw new \RuntimeException('Artikel ' . $id . ' fehlt in der Fixture');
    }

    public function testArticlesAreSortedChronologically(): void
    {
        $ids = array_column($this->converter()->selectArticles($this->ticket), 'ArticleID');
        $this->assertSame(['101', '102', '103', '104', '105'], $ids);
    }

    public function testArticleSelectionOptions(): void
    {
        $this->assertSame(['101', '102', '103', '105'], array_column($this->converter(['include_internal' => false])->selectArticles($this->ticket), 'ArticleID'));
        $this->assertSame(['101', '103', '104', '105'], array_column($this->converter(['include_system' => false])->selectArticles($this->ticket), 'ArticleID'));
        $this->assertSame(['101'], array_column($this->converter(['articles' => 'first'])->selectArticles($this->ticket), 'ArticleID'));
        $this->assertSame(['105'], array_column($this->converter(['articles' => 'last'])->selectArticles($this->ticket), 'ArticleID'));
        $this->assertSame([], $this->converter()->selectArticles(['TicketID' => 1]));
    }

    public function testCustomerEmailStaysEmailWithHtmlAndInlineImage(): void
    {
        $result  = $this->converter()->convert($this->article('101'), true);
        $payload = $result['payload'];

        $this->assertSame('email', $payload['type']);
        $this->assertSame('Customer', $payload['sender']);
        $this->assertFalse($payload['internal']);
        $this->assertSame('text/html', $payload['content_type']);
        $this->assertSame('"Muster, Max" <Max.Muster@acme.example>', $payload['from']);
        $this->assertSame('support@firma.example', $payload['to']);
        $this->assertSame('kollege@acme.example', $payload['cc']);
        $this->assertSame('<abc@acme.example>', $payload['message_id']);

        // Nur der Inhalt von <body>, ohne <style>.
        $this->assertStringNotContains('<style>', $payload['body']);
        $this->assertStringContains('der Drucker im 2. OG druckt nicht.', $payload['body']);
        // cid: wurde durch ein data:-Bild ersetzt (Zammad macht daraus einen Inline-Anhang).
        $this->assertStringNotContains('cid:', $payload['body']);
        $this->assertStringContains('src="data:image/png;base64,iVBOR', $payload['body']);
        // Kopfzeile mit Originaldatum (UTC -> Europe/Berlin).
        $this->assertStringContains('Ursprünglich in Znuny: E-Mail von Kunde, 12.03.2024 10:15', $payload['body']);

        // HTML-Text (file-2) und Inline-Bild sind keine eigenen Anhaenge mehr.
        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('Fehlerprotokoll.pdf', $payload['attachments'][0]['filename']);
        $this->assertSame('application/pdf', $payload['attachments'][0]['mime-type']);
        $this->assertSame(base64_encode("%PDF-1.4\n"), $payload['attachments'][0]['data']);

        // Kein Auto-Reply von Zammad an den Kunden; Artikel-ID fuer das Fortsetzen.
        $this->assertSame(false, $payload['preferences']['send-auto-response']);
        $this->assertSame(101, $payload['preferences']['znuny_article_id']);
        $this->assertSame([], $result['warnings']);
    }

    public function testCustomerEmailBecomesNoteWithoutGroupEmailAddress(): void
    {
        $result = $this->converter()->convert($this->article('101'), false);
        $this->assertSame('note', $result['payload']['type']);
        $this->assertSame('Customer', $result['payload']['sender']);
        $this->assertFalse(isset($result['payload']['from']));
        $this->assertCount(1, $result['warnings']);
        // Absender/Empfaenger stehen dann in der Kopfzeile.
        $this->assertStringContains('Von: &quot;Muster, Max&quot; &lt;Max.Muster@acme.example&gt;', $result['payload']['body']);
    }

    public function testAgentEmailIsNeverCreatedAsEmail(): void
    {
        $payload = $this->converter()->convert($this->article('103'), true)['payload'];
        $this->assertSame('note', $payload['type'], 'Agenten-E-Mails wuerden von Zammad sonst erneut versendet');
        $this->assertSame('Agent', $payload['sender']);
        $this->assertFalse($payload['internal']);
        $this->assertSame('text/plain', $payload['content_type']);
        $this->assertFalse(isset($payload['to']));
        $this->assertStringContains("[Von: Support <support@firma.example>]\n[An: \"Muster, Max\" <max.muster@acme.example>]", $payload['body']);
        $this->assertStringNotContains('geheim@firma.example', $payload['body'], 'Bcc darf nicht uebernommen werden');
        // Auch Agenten-Artikel duerfen keine Kunden-Trigger ausloesen.
        $this->assertSame(false, $payload['preferences']['send-auto-response']);
    }

    public function testSystemArticleIsNote(): void
    {
        $payload = $this->converter()->convert($this->article('102'), true)['payload'];
        $this->assertSame('note', $payload['type']);
        $this->assertSame('System', $payload['sender']);
    }

    public function testInternalNoteStaysInternal(): void
    {
        $payload = $this->converter()->convert($this->article('104'), true)['payload'];
        $this->assertSame('note', $payload['type']);
        $this->assertTrue($payload['internal']);
        $this->assertStringContains('Treiber pruefen', $payload['body']);
    }

    public function testLatin1HtmlInvalidRecipientsAndOversizedAttachment(): void
    {
        $result  = $this->converter()->convert($this->article('105'), true);
        $payload = $result['payload'];

        // "undisclosed-recipients:;" wuerde Zammad als E-Mail ablehnen.
        $this->assertSame('note', $payload['type']);
        $this->assertSame('text/html', $payload['content_type']);
        $this->assertStringContains('Grüße', $payload['body']);
        $this->assertFalse(isset($payload['attachments']), 'Scan.tif (30 MB) ueberschreitet max_attachment_size');
        $this->assertStringContains('Nicht übernommene Anhänge: Scan.tif (30,0 MB, zu gross)', $payload['body']);
        $this->assertCount(2, $result['warnings']);
    }

    public function testOptionsDisableHtmlHeaderAndAttachments(): void
    {
        $payload = $this->converter(['html_body' => false, 'article_header' => false, 'attachments' => false])
            ->convert($this->article('101'), true)['payload'];
        $this->assertSame('text/plain', $payload['content_type']);
        $this->assertStringContains("Hallo,\n\nder Drucker", $payload['body']);
        $this->assertStringNotContains('Ursprünglich', $payload['body']);
        $this->assertFalse(isset($payload['attachments']));
        // Ausgelassene Anhaenge werden trotzdem genannt (ohne HTML-Text file-2).
        $this->assertStringContains('image001.png', $payload['body']);
        $this->assertStringContains('Fehlerprotokoll.pdf', $payload['body']);
        $this->assertStringNotContains('file-2', $payload['body']);
    }

    public function testCustomerAutoReplyCanBeAllowed(): void
    {
        $payload = $this->converter(['customer_auto_reply' => true])->convert($this->article('101'), true)['payload'];
        $this->assertFalse(isset($payload['preferences']['send-auto-response']));
    }

    public function testPhoneWebAndChatChannels(): void
    {
        $base = ['ArticleID' => '1', 'IsVisibleForCustomer' => '1', 'Body' => 'x', 'Subject' => 's'];
        $c    = $this->converter();
        $this->assertSame('phone', $c->convert($base + ['SenderType' => 'customer', 'CommunicationChannel' => 'Phone'], true)['payload']['type']);
        $this->assertSame('phone', $c->convert($base + ['SenderType' => 'agent', 'CommunicationChannel' => 'Phone'], true)['payload']['type']);
        $this->assertSame('web', $c->convert($base + ['SenderType' => 'customer', 'CommunicationChannel' => 'Web'], true)['payload']['type']);
        // Znuny 6: Kundenportal-Anfragen sind "Internal" + sichtbar.
        $this->assertSame('web', $c->convert($base + ['SenderType' => 'customer', 'CommunicationChannel' => 'Internal'], true)['payload']['type']);

        $chat = $c->convert([
            'ArticleID'              => '9',
            'SenderType'             => 'customer',
            'IsVisibleForCustomer'   => '1',
            'CommunicationChannelID' => '4',
            'ChatMessageList'        => [
                ['CreateTime' => '2024-01-01 10:00:00', 'ChatterName' => 'Max', 'MessageText' => 'Hallo'],
                ['CreateTime' => '2024-01-01 10:01:00', 'ChatterName' => 'Agent', 'MessageText' => '<b>Hi</b>'],
            ],
        ], true)['payload'];
        $this->assertSame('note', $chat['type']);
        $this->assertStringContains("[2024-01-01 10:00:00] Max: Hallo\n[2024-01-01 10:01:00] Agent: Hi", $chat['body']);
    }

    public function testEmptyBodyGetsPlaceholder(): void
    {
        $payload = $this->converter(['article_header' => false])->convert(['ArticleID' => '1', 'SenderType' => 'agent', 'Body' => '  '], true)['payload'];
        $this->assertSame('(kein Text)', $payload['body']);
    }

    public function testHugeHtmlFallsBackToPlainTextWithHtmlAttachment(): void
    {
        $html    = '<html><body><p>' . str_repeat('x', 1500000) . '</p></body></html>';
        $article = [
            'ArticleID'            => '1',
            'SenderType'           => 'agent',
            'CommunicationChannel' => 'Email',
            'IsVisibleForCustomer' => '1',
            'Body'                 => 'Kurzfassung',
            'Attachment'           => [[
                'Filename'    => 'file-2',
                'ContentType' => 'text/html; charset="utf-8"',
                'Disposition' => 'inline',
                'FilesizeRaw' => (string) strlen($html),
                'Content'     => base64_encode($html),
            ]],
        ];
        $result = $this->converter(['article_header' => false])->convert($article, true);
        $this->assertSame('text/plain', $result['payload']['content_type']);
        $this->assertSame('Kurzfassung', $result['payload']['body']);
        $this->assertSame('original-nachricht.html', $result['payload']['attachments'][0]['filename']);
    }

    public function testFilenameSanitizing(): void
    {
        $article = [
            'ArticleID'  => '1',
            'SenderType' => 'agent',
            'Body'       => 'x',
            'Attachment' => [
                ['Filename' => '', 'ContentType' => 'image/png', 'Disposition' => 'attachment', 'Content' => base64_encode('a')],
                ['Filename' => str_repeat('a', 300) . '.pdf', 'ContentType' => '', 'Disposition' => 'attachment', 'Content' => base64_encode('b')],
            ],
        ];
        $files = $this->converter()->convert($article, true)['payload']['attachments'];
        $this->assertSame('anhang-1.png', $files[0]['filename']);
        $this->assertSame(250, mb_strlen($files[1]['filename']));
        $this->assertSame('.pdf', substr($files[1]['filename'], -4));
        $this->assertSame('application/octet-stream', $files[1]['mime-type']);
    }

    public function testCustomerFromTicket(): void
    {
        // CustomerUserID ist ein Login -> E-Mail aus dem ersten Kundenartikel.
        $customer = ArticleConverter::customerFromTicket($this->ticket, $this->ticket['Article']);
        $this->assertSame(['email' => 'max.muster@acme.example', 'firstname' => 'Max', 'lastname' => 'Muster'], $customer);

        $customer = ArticleConverter::customerFromTicket(['CustomerUserID' => 'Kunde@Example.com'], []);
        $this->assertSame(['email' => 'kunde@example.com', 'firstname' => '', 'lastname' => ''], $customer);

        // Punycode aus Mail-Headern wird wie in Zammad als Unicode gespeichert.
        $customer = ArticleConverter::customerFromTicket(['CustomerUserID' => 'max@xn--mller-kva.de'], []);
        $this->assertSame('max@müller.de', $customer['email']);

        $this->assertSame(null, ArticleConverter::customerFromTicket(['CustomerUserID' => 'login'], []));
    }

    public function testOnlyImgSrcReferencesAreEmbedded(): void
    {
        $html    = '<table><tr><td background="cid:bg@x">Text</td></tr></table><p><img alt="a" src=\'cid:logo@x\'></p>';
        $article = $this->htmlArticle($html, [
            ['Filename' => 'bg.png', 'ContentType' => 'image/png', 'ContentID' => '<bg@x>', 'Disposition' => 'inline', 'FilesizeRaw' => '1', 'Content' => base64_encode('B')],
            ['Filename' => 'logo.png', 'ContentType' => 'image/png', 'ContentID' => '<logo@x>', 'Disposition' => 'inline', 'FilesizeRaw' => '1', 'Content' => base64_encode('L')],
        ]);
        $payload = $this->converter(['article_header' => false])->convert($article, true)['payload'];
        $this->assertStringContains("src='data:image/png;base64," . base64_encode('L') . "'", $payload['body']);
        $this->assertStringContains('background="cid:bg@x"', $payload['body']);
        // Das Hintergrundbild geht nicht verloren, sondern wird normaler Anhang.
        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('bg.png', $payload['attachments'][0]['filename']);
    }

    public function testInlineImagesRespectSizeLimits(): void
    {
        $mb      = 1024 * 1024;
        $html    = '<p><img src="cid:a@x"><img src="cid:b@x"><img src="cid:c@x"></p>';
        $article = $this->htmlArticle($html, [
            ['Filename' => 'a.jpg', 'ContentType' => 'image/jpeg', 'ContentID' => '<a@x>', 'Disposition' => 'inline', 'FilesizeRaw' => (string) (25 * $mb), 'Content' => base64_encode('A')],
            ['Filename' => 'b.jpg', 'ContentType' => 'image/jpeg', 'ContentID' => '<b@x>', 'Disposition' => 'inline', 'FilesizeRaw' => (string) (19 * $mb), 'Content' => base64_encode('B')],
            ['Filename' => 'c.jpg', 'ContentType' => 'image/jpeg', 'ContentID' => '<c@x>', 'Disposition' => 'inline', 'FilesizeRaw' => (string) (19 * $mb), 'Content' => base64_encode('C')],
        ]);
        $payload = $this->converter()->convert($article, true)['payload'];
        // a: groesser als max_attachment_size (20 MB), c: Summe > 35 MB -> beide ausgelassen und vermerkt.
        $this->assertStringContains('data:image/jpeg;base64,' . base64_encode('B'), $payload['body']);
        $this->assertStringNotContains(base64_encode('A') . '"', $payload['body']);
        $this->assertStringContains('a.jpg (25,0 MB, zu gross)', $payload['body']);
        $this->assertStringContains('c.jpg (19,0 MB, Gesamtgroesse ueberschritten)', $payload['body']);
        $this->assertFalse(isset($payload['attachments']));
    }

    public function testLongRecipientListIsNotTruncated(): void
    {
        $to = [];
        for ($i = 1; $i <= 90; $i++) {
            $to[] = sprintf('"Mitarbeiter Nummer %d" <mitarbeiter%d@example.com>', $i, $i);
        }
        $article = $this->article('101');
        $article['To'] = implode(', ', $to);
        $payload = $this->converter()->convert($article, true)['payload'];
        $this->assertSame('email', $payload['type']);
        $this->assertSame($article['To'], $payload['to']);
    }

    /**
     * @param array<int,array<string,mixed>> $attachments
     *
     * @return array<string,mixed>
     */
    private function htmlArticle(string $html, array $attachments): array
    {
        array_unshift($attachments, [
            'Filename'    => 'file-2',
            'ContentType' => 'text/html; charset="utf-8"',
            'Disposition' => 'inline',
            'FilesizeRaw' => (string) strlen($html),
            'Content'     => base64_encode($html),
        ]);

        return [
            'ArticleID'            => '1',
            'SenderType'           => 'customer',
            'CommunicationChannel' => 'Email',
            'IsVisibleForCustomer' => '1',
            'From'                 => 'kunde@example.com',
            'To'                   => 'support@example.com',
            'Body'                 => 'Text',
            'Attachment'           => $attachments,
        ];
    }

    public function testCharsetConversion(): void
    {
        $this->assertSame('Grüße', ArticleConverter::toUtf8("Gr\xfc\xdfe", 'iso-8859-1'));
        $this->assertSame('Grüße', ArticleConverter::toUtf8("Gr\xfc\xdfe", 'utf-8'), 'ungueltiges UTF-8 wird als Windows-1252 gelesen');
        $this->assertSame('Grüße', ArticleConverter::toUtf8("Gr\xfc\xdfe", 'unbekannt-123'));
        $this->assertSame('iso-8859-1', ArticleConverter::charsetOf('text/html; charset = "ISO-8859-1"'));
        $this->assertSame('image/png', ArticleConverter::mimeType('Image/PNG; name="a.png"'));
    }
}
