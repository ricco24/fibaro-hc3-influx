<?php

namespace F3ILog\Command;

use F3ILog\HCClient\HCClient;
use F3ILog\Storage\Storage;
use GuzzleHttp\Client;
use InfluxDB\Database;
use InfluxDB\Point;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

class LogRefreshStatesCommand extends Command
{
    private $influxDb;

    private $hcClient;

    private $storage;

    private $guzzle;

    private $io;

    public function __construct(Database $influxDb, HCClient $hcClient, Storage $storage)
    {
        parent::__construct();
        $this->influxDb = $influxDb;
        $this->hcClient = $hcClient;
        $this->storage = $storage;
        $this->guzzle = new Client();
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('log:refreshStates');
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
        $this->io->title('Events import from HC3 to InfluxDB');


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

        $this->io->write(' * loading saved last ... ');
        $last = $this->storage->load('refreshStates');
        if ($last) {
            $this->io->writeln("<info>$last</info>");
        } else {
            $this->io->writeln("No saved last");
            $last = 1;
        }

        $this->io->section('Import');

        $this->io->writeln(sprintf(' * loading refreshStates for last <info>%d</info>', $last));
        $poll = $this->hcClient->refreshStates($last);
        if (!isset($poll['changes'])) {
            $this->io->writeln('<error>No changes key in refreshStates response</error>');
            return 1;
        }
        $this->io->writeln(' * refreshStates loaded');

        $timestamp = $poll['timestamp'];
        if (isset($poll['last'])) {
            $this->storage->store('refreshStates', $poll['last']);
        }

        $points = [];
        foreach ($poll['changes'] as $change) {
            $point = $this->createInfluxPoint($change, $devices, $rooms, $sections, $timestamp);
            if ($point) {
                $points[] = $point;
            }
        }

        if (count($points)) {
            $this->influxDb->writePoints($points, Database::PRECISION_SECONDS);
        }

        $this->io->section('Result');
        $this->io->writeln(sprintf("Duration: <info>%.2fs</info>", microtime(true) - $startTime));
        $this->io->writeln(sprintf("Total changes loaded: <info>%d</info>", count($poll['changes'])));
        $this->io->writeln(sprintf("Total changes saved: <info>%d</info>", count($points)));
        $this->io->newLine();

        return 0;
    }

    private function getFieldValue($value)
    {
        if ($value === "true" || $value === true) {
            return 1.0;
        }
        if ($value === "false" || $value === false) {
            return 0.0;
        }
        return $value;
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

    private function createInfluxPoint(array $change, array $devices, array $rooms, array $sections, $timestamp)
    {
        if (!isset($change['id'])) {
            return null;
        }

        $device = $devices[$change['id']];
        $room = $this->getRoomFromDevice($device, $rooms);
        $section = $this->getSectionFromRoom($room, $sections);

        $fields = [];
        foreach ($change as $k => $v) {
            if (in_array($k, ['id', 'log', 'logTemp', 'lastBreached'])) {
                continue;
            }
            $fields[$k] = $this->getFieldValue($v);
        }

        if (count($fields) === 0) {
            return null;
        }

        return new Point(
            'refreshStates.' . $device['type'],
            null,
            [
                'device_id' => $device['id'],
                'device_name' => $device['name'],
                'device_type' => $device['type'],
                'room_name' => $room && isset($room['name']) ? $room['name'] : 'None',
                'room_id' => $room && isset($room['id']) ? $room['id'] : 'None',
                'section_name' => $section && isset($section['name']) ? $section['name'] : 'None',
                'section_id' => $section && isset($section['id']) ? $section['id'] : 'None'
            ],
            $fields,
            (int) $timestamp
        );
    }
}
