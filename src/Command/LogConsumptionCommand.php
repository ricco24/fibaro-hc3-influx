<?php

namespace F3ILog\Command;

use F3ILog\HCClient\HCClient;
use F3ILog\Storage\Storage;
use GuzzleHttp\Client;
use InfluxDB\Database;
use InfluxDB\Point;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

class LogConsumptionCommand extends Command
{
    private $influxDb;

    private $hcClient;

    private $storage;

    private $ids;

    private $guzzle;

    /** @var SymfonyStyle */
    private $io;

    public function __construct(Database $influxDb, HCClient $hcClient, Storage $storage, array $ids)
    {
        parent::__construct();
        $this->influxDb = $influxDb;
        $this->hcClient = $hcClient;
        $this->storage = $storage;
        $this->ids = $ids;
        $this->guzzle = new Client();
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('log:consumption')
            ->addOption('start_timestamp', null, InputOption::VALUE_REQUIRED, 'First timestamp to use. Default: 1.1.2020', 1577836800)
            ->addOption('span', null, InputOption::VALUE_REQUIRED, 'Seconds span from last timestamp', 60)
            ->addOption('max_calls', null, InputOption::VALUE_REQUIRED, 'How many maximum times will be events API called (or until last event from HC3 reached)', 3);
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
        $this->io->title('Consumption import from HC3 to InfluxDB');

        // Input data
        $startTimestamp = (int)$input->getOption('start_timestamp');
        $span = (int)$input->getOption('span');
        $maxCalls = (int)$input->getOption('max_calls');

        $this->io->section('Configuration');
        $this->io->writeln(" * start_timestamp: <info>$startTimestamp</info>");
        $this->io->writeln(" * span: <info>$span</info>");
        $this->io->writeln(" * max_calls: <info>$maxCalls</info>");

        $this->io->section('Data preparation');

        $this->io->write(' * loading devices (API) ... ');
        $devices = $this->hcClient->devices();
        if ($devices === null) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln(sprintf('<info>%d</info> devices loaded', count($devices)));

        $this->io->write(' * loading rooms (API) ... ');
        $rooms = $this->hcClient->rooms();
        if ($rooms === null) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln(sprintf('<info>%d</info> rooms loaded', count($rooms)));

        $this->io->write(' * loading sections (API) ... ');
        $sections = $this->hcClient->sections();
        if ($sections === null) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln(sprintf('<info>%d</info> sections loaded', count($sections)));

        $this->io->section('Import');
        $currentTimestamp = time();
        $totalPoints = 0;

        foreach ($this->ids as $id) {
            $this->io->writeln('<info>Device ID: ' . $id . '</info>');
            $this->io->write(' * loading last used timestamp ... ');

            $idStorageKey = 'consumption_' . $id;
            $lastUsedTimestamp = $this->storage->load($idStorageKey);
            $this->io->writeln($lastUsedTimestamp
                ? "<info>$lastUsedTimestamp</info>"
                : "No saved events"
            );

            $timestampFrom = $lastUsedTimestamp ? $lastUsedTimestamp + 1 : $startTimestamp + 1;
            $timestampTo = $timestampFrom + ($span - 1);

            if ($timestampTo > $currentTimestamp) {
                break;
            }

            $idMaxCalls = $maxCalls;
            $break = false;

            $this->io->newLine();
            $progress = new ProgressBar($output);
            $progress->setFormat(" %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%");
            $progress->start($this->calculateProgressbarSteps($timestampFrom, $currentTimestamp, $span, $maxCalls));

            while ($idMaxCalls > 0 && !$break) {
                $idMaxCalls--;
                $data = $this->hcClient->consumptionEnergyCompare($timestampFrom, $timestampTo, 'devices', 'power', $id);

                $this->storage->store($idStorageKey, $timestampTo);
                $timestampFrom = $timestampTo + 1;
                $timestampTo = $timestampFrom + ($span - 1);

                if ($timestampTo > $currentTimestamp) {
                    $break = true;
                }

                if ($data === null) {
                    $progress->advance();
                    $this->io->writeln("noting loaded");
                    continue;
                }

                $influxPoint = $this->createInfluxPoint($data, $devices, $rooms, $sections, (int)($timestampTo + $timestampFrom) / 2);
                $this->influxDb->writePoints([$influxPoint], Database::PRECISION_SECONDS);
                $totalPoints++;
                $progress->advance();
            }

            $progress->finish();
            $this->io->newLine(2);
        }

        $this->io->section('Result');
        $this->io->writeln(sprintf("Duration: <info>%.2fs</info>", microtime(true) - $startTime));
        $this->io->writeln("Total inserted points: <info>$totalPoints</info>");
        $this->io->newLine();

        return 0;
    }

    private function createInfluxPoint(array $data, array $devices, array $rooms, array $sections, $timestamp)
    {
        $device = $devices[$data['id']];
        $room = $this->getRoomFromDevice($device, $rooms);
        $section = $this->getSectionFromRoom($room, $sections);

        return new Point(
            'consumption',
            null,
            [
                'device_id' => $data['id'],
                'device_name' => $device['name'],
                'room_name' => $room && isset($room['name']) ? $room['name'] : 'None',
                'room_id' => $room && isset($room['id']) ? $room['id'] : 'None',
                'section_name' => $section && isset($section['name']) ? $section['name'] : 'None',
                'section_id' => $section && isset($section['id']) ? $section['id'] : 'None'
            ],
            [
                'consumption' => $data['kWh'],
                'powerCurrent' => $data['W'],
                'powerMin' => $data['min'],
                'powerMax' => $data['max'],
                'powerAvg' => $data['avg']
            ],
            (int)$timestamp
        );
    }

    private function getRoomFromDevice($device, $rooms)
    {
        if (!isset($device['roomID'])) {
            return null;
        }

        if (!isset($rooms[$device['roomID']])) {
            return null;
        }

        return $rooms[$device['roomID']];
    }

    private function getSectionFromRoom($room, $sections)
    {
        if (!isset($room['sectionID'])) {
            return null;
        }

        if (!isset($sections[$room['sectionID']])) {
            return null;
        }

        return $sections[$room['sectionID']];
    }

    private function calculateProgressbarSteps($timestampFrom, $currentTimestamp, $span, $maxCalls)
    {
        $timestampSteps = (int) ($currentTimestamp - $timestampFrom)/$span;
        return $timestampSteps > $maxCalls ? $maxCalls : $timestampSteps;
    }
}
