<?php

use Amp\Artax\Request;

require __DIR__ . '/../vendor/autoload.php';

try {
    // Instantiate the HTTP client
    $client = new Amp\Artax\DefaultClient;

    // Here we create a custom request object instead of simply passing an URL to request().
    // We set the method to POST now and add a request body.
    $request = Request::fromString('https://httpbin.org/post', 'POST')
        ->withBody('woot \o/');

    $response = $client->request($request);

    // Output the results
    printf(
        "HTTP/%s %d %s\n",
        $response->getProtocolVersion(),
        $response->getStatus(),
        $response->getReason()
    );

    foreach ($response->getHeaders() as $field => $values) {
        foreach ($values as $value) {
            print "$field: $value" . PHP_EOL;
        }
    }

    print PHP_EOL;

    // The response body is an instance of Message, which allows buffering or streaming by the consumers choice.
    print $response->getBody()->buffer() . PHP_EOL;
} catch (HttpException | StreamException $error) {
    print $error;
}
