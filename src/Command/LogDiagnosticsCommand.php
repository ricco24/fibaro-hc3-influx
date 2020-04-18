<?php

namespace F3ILog\Command;

use F3ILog\HCClient\HCClient;
use GuzzleHttp\Client;
use InfluxDB\Database;
use InfluxDB\Point;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

class LogDiagnosticsCommand extends Command
{
    private $influxDb;

    private $hcClient;

    private $guzzle;

    /** @var SymfonyStyle */
    private $io;

    public function __construct(Database $influxDb, HCClient $hcClient)
    {
        parent::__construct();
        $this->influxDb = $influxDb;
        $this->hcClient = $hcClient;
        $this->guzzle = new Client();
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('log:diagnostics');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $startTime = microtime(true);
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Diagnostic data import from HC3 to InfluxDB');

        $this->io->section('Import');
        $this->io->write(' * loading diagnostics data (API) ... ');
        $diagnostics = $this->hcClient->diagnostics();
        if (!$diagnostics) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln('<info>loaded</info>');

        $influxPoints = $this->createInfluxPoints($diagnostics, time());
        $this->influxDb->writePoints($influxPoints, Database::PRECISION_SECONDS);

        $this->io->section('Result');
        $this->io->writeln(sprintf("Duration: <info>%.2fs</info>", microtime(true) - $startTime));
        $this->io->writeln("Data inserted");
        $this->io->newLine();

        return 0;
    }

    private function createInfluxPoints(array $diagnostics, $timestamp)
    {
        $result = [
            new Point(
                'system.memory',
                null,
                [],
                [
                    'free' => (int) $diagnostics['memory']['free'],
                    'cache' => (int) $diagnostics['memory']['cache'],
                    'buffers' => (int) $diagnostics['memory']['buffers'],
                    'used' => (int) $diagnostics['memory']['used'],
                ],
                (int) $timestamp
            )
        ];

        foreach ($diagnostics['storage'] as $storageType => $allStorageData) {
            foreach ($allStorageData as $storageData) {
                $result = array_merge($result, [
                    new Point(
                        'system.storage',
                        null,
                        [
                            'type' => $storageType,
                            'name' => $storageData['name']
                        ],
                        [
                            'used' => (float) $storageData['used']
                        ],
                        (int) $timestamp
                    )
                ]);
            }
        }


        foreach ($diagnostics['cpuLoad'] as $cpuData) {
            $result = array_merge($result, [
                new Point(
                    'system.cpuLoad',
                    null,
                    [
                        'name' => $cpuData['name'],
                        'user' => $cpuData['user']
                    ],
                    [
                        'nice' => (int) $cpuData['nice'],
                        'system' => (int) $cpuData['system'],
                        'idle' => (int) $cpuData['idle']
                    ],
                    (int) $timestamp
                )
            ]);
        }

        return $result;
    }
}
