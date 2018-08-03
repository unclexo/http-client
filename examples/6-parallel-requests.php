<?php

use Amp\Artax\HttpException;
use Amp\Artax\Request;
use Amp\ByteStream\StreamException;
use Concurrent\Task;
use function Concurrent\all;

require __DIR__ . '/../vendor/autoload.php';

$uris = [
    "https://google.com/",
    "https://github.com/",
    "https://stackoverflow.com/",
];

// Instantiate the HTTP client
$client = new Amp\Artax\DefaultClient;

try {
    $awaitables = [];

    foreach ($uris as $uri) {
        $awaitables[$uri] = Task::async(function (string $uri) use ($client) {
            $response = $client->request(Request::fromString($uri));

            return $response->getBody()->buffer();
        }, $uri);
    }

    $bodies = Task::await(all($awaitables));

    foreach ($bodies as $uri => $body) {
        print $uri . " - " . \strlen($body) . " bytes" . PHP_EOL;
    }
} catch (HttpException | StreamException $error) {
    print $error;
}
