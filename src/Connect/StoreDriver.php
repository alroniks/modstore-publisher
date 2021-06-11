<?php

use GuzzleHttp\Client;

class StoreDriver implements DriverInterface
{
    private const CLIENT_BASE_URI = 'https://modstore.pro/';
    private const CLIENT_ENTRY_POINT = 'assets/components/extras/action.php';

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
           'base_uri' => self::CLIENT_BASE_URI,
           'cookies' => true
       ]);
    }

    public function upload()
    {
        // TODO: Implement upload() method.
    }

    public function login()
    {
        // TODO: Implement login() method.
    }

    public function fetch()
    {
        // TODO: Implement fetch() method.
    }
}
