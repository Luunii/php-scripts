<?php

declare(strict_types=1);

namespace Znuny2Zammad;

/**
 * Wandelt Znuny-Artikel (Ausgabe von TicketGet) in Zammad-Artikel um.
 *
 * Sicherheitsregeln (siehe README):
 *  - Artikel von Agenten/System werden in Zammad IMMER als Notiz angelegt.
 *    Ein Artikel vom Typ "email" mit Absender Agent/System wuerde Zammad
 *    sofort erneut an den Kunden verschicken.
 *  - Kunden-E-Mails bleiben E-Mails (Absender "Customer" wird nie versendet),
 *    sofern die Zammad-Gruppe eine E-Mail-Adresse hat und die Empfaenger gueltig sind.
 *  - Kunden-Artikel bekommen preferences["send-auto-response"] = false, damit
 *    Zammad-Trigger (z. B. die Eingangsbestaetigung) den Kunden nicht anschreiben.
 */
final class ArticleConverter
{
    /** Zammad erlaubt 1.500.000 Zeichen pro Artikeltext; etwas Reserve. */
    private const BODY_LIMIT = 1400000;

    /** Diese Bildtypen wandelt Zammad aus data:-URIs in Inline-Anhaenge um. */
    private const ZAMMAD_INLINE_TYPES = ['image/png', 'image/jpeg', 'image/jpg'];

    /** Andere Inline-Bilder werden bis zu dieser Groesse direkt in den Text eingebettet. */
    private const MAX_EMBEDDED_OTHER_IMAGE = 300 * 1024;

    /** @var array<string,mixed> */
    private $options;

    /** @var \DateTimeZone */
    private $sourceTimezone;

    /** @var \DateTimeZone */
    private $displayTimezone;

    /**
     * @param array<string,mixed> $options Abschnitt "forward" der Konfiguration
     */
    public function __construct(array $options, string $sourceTimezone = 'UTC', string $displayTimezone = 'Europe/Berlin')
    {
        $this->options         = $options;
        $this->sourceTimezone  = new \DateTimeZone($sourceTimezone !== '' ? $sourceTimezone : 'UTC');
        $this->displayTimezone = new \DateTimeZone($displayTimezone !== '' ? $displayTimezone : date_default_timezone_get());
    }

    /**
     * Liefert die zu uebertragenden Artikel in chronologischer Reihenfolge.
     *
     * @param array<string,mixed> $ticket
     *
     * @return array<int,array<string,mixed>>
     */
    public function selectArticles(array $ticket): array
    {
        $articles = array_values(array_filter((array) ($ticket['Article'] ?? []), 'is_array'));
        usort($articles, static function (array $a, array $b): int {
            return (int) ($a['ArticleID'] ?? 0) <=> (int) ($b['ArticleID'] ?? 0);
        });

        $includeInternal = (bool) ($this->options['include_internal'] ?? true);
        $includeSystem   = (bool) ($this->options['include_system'] ?? true);

        $articles = array_values(array_filter($articles, static function (array $article) use ($includeInternal, $includeSystem): bool {
            if (!$includeInternal && !self::isVisibleForCustomer($article)) {
                return false;
            }
            if (!$includeSystem && self::senderType($article) === 'system') {
                return false;
            }

            return true;
        }));

        $mode = (string) ($this->options['articles'] ?? 'all');
        if ($articles !== [] && $mode === 'first') {
            return [$articles[0]];
        }
        if ($articles !== [] && $mode === 'last') {
            return [$articles[count($articles) - 1]];
        }

        return $articles;
    }

