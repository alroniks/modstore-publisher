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
        'email' => 'ivan@klimchuk.com',
        'password' => 'Alro1788!1$$$',
        'action' => 'office/login'
    ]
];

function get_meta_tags_from_string($html) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    echo strlen($html);
    $doc->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//head/meta[@name]');
    $meta = [];

    foreach($nodes as $node) {
        $meta[$node->getAttribute('name')] = $node->getAttribute('content');
    }

    return $meta;
}

try {
    $response = $client->request('POST', 'assets/components/extras/action.php', $opts);

    if ($response->getStatusCode() === 200) {

        $data = json_decode($response->getBody(), true);

        $page = $client->request('GET', 'office/packages/mspoplati');

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
                    'contents' => 542
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
                    'contents' => Psr7\Utils::tryFopen(__DIR__ . '/mspoplati-0.4.0-dev.transport.zip', 'r')
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
//    print_r($ex);
}


