<?php

declare(strict_types=1);

namespace Znuny2Zammad;

/**
 * Merkt sich, welche Znuny-Tickets bereits an Zammad weitergeleitet wurden
 * (JSON-Datei). Verhindert doppelte Tickets und erlaubt das Fortsetzen
 * abgebrochener Weiterleitungen.
 *
 * Aufbau eines Eintrags (Schluessel = Znuny-TicketID):
 *   {
 *     "znuny_ticket_number": "2024010110000011",
 *     "zammad_ticket_id": 123,
 *     "zammad_ticket_number": "31001",
 *     "articles_done": [11, 12],
 *     "complete": true,
 *     "source_updated": true,
 *     "forwarded_at": "2024-01-01T10:00:00+01:00"
 *   }
 */
final class StateStore
{
    /** @var string|null */
    private $file;

    /** @var array<string,array<string,mixed>> */
    private $data = [];

    /**
     * @param string|null $file null = nichts speichern (z. B. beim Trockenlauf)
     */
    public function __construct(?string $file)
    {
        $this->file = $file;
        if ($file === null || !is_file($file)) {
            return;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" kann nicht gelesen werden.', $file));
        }
        if (trim($content) === '') {
            return;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" enthaelt kein gueltiges JSON.', $file));
        }
        $this->data = $decoded;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $znunyTicketId): ?array
    {
        return $this->data[$znunyTicketId] ?? null;
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function set(string $znunyTicketId, array $entry): void
    {
        $this->data[$znunyTicketId] = $entry;
        $this->save();
    }

    public function remove(string $znunyTicketId): void
    {
        unset($this->data[$znunyTicketId]);
        $this->save();
    }

    private function save(): void
    {
        if ($this->file === null) {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Verzeichnis "%s" kann nicht angelegt werden.', $dir));
        }
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Status kann nicht als JSON gespeichert werden: ' . json_last_error_msg());
        }
        // Atomar schreiben: erst temporaere Datei, dann umbenennen.
        $tmp = $this->file . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $this->file)) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" kann nicht geschrieben werden.', $this->file));
        }
    }
}
