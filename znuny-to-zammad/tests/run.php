<?php

declare(strict_types=1);

// Startet alle Tests:  php tests/run.php [Filter]

require dirname(__DIR__) . '/src/autoload.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/FakeHttpClient.php';
require __DIR__ . '/FakeServers.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$assertions = 0;
foreach ($files as $file) {
    require $file;
    $class = 'Znuny2Zammad\\Tests\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (strpos($method, 'test') !== 0 || ($filter !== '' && stripos($class . '::' . $method, $filter) === false)) {
            continue;
        }
        /** @var Znuny2Zammad\Tests\TestCase $test */
        $test = new $class();
        try {
            $test->setUp();
            $test->$method();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failed++;
            echo "\nFEHLGESCHLAGEN: " . basename($file, '.php') . '::' . $method . "\n" . $e->getMessage() . "\n"
                . '  in ' . $e->getFile() . ':' . $e->getLine() . "\n";
        } finally {
            $test->tearDown();
            $assertions += $test->assertions;
        }
    }
}

printf("\n\n%d Tests, %d Pruefungen, %d fehlgeschlagen\n", $passed + $failed, $assertions, $failed);
exit($failed > 0 ? 1 : 0);
