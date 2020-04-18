<?php

namespace F3ILog\HCClient;

use GuzzleHttp\Client;

class HCClient
{
    private $baseUrl;

    private $username;

    private $password;

    private $verifySSL;

    private $guzzle;

    public function __construct($baseUrl, $username, $password, $verifySSL = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->verifySSL = $verifySSL;
        $this->guzzle = new Client();
    }

    private function call($url)
    {
        $response = $this->guzzle->get($url, [
            'auth' => [$this->username, $this->password],
            'verify' => $this->verifySSL,
            'http_errors' => false
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = json_decode($response->getBody(), true);
        if (!$data) {
            return null;
        }

        return $data;
    }


    public function devices()
    {
        $result = [];
        $data = $this->call(sprintf('%s/api/devices', $this->baseUrl));
        foreach ($data as $device) {
            $result[$device['id']] = $device;
        }
        return $result;
    }

    public function rooms()
    {
        $result = [];
        $data = $this->call(sprintf('%s/api/rooms', $this->baseUrl));
        foreach ($data as $room) {
            $result[$room['id']] = $room;
        }
        return $result;
    }

    public function sections()
    {
        $result = [];
        $data = $this->call(sprintf('%s/api/sections', $this->baseUrl));
        foreach ($data as $section) {
            $result[$section['id']] = $section;
        }
        return $result;
    }

    public function refreshStates($last)
    {
        return $this->call(sprintf('%s/api/refreshStates?last=%s', $this->baseUrl, $last));
    }

    public function panelsEvent($type, $last = null, $startFrom = null, $from = null, $to = null)
    {
        $query = [
            'type' => $type
        ];
        if ($last !== null) {
            $query['last'] = $last;
        }
        if ($startFrom !== null) {
            $query['startFrom'] = $startFrom;
        }
        if ($from !== null) {
            $query['from'] = $from;
        }
        if ($to !== null) {
            $query['to'] = $to;
        }

        $events = $this->call(sprintf('%s/api/panels/event', $this->baseUrl) . '?' . http_build_query($query));
        return $events === null ? [] : $events;
    }

    public function consumptionEnergy($timestampFrom, $timestampTo, $dataSet, $type, $unit, $id)
    {
        return $this->call(sprintf('%s/api/energy/', $this->baseUrl) . "$timestampFrom/$timestampTo/$dataSet/$type/$unit/$id");
    }

    public function consumptionEnergySummaryGraph($timestampFrom, $timestampTo, $type, $unit, $id)
    {
        $logs = $this->consumptionEnergy($timestampFrom, $timestampTo, 'summary-graph', $type, $unit, $id);
        return $logs === null ? [] : $logs;
    }

    public function consumptionEnergyCompare($timestampFrom, $timestampTo, $type, $unit, $id)
    {
        $result = $this->consumptionEnergy($timestampFrom, $timestampTo, 'compare', $type, $unit, $id);
        return $result === null ? null : $result[0];
    }

    public function weather()
    {
        return $this->call(sprintf('%s/api/weather', $this->baseUrl));
    }
}
