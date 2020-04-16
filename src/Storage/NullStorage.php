<?php

namespace F3ILog\Storage;

class NullStorage implements Storage
{
    public function store($key, $value)
    {
        return true;
    }

    public function load($key)
    {
        return null;
    }
}