    /**
     * @param array<string,mixed> $article     Znuny-Artikel
     * @param bool                $groupHasEmail Zielgruppe in Zammad hat eine E-Mail-Adresse
     *
     * @return array{payload: array<string,mixed>, warnings: string[]}
     */
    public function convert(array $article, bool $groupHasEmail): array
    {
        $warnings = [];
        $sender   = self::senderType($article);
        $channel  = self::channel($article);
        $visible  = self::isVisibleForCustomer($article);
        $to       = self::header($article, 'To');
        $cc       = self::header($article, 'Cc');
        $type     = $this->zammadType($sender, $channel, $visible, $to, $groupHasEmail, $warnings, $article);

        $attachments = array_values(array_filter((array) ($article['Attachment'] ?? []), 'is_array'));
        $body        = $this->buildBody($article, $attachments, $warnings);
        $files       = $this->buildAttachments($attachments, $body['used'], $warnings);

        $header = '';
        if (!empty($this->options['article_header']) || $files['skipped'] !== []) {
            $header = $this->buildHeader($article, $type, $channel, $sender, $files['skipped'], $body['content_type']);
        }

        $payload = [
            'type'         => $type,
            'sender'       => self::zammadSender($sender),
            'internal'     => !$visible,
            'subject'      => self::limit(self::header($article, 'Subject'), 3000),
            'body'         => $header . $body['body'],
            'content_type' => $body['content_type'],
        ];
        if ($type === 'email') {
            // Nur bei Kunden-E-Mails speichert Zammad die Adressen unveraendert.
            $payload['from'] = self::limit(self::header($article, 'From'), 3000);
            $payload['to']   = self::limit($to, 3000);
            if ($cc !== '') {
                $payload['cc'] = self::limit($cc, 3000);
            }
            $replyTo = self::header($article, 'ReplyTo');
            if ($replyTo !== '') {
                $payload['reply_to'] = self::limit($replyTo, 300);
            }
            $messageId = self::header($article, 'MessageID');
            if ($messageId !== '') {
                $payload['message_id'] = self::limit($messageId, 3000);
            }
            $inReplyTo = self::header($article, 'InReplyTo');
            if ($inReplyTo !== '') {
                $payload['in_reply_to'] = self::limit($inReplyTo, 3000);
            }
        }
        if ($files['attachments'] !== []) {
            $payload['attachments'] = $files['attachments'];
        }

        $preferences = ['znuny_article_id' => (int) ($article['ArticleID'] ?? 0)];
        if ($sender === 'customer' && empty($this->options['customer_auto_reply'])) {
            // Muss ein echtes JSON-false sein (Zammad vergleicht "== false").
            $preferences['send-auto-response'] = false;
        }
        $payload['preferences'] = $preferences;

        return ['payload' => $payload, 'warnings' => $warnings];
    }

    /**
     * Ermittelt den Kunden (E-Mail + Name) aus den Znuny-Daten.
     *
     * @param array<string,mixed>            $ticket
     * @param array<int,array<string,mixed>> $articles alle Artikel des Tickets
     *
     * @return array{email:string,firstname:string,lastname:string}|null
     */
    public static function customerFromTicket(array $ticket, array $articles): ?array
    {
        $customerUser = trim((string) ($ticket['CustomerUserID'] ?? ''));
        $email        = EmailAddress::isValid($customerUser) ? mb_strtolower($customerUser) : '';
        $name         = '';

        foreach ($articles as $article) {
            if (self::senderType($article) !== 'customer') {
                continue;
            }
            $from = EmailAddress::first(self::header($article, 'From'));
            if ($from === null) {
                continue;
            }
            if ($email === '') {
                $email = $from['email'];
            }
            if ($from['email'] === $email) {
                $name = $from['name'];
                break;
            }
        }

        if ($email === '') {
            return null;
        }
        [$firstname, $lastname] = EmailAddress::splitName($name);

        return ['email' => $email, 'firstname' => $firstname, 'lastname' => $lastname];
    }

    public static function senderType(array $article): string
    {
        $sender = strtolower(trim((string) ($article['SenderType'] ?? '')));

        return in_array($sender, ['agent', 'system', 'customer'], true) ? $sender : 'agent';
    }

