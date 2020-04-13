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

class LogDevicesCommand extends Command
{
    private $influxDb;

    private $hcClient;

    private $lastFile;

    private $guzzle;

    private $io;

    public function __construct(Database $influxDb, HCClient $hcClient, $lastFile)
    {
        parent::__construct();
        $this->influxDb = $influxDb;
        $this->hcClient = $hcClient;
        $this->lastFile = $lastFile;
        $this->guzzle = new Client();
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('log:devices');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->io = new SymfonyStyle($input, $output);
        $devices = $this->hcClient->devices();
        $this->io->writeln('-> devices loaded');
        $rooms = $this->hcClient->rooms();
        $this->io->writeln('-> rooms loaded');

        $lastFile = @file_get_contents($this->lastFile);
        $last = $lastFile ? (int) $lastFile : 1;

        $this->io->writeln(sprintf('-> loading refreshStates (poll) for last %d', $last));
        $poll = $this->hcClient->refreshStates($last);
        if (!isset($poll['changes'])) {
            throw new Exception('No changes key in refreshStates response');
        }
        $this->io->writeln('-> refreshStates (poll) loaded');

        $timestamp = $poll['timestamp'];
        if (isset($poll['last'])) {
            file_put_contents($this->lastFile, $poll['last']);
        }

        foreach ($poll['changes'] as $change) {
            if (!isset($change['id']) || !isset($change['value'])) {
                continue;
            }
            $id = $change['id'];
            $value = $this->getValue($change);
            $device = $devices[$id];
            $room = $this->getRoomFromDevice($device, $rooms);
            $this->io->writeln(sprintf('-> saving %s', $device['name']));
            $this->saveToInflux($id, $value, $device, $room, $timestamp);
        }

        return 0;
    }

    private function getValue(array $change)
    {
        $value = $change['value'];
        if ($value === "true") {
            return 1;
        }
        if ($value === "false") {
            return 0;
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

    private function saveToInflux($id, $value, $device, $room, $timestamp)
    {
        $points = [
            new Point(
                'devices',
                (float) $value,
                [
                    'sensor_id' => $id,
                    'sensor_name' => $device['name'],
                    'sensor_type' => isset($device['type']) ? $device['type'] : '',
                    'room_name' => $room && isset($room['name']) ? $room['name'] : 'None',
                    'room_id' => $room && isset($room['id']) ? $room['id'] : 'None',
                ],
                [],
                $timestamp * 1000000000)
        ];
        $this->influxDb->writePoints($points);
    }
}
