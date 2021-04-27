<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;

require_once __DIR__ . '/vendor/autoload.php';

$client = new Client([
    'base_uri' => 'https://modstore.pro/',
    'cookies' => true
]);

$opts = [
    'form_params' => [
        'email' => '***',
        'password' => '***',
        'action' => 'office/login'
    ]
];

try {
    $response = $client->request('POST', 'assets/components/extras/action.php', $opts);

    if ($response->getStatusCode() === 200) {

//        $data = json_decode($response->getBody(), true);

        $page = $client->request('GET', 'office/packages/***');

        $str = $page->getBody()->getContents();

        $tmp = tmpfile();
        fwrite($tmp, $str);
        $tags = get_meta_tags(stream_get_meta_data($tmp)['uri']);

//        print_r($tags);

        $token = $tags['csrf-token'];

        echo $token;

        $uploadResponse = $client->request('POST', 'assets/components/extras/action.php', [
            'multipart' => [
                [
                    'name'     => 'action',
                    'contents' => 'office/versions/create'
                ],
                [
                    'name' => 'package_id',
                    'contents' => 9
                ],
                [
                    'name' => 'changelog',
                    'contents' => 'changelog'
                ],
                [
                    'name' => 'changelog_en',
                    'contents' => 'changelog_en'
                ],
                [
                    'name' => 'minimum_supports',
                    'contents' => '2.8'
                ],
                [
                    'name' => 'supports',
                    'contents' => '2.8'
                ],
                [
                    'name' => 'minimum_php',
                    'contents' => '7.4'
                ],
                [
                    'name' => 'deprecate_other',
                    'contents' => 1
                ],
                [
                    'name'     => 'package',
                    'contents' => Psr7\Utils::tryFopen(__DIR__ . '/*.transport.zip', 'r')
                ]
            ],
            'headers' => [
                'X-CSRF-Token' => $token
            ],
            'debug' => true
        ]);

        echo $uploadResponse->getBody();

    } else {
        echo "Authorization error", $response->getStatusCode();
    }
} catch (Exception $ex) {
    echo $ex->getMessage(), PHP_EOL;
    print_r(json_decode($ex->getResponse()->getBody()->getContents(), true));
}


