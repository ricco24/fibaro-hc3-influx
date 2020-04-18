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

class LogRefreshStatesCommand extends Command
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
            $device = $devices[$id];
            $room = $this->getRoomFromDevice($device, $rooms);
            $this->io->writeln(sprintf('-> saving <info>%s</info>', $device['name']));
            $this->saveToInflux($id, $change, $device, $room, $timestamp);
        }

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

    private function saveToInflux($id, $change, $device, $room, $timestamp)
    {
        $fields = [];
        foreach ($change as $k => $v) {
            if (in_array($k, ['id', 'log', 'logTemp', 'lastBreached'])) {
                continue;
            }
            $fields[$k] = $this->getFieldValue($v);
        }

        $points = [
            new Point(
                $device['type'],
                null,
                [
                    'sensor_id' => $id,
                    'sensor_name' => $device['name'],
                    'room_name' => $room && isset($room['name']) ? $room['name'] : 'None',
                    'room_id' => $room && isset($room['id']) ? $room['id'] : 'None',
                ],
                $fields,
                (int) $timestamp)
        ];
        $this->influxDb->writePoints($points, Database::PRECISION_SECONDS);
    }
}
