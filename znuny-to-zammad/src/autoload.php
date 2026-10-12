<?php

declare(strict_types=1);

// Kleiner PSR-4-Autoloader, damit das Skript ohne Composer laeuft.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Znuny2Zammad\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
