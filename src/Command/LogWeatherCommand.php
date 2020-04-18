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

class LogWeatherCommand extends Command
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
        $this->setName('log:weather');
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
        $this->io->title('Weather import from HC3 to InfluxDB');

        $this->io->section('Import');
        $this->io->write(' * loading weather data (API) ... ');
        $weather = $this->hcClient->weather();
        if (!$weather) {
            $this->io->writeln('<error>error</error>');
            return 1;
        }
        $this->io->writeln('<info>loaded</info>');

        $influxPoint = $this->createInfluxPoint($weather, time());
        $this->influxDb->writePoints([$influxPoint], Database::PRECISION_SECONDS);

        $this->io->section('Result');
        $this->io->writeln(sprintf("Duration: <info>%.2fs</info>", microtime(true) - $startTime));
        $this->io->writeln("Data inserted");
        $this->io->newLine();

        return 0;
    }

    private function createInfluxPoint(array $weather, $timestamp)
    {
        return new Point(
            'weather',
            null,
            [
                'temperatureUnit' => $weather['TemperatureUnit'],
                'windUnit' => $weather['WindUnit'],
                'weatherCondition' => $weather['WeatherCondition'],
                'conditionCode' => $weather['ConditionCode']
            ],
            [
                'temperature' => (float) $weather['Temperature'],
                'humidity' => (float) $weather['Humidity'],
                'wind' => (float) $weather['Wind']
            ],
            (int) $timestamp
        );
    }
}
