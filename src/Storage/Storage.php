<?php

namespace F3ILog\Storage;

interface Storage
{
    public function store($key, $value);

    public function load($key);
}
