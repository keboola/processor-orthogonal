<?php

declare(strict_types=1);

use Keboola\Temp\Temp;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/../../vendor/autoload.php';

$testFolder = __DIR__;

$finder = new Finder();
$finder->directories()->sortByName()->in($testFolder)->depth(0);
foreach ($finder as $testSuite) {
    print 'Test ' . $testSuite->getPathname() . "\n";
    $temp = new Temp('my-component');

    $copyCommand = 'cp -R ' . $testSuite->getPathname() . '/source/data/* ' . $temp->getTmpFolder();
    $copyProcess = Process::fromShellCommandline($copyCommand);
    $copyProcess->mustRun();

    if (!file_exists($temp->getTmpFolder() . '/in/tables')) {
        mkdir($temp->getTmpFolder() . '/in/tables', 0777, true);
    }
    if (!file_exists($temp->getTmpFolder() . '/in/files')) {
        mkdir($temp->getTmpFolder() . '/in/files', 0777, true);
    }

    mkdir($temp->getTmpFolder() . '/out/tables', 0777, true);
    mkdir($temp->getTmpFolder() . '/out/files', 0777, true);

    $runCommand = "KBC_DATADIR={$temp->getTmpFolder()} php /code/src/run.php";
    $runProcess = Process::fromShellCommandline($runCommand);
    $runProcess->mustRun(
        function ($type, $buffer): void {
            print $buffer; // print output of the command
        },
    );

    // prettify manifest files
    foreach ((new Finder)->files()->in($temp->getTmpFolder() . '/out/tables')->name(['~.*\.manifest~']) as $file) {
        $path = (string) $file->getRealPath();
        $json = (string) file_get_contents($path);
        try {
            file_put_contents($path, (string) json_encode(json_decode($json), JSON_PRETTY_PRINT));
        } catch (Throwable $e) {
            // If a problem occurs, preserve the original contents
            file_put_contents($path, $json);
        }
    }

    $diffCommand = sprintf(
        'diff --exclude=.gitkeep --ignore-all-space --recursive %s/expected/data/out %s/out',
        $testSuite->getPathname(),
        $temp->getTmpFolder(),
    );
    $diffProcess = Process::fromShellCommandline($diffCommand);
    $diffProcess->run();
    if ($diffProcess->getExitCode() > 0) {
        print "\n" . $diffProcess->getOutput() . "\n";
        exit(1);
    }
}
