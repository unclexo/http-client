<?php

use Amp\Artax\HttpException;
use Amp\Artax\HttpSocketPool;
use Amp\Artax\Request;
use Amp\ByteStream\StreamException;
use Amp\Socket\StaticSocketPool;

require __DIR__ . '/../vendor/autoload.php';

try {
    // Unix sockets require a socket pool that changes all URLs to a fixed one.
    $socketPool = new StaticSocketPool("unix:///var/run/docker.sock");
    $client = new Amp\Artax\DefaultClient(null, new HttpSocketPool($socketPool));

    // Artax currently requires a host, so just use a dummy one.
    $request = Request::fromString('/info');
    $response = $client->request($request);

    printf(
        "HTTP/%s %d %s\n\n",
        $response->getProtocolVersion(),
        $response->getStatus(),
        $response->getReason()
    );

    print $response->getBody()->buffer() . "\n";
} catch (HttpException | StreamException $error) {
    print $error;
}