    public static function isVisibleForCustomer(array $article): bool
    {
        return (int) ($article['IsVisibleForCustomer'] ?? 0) === 1;
    }

    public static function channel(array $article): string
    {
        if (!empty($article['CommunicationChannel'])) {
            return (string) $article['CommunicationChannel'];
        }

        return isset($article['ChatMessageList']) ? 'Chat' : '';
    }

    /**
     * @param string[]            $warnings
     * @param array<string,mixed> $article
     */
    private function zammadType(string $sender, string $channel, bool $visible, string $to, bool $groupHasEmail, array &$warnings, array $article): string
    {
        if ($sender !== 'customer') {
            // Agenten- und Systemartikel nie als E-Mail/SMS anlegen (wuerden versendet).
            return $channel === 'Phone' ? 'phone' : 'note';
        }

        switch ($channel) {
            case 'Email':
                if (!($this->options['keep_customer_emails'] ?? true)) {
                    return 'note';
                }
                // Zammad lehnt E-Mail-Artikel ab, wenn die Gruppe keine E-Mail-Adresse hat
                // oder die Empfaenger ungueltig sind - dann lieber als Notiz uebernehmen.
                if (!$groupHasEmail) {
                    $warnings[] = sprintf('Artikel %s: Zammad-Gruppe hat keine E-Mail-Adresse, Kunden-E-Mail wird als Notiz uebernommen.', $article['ArticleID'] ?? '?');

                    return 'note';
                }
                if (!EmailAddress::isValidList($to)) {
                    $warnings[] = sprintf('Artikel %s: Empfaenger "%s" nicht gueltig, Kunden-E-Mail wird als Notiz uebernommen.', $article['ArticleID'] ?? '?', $to);

                    return 'note';
                }

                return 'email';
            case 'Phone':
                return 'phone';
            case 'Web':
                return 'web';
            case 'Internal':
                // Znuny 6.x speichert Anfragen aus dem Kundenportal als "Internal".
                return $visible ? 'web' : 'note';
            default:
                return 'note';
        }
    }

    private static function zammadSender(string $sender): string
    {
        switch ($sender) {
            case 'customer':
                return 'Customer';
            case 'system':
                return 'System';
            default:
                return 'Agent';
        }
    }

    /**
     * @param array<string,mixed>            $article
     * @param array<int,array<string,mixed>> $attachments
     * @param string[]                       $warnings
     *
     * @return array{body:string,content_type:string,used:int[]}
     */
    private function buildBody(array $article, array $attachments, array &$warnings): array
    {
        $plain = str_replace("\r\n", "\n", (string) ($article['Body'] ?? ''));
        if (isset($article['ChatMessageList']) && is_array($article['ChatMessageList'])) {
            $plain = self::renderChat($article['ChatMessageList']);
        }

        $htmlIndex = self::findHtmlBody($attachments);
        // Standard: der HTML-Text wird nicht zusaetzlich als Anhang uebernommen.
        $used = $htmlIndex !== null ? [$htmlIndex] : [];
        if ($htmlIndex !== null && ($this->options['html_body'] ?? true)) {
            $html = self::decodeHtml($attachments[$htmlIndex]);
            if ($html !== null && trim(strip_tags($html, '<img>')) !== '') {
                // 1. Versuch: Inline-Bilder einbetten.
                $used     = [$htmlIndex];
                $embedded = self::embedInlineImages($html, $attachments, $used);
                if (self::zammadBodyLength($embedded) <= self::BODY_LIMIT) {
                    return ['body' => $embedded, 'content_type' => 'text/html', 'used' => $used];
                }
                // 2. Versuch: Bilder als normale Anhaenge.
                if (self::zammadBodyLength($html) <= self::BODY_LIMIT) {
                    $warnings[] = sprintf('Artikel %s: Text zu gross, Inline-Bilder werden als Anhang uebernommen.', $article['ArticleID'] ?? '?');

                    return ['body' => $html, 'content_type' => 'text/html', 'used' => [$htmlIndex]];
                }
                // 3. Versuch: Klartext, HTML-Original bleibt als Anhang erhalten.
                $warnings[] = sprintf('Artikel %s: HTML-Text zu gross, Klartext wird uebernommen (HTML als Anhang).', $article['ArticleID'] ?? '?');
                $used = [];
            }
        }

        if (trim($plain) === '') {
            $plain = '(kein Text)';
        }
        if (mb_strlen($plain) > self::BODY_LIMIT) {
            $warnings[] = sprintf('Artikel %s: Text gekuerzt (Zammad-Grenze 1.500.000 Zeichen).', $article['ArticleID'] ?? '?');
            $plain = mb_substr($plain, 0, self::BODY_LIMIT) . "\n[... gekuerzt]";
        }

        return ['body' => $plain, 'content_type' => 'text/plain', 'used' => $used];
    }

