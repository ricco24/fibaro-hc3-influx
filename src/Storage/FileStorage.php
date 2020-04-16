<?php

namespace F3ILog\Storage;

class FileStorage implements Storage
{
    private $directory;

    public function __construct($directory)
    {
        $this->directory = $directory;
    }

    public function store($key, $value)
    {
        return (bool) file_put_contents($this->getFilePath($key), $value);
    }

    public function load($key)
    {
        $data = @file_get_contents($this->getFilePath($key));
        return !$data ? null : $data;
    }

    private function getFilePath($key)
    {
        return "{$this->directory}/$key";
    }
}
