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

    /** @var bool */
    private $readOnly;

    /** @var array<string,array<string,mixed>> */
    private $data = [];

    /**
     * @param string|null $file     null = nur im Speicher halten
     * @param bool        $readOnly Datei nur lesen (Trockenlauf)
     */
    public function __construct(?string $file, bool $readOnly = false)
    {
        $this->file     = $file;
        $this->readOnly = $readOnly;
        $this->data     = $this->read();
    }

    /**
     * Prueft vor der ersten Aenderung in Zammad, dass der Status gespeichert werden kann.
     * Sonst wuerde jeder Lauf dasselbe Ticket erneut anlegen.
     */
    public function assertWritable(): void
    {
        if ($this->file === null || $this->readOnly) {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Verzeichnis "%s" fuer die Statusdatei kann nicht angelegt werden%s.', $dir, self::userHint()));
        }
        if (!is_writable($dir) || (is_file($this->file) && !is_writable($this->file))) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" ist nicht beschreibbar%s.', $this->file, self::userHint()));
        }
    }

    /** Schluessel fuer allgemeine Vermerke (keine Ticket-ID). */
    private const META = '_meta';

    /**
     * Anzahl der Ticket-Eintraege.
     */
    public function count(): int
    {
        return count($this->data) - (isset($this->data[self::META]) ? 1 : 0);
    }

    /**
     * @return mixed|null
     */
    public function meta(string $name)
    {
        return $this->data[self::META][$name] ?? null;
    }

    /**
     * @param mixed|null $value null = Vermerk entfernen
     */
    public function setMeta(string $name, $value): void
    {
        $meta = (array) ($this->data[self::META] ?? []);
        if (($meta[$name] ?? null) === $value) {
            return;
        }
        if ($value === null) {
            unset($meta[$name]);
        } else {
            $meta[$name] = $value;
        }
        $this->update(self::META, $meta);
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
        $this->update($znunyTicketId, $entry);
    }

    public function remove(string $znunyTicketId): void
    {
        $this->update($znunyTicketId, null);
    }

    /**
     * @param array<string,mixed>|null $entry null = Eintrag loeschen
     */
    private function update(string $znunyTicketId, ?array $entry): void
    {
        if ($entry === null) {
            unset($this->data[$znunyTicketId]);
        } else {
            $this->data[$znunyTicketId] = $entry;
        }
        if ($this->file === null || $this->readOnly) {
            return;
        }

        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Verzeichnis "%s" kann nicht angelegt werden%s.', $dir, self::userHint()));
        }

        // Unter Sperre neu einlesen und nur diesen Eintrag ersetzen, damit Eintraege
        // anderer Prozesse (z. B. zweite Konfiguration mit derselben Statusdatei) erhalten bleiben.
        // Eigener Dateiname: darf nicht die Sperrdatei des Gesamtlaufs sein (sonst Selbstblockade).
        $lock = @fopen($this->file . '.writelock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            $data = $this->read();
            if ($entry === null) {
                unset($data[$znunyTicketId]);
            } else {
                $data[$znunyTicketId] = $entry;
            }
            $this->write($data);
            $this->data = $data;
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function read(): array
    {
        if ($this->file === null || !is_file($this->file)) {
            return [];
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" kann nicht gelesen werden%s.', $this->file, self::userHint()));
        }
        if (trim($content) === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" enthaelt kein gueltiges JSON.', $this->file));
        }

        return $decoded;
    }

    /**
     * @param array<string,array<string,mixed>> $data
     */
    private function write(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Status kann nicht als JSON gespeichert werden: ' . json_last_error_msg());
        }
        // Atomar schreiben: erst temporaere Datei, dann umbenennen.
        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, (string) $this->file)) {
            throw new \RuntimeException(sprintf('Statusdatei "%s" kann nicht geschrieben werden%s.', $this->file, self::userHint()));
        }
    }

    private static function userHint(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && isset($user['name'])) {
                return sprintf(' (Skript laeuft als Benutzer "%s")', $user['name']);
            }
        }

        return '';
    }
}