    /**
     * Znuny liefert den HTML-Text als Anhang "file-2" (bzw. "file-1"/"file-1.html").
     *
     * @param array<int,array<string,mixed>> $attachments
     */
    public static function findHtmlBody(array $attachments): ?int
    {
        foreach ($attachments as $index => $attachment) {
            $filename    = strtolower((string) ($attachment['Filename'] ?? ''));
            $contentType = (string) ($attachment['ContentType'] ?? '');
            $disposition = strtolower((string) ($attachment['Disposition'] ?? ''));
            if (
                in_array($filename, ['file-1', 'file-2', 'file-1.html'], true)
                && stripos($contentType, 'text/html') !== false
                && ($disposition === '' || $disposition === 'inline')
            ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private static function decodeHtml(array $attachment): ?string
    {
        $raw = base64_decode((string) ($attachment['Content'] ?? ''), true);
        if ($raw === false || $raw === '') {
            return null;
        }
        $html = self::toUtf8($raw, self::charsetOf((string) ($attachment['ContentType'] ?? '')));

        // Nur den Inhalt von <body> uebernehmen (Zammad entfernt <head>/<style> ohnehin).
        if (preg_match('~<body\b[^>]*>(.*)</body\s*>~is', $html, $m)) {
            $html = $m[1];
        } else {
            $html = (string) preg_replace('~<head\b.*?</head\s*>~is', '', $html);
        }

        return trim($html);
    }

    /**
     * Ersetzt cid:-Verweise durch data:-URIs (Zammad macht daraus Inline-Anhaenge).
     *
     * @param array<int,array<string,mixed>> $attachments
     * @param int[]                          $used Indizes der verbrauchten Anhaenge (wird ergaenzt)
     */
    private static function embedInlineImages(string $html, array $attachments, array &$used): string
    {
        $byCid = [];
        foreach ($attachments as $index => $attachment) {
            $cid = trim((string) ($attachment['ContentID'] ?? ''), " <>\t");
            if ($cid !== '') {
                $byCid[mb_strtolower($cid)] = $index;
            }
        }
        if ($byCid === []) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(["\'])cid:([^"\']+)\1/i',
            static function (array $m) use ($attachments, $byCid, &$used): string {
                $key = mb_strtolower(rawurldecode(trim($m[2])));
                if (!isset($byCid[$key])) {
                    return $m[0];
                }
                $index      = $byCid[$key];
                $attachment = $attachments[$index];
                $mime       = self::mimeType((string) ($attachment['ContentType'] ?? ''));
                $content    = (string) ($attachment['Content'] ?? '');
                if ($content === '' || strpos($mime, 'image/') !== 0) {
                    return $m[0];
                }
                if ($mime === 'image/jpg') {
                    $mime = 'image/jpeg';
                }
                $convertible = in_array($mime, self::ZAMMAD_INLINE_TYPES, true);
                if (!$convertible && self::size($attachment) > self::MAX_EMBEDDED_OTHER_IMAGE) {
                    return $m[0];
                }
                if (!in_array($index, $used, true)) {
                    $used[] = $index;
                }

                return $m[1] . 'data:' . $mime . ';base64,' . $content . $m[1];
            },
            $html
        );
    }

    /**
     * Laenge, wie Zammad sie prueft (PNG/JPEG-data:-Bilder werden vorher herausgeloest).
     */
    private static function zammadBodyLength(string $html): int
    {
        return mb_strlen((string) preg_replace('~data:image/(?:png|jpe?g);base64,[A-Za-z0-9+/=]+~i', '', $html));
    }

    /**
     * @param array<int,array<string,mixed>> $attachments
     * @param int[]                          $used
     * @param string[]                       $warnings
     *
     * @return array{attachments: array<int,array<string,string>>, skipped: string[]}
     */
    private function buildAttachments(array $attachments, array $used, array &$warnings): array
    {
        $result  = [];
        $skipped = [];
        $htmlIndex = self::findHtmlBody($attachments);
        if (!($this->options['attachments'] ?? true)) {
            foreach ($attachments as $index => $attachment) {
                if (!in_array($index, $used, true) && $index !== $htmlIndex) {
                    $skipped[] = self::describe($attachment, 'Anhaenge deaktiviert');
                }
            }

            return ['attachments' => [], 'skipped' => $skipped];
        }

        $maxSize  = (int) ($this->options['max_attachment_size'] ?? 0);
        $maxTotal = (int) ($this->options['max_article_attachments_size'] ?? 0);
        $total    = 0;
        foreach ($used as $index) {
            // Eingebettete Bilder zaehlen zur Anfragegroesse, der HTML-Text nicht.
            if (isset($attachments[$index]) && $index !== $htmlIndex) {
                $total += self::size($attachments[$index]);
            }
        }

        $number = 0;
        foreach ($attachments as $index => $attachment) {
            if (in_array($index, $used, true)) {
                continue;
            }
            $number++;
            $size = self::size($attachment);
            if ($maxSize > 0 && $size > $maxSize) {
                $skipped[] = self::describe($attachment, 'zu gross');
                continue;
            }
            if ($maxTotal > 0 && $total + $size > $maxTotal) {
                $skipped[] = self::describe($attachment, 'Gesamtgroesse ueberschritten');
                continue;
            }
            $content = (string) ($attachment['Content'] ?? '');
            if ($content === '' && $size > 0) {
                // Trockenlauf (GetAttachmentContents=0): nichts zu uebertragen.
                continue;
            }
            $total += $size;

            $mime = self::mimeType((string) ($attachment['ContentType'] ?? ''));
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }
            $filename = self::filename((string) ($attachment['Filename'] ?? ''), $number, $mime);
            // Den HTML-Text nicht unter dem internen Namen "file-2" ablegen.
            if ($index === $htmlIndex) {
                $filename = 'original-nachricht.html';
            }
            $entry = ['filename' => $filename, 'data' => $content, 'mime-type' => $mime];
            $charset = self::charsetOf((string) ($attachment['ContentType'] ?? ''));
            if ($charset !== '') {
                $entry['charset'] = $charset;
            }
            $result[] = $entry;
        }
        foreach ($skipped as $description) {
            $warnings[] = 'Anhang nicht uebernommen: ' . $description;
        }

        return ['attachments' => $result, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $article
     * @param string[]            $skipped
     */
    private function buildHeader(array $article, string $type, string $channel, string $sender, array $skipped, string $contentType): string
    {
        $channelNames = ['Email' => 'E-Mail', 'Phone' => 'Telefon', 'Internal' => 'Notiz', 'Chat' => 'Chat', 'Web' => 'Web'];
        $senderNames  = ['agent' => 'Agent', 'customer' => 'Kunde', 'system' => 'System'];

        $summary = 'Ursprünglich in Znuny: ' . ($channelNames[$channel] ?? ($channel !== '' ? $channel : 'Artikel'))
            . ' von ' . $senderNames[$sender];
        $date = $this->formatDate((string) ($article['CreateTime'] ?? ''));
        if ($date !== '') {
            $summary .= ', ' . $date;
        }

        $lines = [];
        if (!empty($this->options['article_header'])) {
            $lines[] = $summary;
            if ($type !== 'email') {
                // Zammad ueberschreibt "from" bei Notizen, daher hier festhalten.
                foreach (['From' => 'Von', 'To' => 'An', 'Cc' => 'Cc'] as $field => $label) {
                    $value = self::header($article, $field);
                    if ($value !== '') {
                        $lines[] = $label . ': ' . $value;
                    }
                }
            }
        }
        if ($skipped !== []) {
            $lines[] = 'Nicht übernommene Anhänge: ' . implode('; ', $skipped);
        }
        if ($lines === []) {
            return '';
        }

        if ($contentType === 'text/html') {
            $escaped = array_map(static function (string $line): string {
                return htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }, $lines);

            return '<div style="color: #6b7280;"><small>' . implode('<br>', $escaped) . '</small></div><hr>';
        }

        return '[' . implode("]\n[", $lines) . "]\n\n";
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            $date = new \DateTimeImmutable($value, $this->sourceTimezone);
        } catch (\Exception $e) {
            return $value;
        }

        return $date->setTimezone($this->displayTimezone)->format('d.m.Y H:i');
    }

    /**
     * @param array<int,mixed> $messages
     */
    private static function renderChat(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $lines[] = sprintf(
                '[%s] %s: %s',
                (string) ($message['CreateTime'] ?? ''),
                (string) ($message['ChatterName'] ?? ''),
                trim(strip_tags((string) ($message['MessageText'] ?? '')))
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $article
     */
    private static function header(array $article, string $field): string
    {
        $value = $article[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function mimeType(string $contentType): string
    {
        $parts = explode(';', $contentType, 2);

        return strtolower(trim($parts[0]));
    }

    public static function charsetOf(string $contentType): string
    {
        if (preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $contentType, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

    public static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower($charset);
        if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8' || $charset === 'us-ascii') {
            return mb_check_encoding($text, 'UTF-8') ? $text : self::fromWindows1252($text);
        }
        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        } catch (\Throwable $e) {
            // Unbekannter Zeichensatz fuer mbstring, iconv versuchen.
        }
        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : self::fromWindows1252($text);
    }

    private static function fromWindows1252(string $text): string
    {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

        return is_string($converted) ? $converted : $text;
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private static function size(array $attachment): int
    {
        if (isset($attachment['FilesizeRaw']) && is_numeric($attachment['FilesizeRaw'])) {
            return (int) $attachment['FilesizeRaw'];
        }

        return (int) floor(strlen((string) ($attachment['Content'] ?? '')) * 3 / 4);
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private static function describe(array $attachment, string $reason): string
    {
        return sprintf('%s (%s, %s)', (string) ($attachment['Filename'] ?? '?'), self::humanSize(self::size($attachment)), $reason);
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        return $bytes . ' Bytes';
    }

    private static function filename(string $filename, int $number, string $mime): string
    {
        $filename = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $extension = ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif', 'application/pdf' => '.pdf', 'text/plain' => '.txt', 'text/html' => '.html'];
            $filename  = 'anhang-' . $number . ($extension[$mime] ?? '');
        }
        if (mb_strlen($filename) > 250) {
            $dot       = mb_strrpos($filename, '.');
            $extension = ($dot !== false && mb_strlen($filename) - $dot <= 10) ? mb_substr($filename, $dot) : '';
            $filename  = mb_substr($filename, 0, 250 - mb_strlen($extension)) . $extension;
        }

        return $filename;
    }

    private static function limit(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
