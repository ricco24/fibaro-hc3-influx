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

class LogEventsCommand extends Command
{
    private $influxDb;

    private $hcClient;

    private $lastFile;

    private $guzzle;

    /** @var SymfonyStyle */
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
        $this->setName('log:events');
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

        $lastEventId = @file_get_contents($this->lastFile);
        if (!$lastEventId) {
            $this->loadHistoricalData($devices, $rooms);
            return 0;
        }

        $this->loadData($lastEventId, $devices, $rooms);
        return 0;

    }

    private function loadHistoricalData(array $devices, array $rooms)
    {
        $lastEvent = $this->hcClient->panelsEvent('id', 1);

        // No events logged in system
        if (count($lastEvent) === 0) {
            return;
        }

        $lastEventId = $lastEvent[0]['id'];
        $startFrom = 1;
        $limit = 500;
        $currentEventId = null;
        $break = false;
        while (!$break) {
            $this->io->write("-> loading {$limit} events from {$startFrom} ... ");
            $events = $this->hcClient->panelsEvent('id', $limit, $startFrom);
            $this->io->writeln(sprintf('loaded %d', count($events)));
            $startFrom += $limit;
            if (count($events) === 0) {
                continue;
            }

            // We need from oldest to newest
            $events = array_reverse($events);

            $points = [];
            foreach ($events as $event) {
                $currentEventId = $event['id'];
                if ($event['id'] === $lastEventId) {
                    $break = true;
                }
                $influxPoint = $this->createInfluxPoint($event, $devices, $rooms);
                if ($influxPoint !== null) {
                    $points[] = $influxPoint;
                }
            }

            if (count($points)) {
                $this->influxDb->writePoints($points, Database::PRECISION_SECONDS);
            }
        }

        if ($currentEventId !== null) {
            file_put_contents($this->lastFile, $currentEventId);
        }
    }

    private function loadData($lastEventId, array $devices, array $rooms)
    {
        $currentEventId = null;
        $limit = 500;
        $startFrom = $lastEventId + 1;
        $this->io->write("-> loading {$limit} events from {$startFrom} ... ");
        $events = $this->hcClient->panelsEvent('id', $limit, $startFrom);
        $this->io->writeln(sprintf('loaded %d', count($events)));

        if (count($events) === 0) {
            return;
        }

        // We need from oldest to newest
        $events = array_reverse($events);

        $points = [];
        foreach ($events as $event) {
            $currentEventId = $event['id'];
            $influxPoint = $this->createInfluxPoint($event, $devices, $rooms);
            if ($influxPoint !== null) {
                $points[] = $influxPoint;
            }
        }

        if (count($points)) {
            $this->influxDb->writePoints($points, Database::PRECISION_SECONDS);
        }
        if ($currentEventId !== null) {
            file_put_contents($this->lastFile, $currentEventId);
        }
    }

    private function createInfluxPoint(array $event, array $devices, array $rooms)
    {
        if ($event['type'] == 'DEVICE_EVENT' && $event['event']['type'] == 'DevicePropertyUpdatedEvent') {
            $property = $event['event']['data']['property'];
            $value = $event['event']['data']['newValue'];
        } elseif ($event['type'] == 'DEVICE_PROPERTY_CHANGED') {
            $property = $event['propertyName'];
            $value = $event['newValue'];
        } else {
            var_dump($event);
            return null;
        }
        return null;

        $device = $devices[$event['deviceID']];
        $room = $this->getRoomFromDevice($device, $rooms);

        //$this->io->writeln("-> preparing data for {$device['name']} | {$property} - {$value}");
        return new Point(
            $event['deviceType'],
            null,
            [
                'device_id' => $event['deviceID'],
                'device_name' => $device['name'],
                'room_name' => $room && isset($room['name']) ? $room['name'] : 'None',
                'room_id' => $room && isset($room['id']) ? $room['id'] : 'None',
            ],
            [
                $property => $this->fixValue($value)
            ],
            (int) $event['timestamp']
        );
    }

    private function fixValue($value)
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
}
