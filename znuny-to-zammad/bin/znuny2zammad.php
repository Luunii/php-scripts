#!/usr/bin/env php
<?php

declare(strict_types=1);

// Leitet Tickets von Znuny an Zammad weiter. Hilfe: php bin/znuny2zammad.php --help

if (PHP_SAPI !== 'cli') {
    exit("Dieses Skript ist nur fuer die Kommandozeile gedacht.\n");
}
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "PHP 7.4 oder neuer wird benoetigt.\n");
    exit(2);
}
foreach (['curl', 'json', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Die PHP-Erweiterung \"$extension\" fehlt.\n");
        exit(2);
    }
}

require dirname(__DIR__) . '/src/autoload.php';

exit((new Znuny2Zammad\Cli())->run($argv));
