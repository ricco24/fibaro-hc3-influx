<?php

namespace F3ILog\Command;

use F3ILog\HCClient\HCClient;
use GuzzleHttp\Client;
use InfluxDB\Database;
use InfluxDB\Point;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        $this->setName('log:events')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many events from API will be downloaded in one API call', 25)
            ->addOption('max_calls', null, InputOption::VALUE_REQUIRED, 'How many maximum times will be events API called (or until last event from HC3 reached)', 1);
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

        // Input data
        $limit = (int) $input->getOption('limit');
        $maxCalls = (int) $input->getOption('max_calls');

        $this->io->section('Configuration');
        $this->io->writeln("Limit: <comment>$limit</comment>");
        $this->io->writeln("Max calls: <comment>$maxCalls</comment>");

        $this->io->section('Data preparation');

        $this->io->write(' * loading devices (API) ... ');
        $devices = $this->hcClient->devices();
        if ($devices === null) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln(sprintf('<comment>%d</comment> devices loaded', count($devices)));

        $this->io->write(' * loading rooms (API) ... ');
        $rooms = $this->hcClient->rooms();
        if ($rooms === null) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln(sprintf('<comment>%d</comment> rooms loaded', count($rooms)));

        $this->io->write(' * loading sections (API) ... ');
        $sections = $this->hcClient->sections();
        if ($sections === null) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln(sprintf('<comment>%d</comment> sections loaded', count($sections)));

        $this->io->write(' * loading last HC3 event ID ... ');
        $lastEvent = $this->hcClient->panelsEvent('id', 1);
        $lastEventId = count($lastEvent) ? $lastEvent[0]['id'] : null;
        $this->io->writeln($lastEventId ? "<comment>$lastEventId</comment>" : "No events logged in HC3");

        // No events logged in system
        if ($lastEventId === null) {
            $this->io->warning("No events logged in HC3, exit.");
            return 0;
        }

        $this->io->write(' * loading last saved event ID ... ');
        $lastSavedEventId = @file_get_contents($this->lastFile);
        $this->io->writeln($lastSavedEventId
            ? sprintf("<comment>$lastSavedEventId</comment> (missing events: <comment>%d</comment>)", $lastEventId - $lastSavedEventId)
            : "No saved events"
        );

        $this->io->section('Import');

        $startFrom = $lastSavedEventId ? $lastSavedEventId + $limit : $limit;
        $currentEventId = null;
        $break = false;
        $totalLoaded = 0;
        $totalInserted = 0;
        while ($maxCalls > 0 && !$break) {
            $maxCalls--;
            $this->io->write(" * loading $limit events startFrom <comment>$startFrom</comment> ... ");
            $events = $this->hcClient->panelsEvent('id', $limit, $startFrom);
            $startFrom += $limit;
            $totalLoaded += count($events);
            $this->io->write(sprintf("loaded: <comment>%d</comment>", count($events)));
            if (count($events) === 0) {
                $this->io->newLine();
                continue;
            }

            // We need from oldest to newest
            $events = array_reverse($events);
            $points = [];
            foreach ($events as $event) {
                $currentEventId = $event['id'];

                // Reached last event from HC3 from time when script starts
                if ($currentEventId === $lastEventId) {
                    $break = true;
                }

                // Skip already processed events
                if ($lastSavedEventId !== false && $currentEventId <= $lastSavedEventId) {
                    continue;
                }

                $influxPoint = $this->createInfluxPoint($event, $devices, $rooms, $sections);
                if ($influxPoint !== null) {
                    $points[] = $influxPoint;
                }
            }

            if (count($points)) {
                $this->influxDb->writePoints($points, Database::PRECISION_SECONDS);
            }

            $totalInserted += count($points);
            $this->io->writeln(sprintf(" / inserted: <comment>%d</comment>", count($points)));

            if ($currentEventId !== null) {
                file_put_contents($this->lastFile, $currentEventId);
            }
        }

        $this->io->section('Result');
        $this->io->writeln(sprintf("Duration: <comment>%.2fs</comment>", microtime(true) - $startTime));
        $this->io->writeln(sprintf("Total loaded: <comment>%d</comment>", $totalLoaded));
        $this->io->writeln(sprintf("Total skipped: <comment>%d</comment>", $totalLoaded - $totalInserted));
        $this->io->writeln(sprintf("Total inserted: <comment>%d</comment>", $totalInserted));
        $this->io->newLine();

        return 0;
    }

    private function createInfluxPoint(array $event, array $devices, array $rooms, array $sections)
    {
        if ($event['type'] == 'DEVICE_EVENT' && $event['event']['type'] == 'DevicePropertyUpdatedEvent') {
            $property = $event['event']['data']['property'];
            $value = $event['event']['data']['newValue'];
        } elseif ($event['type'] == 'DEVICE_PROPERTY_CHANGED') {
            $property = $event['propertyName'];
            $value = $event['newValue'];
        } else {
            return null;
        }

        $device = $devices[$event['deviceID']];
        $room = $this->getRoomFromDevice($device, $rooms);
        $section = $this->getSectionFromRoom($room, $sections);

        return new Point(
            $event['deviceType'],
            null,
            [
                'device_id' => $event['deviceID'],
                'device_name' => $device['name'],
                'room_name' => $room && isset($room['name']) ? $room['name'] : 'None',
                'room_id' => $room && isset($room['id']) ? $room['id'] : 'None',
                'section_name' => $section && isset($section['name']) ? $section['name'] : 'None',
                'section_id' => $section && isset($section['id']) ? $section['id'] : 'None'
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
}
