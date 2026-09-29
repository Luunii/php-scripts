<?php

declare(strict_types=1);

namespace Znuny2Zammad;

/**
 * Einfacher Logger fuer die Kommandozeile (und optional eine Logdatei fuer Cronjobs).
 */
final class Logger
{
    public const DEBUG = 10;
    public const INFO  = 20;
    public const WARN  = 30;
    public const ERROR = 40;

    private const LABELS = [
        self::DEBUG => 'DEBUG',
        self::INFO  => 'INFO',
        self::WARN  => 'WARNUNG',
        self::ERROR => 'FEHLER',
    ];

    /** @var int */
    private $level;

    /** @var resource|null */
    private $file;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(int $level = self::INFO, ?string $logFile = null, $stdout = null, $stderr = null)
    {
        $this->level  = $level;
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        if ($logFile !== null && $logFile !== '') {
            $handle = @fopen($logFile, 'ab');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Logdatei "%s" kann nicht geoeffnet werden.', $logFile));
            }
            $this->file = $handle;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    public function debug(string $message): void
    {
        $this->log(self::DEBUG, $message);
    }

    public function info(string $message): void
    {
        $this->log(self::INFO, $message);
    }

    public function warn(string $message): void
    {
        $this->log(self::WARN, $message);
    }

    public function error(string $message): void
    {
        $this->log(self::ERROR, $message);
    }

    private function log(int $level, string $message): void
    {
        $label = self::LABELS[$level];

        // Die Logdatei bekommt immer alles ab INFO (DEBUG nur im Verbose-Modus).
        if (is_resource($this->file) && ($level >= self::INFO || $this->level <= self::DEBUG)) {
            fwrite($this->file, sprintf("[%s] %-7s %s\n", date('Y-m-d H:i:s'), $label, $message));
        }

        if ($level < $this->level) {
            return;
        }
        $line = ($level === self::INFO ? '' : $label . ': ') . $message . "\n";
        fwrite($level >= self::WARN ? $this->stderr : $this->stdout, $line);
    }
}
