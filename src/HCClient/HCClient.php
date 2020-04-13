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

    public function refreshStates($last)
    {
        return $this->call(sprintf('%s/api/refreshStates?last=%s', $this->baseUrl, $last));
    }
}
