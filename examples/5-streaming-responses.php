<?php

use Amp\Artax\Client;
use Amp\Artax\Request;
use Amp\File\StatCache;

require __DIR__ . '/../vendor/autoload.php';

try {
    $start = microtime(1);

    // Instantiate the HTTP client
    $client = new Amp\Artax\DefaultClient;

    $response = $client->request(Request::fromString('http://speed.hetzner.de/100MB.bin'), [
        Client::OP_MAX_BODY_BYTES => 120 * 1024 * 1024,
    ]);

    // Output the results
    printf(
        "HTTP/%s %d %s\n\n",
        $response->getProtocolVersion(),
        $response->getStatus(),
        $response->getReason()
    );

    foreach ($response->getHeaders() as $field => $values) {
        foreach ($values as $value) {
            print "$field: $value\n";
        }
    }

    print "\n";

    $path = tempnam(sys_get_temp_dir(), "artax-streaming-");
    $file = Amp\File\open($path, "w");

    // The response body is an instance of Message, which allows buffering or streaming by the consumers choice.
    // Pipe streams the body into the file, which is an instance of OutputStream.
    Amp\ByteStream\pipe($response->getBody(), $file);
    $file->close();

    printf(
        "Done in %.2f with peak memory usage of %.2fMB.\n",
        microtime(1) - $start,
        (float) memory_get_peak_usage(true) / 1024 / 1024
    );

    // We need to clear the stat cache, as we have just written to the file
    StatCache::clear($path);
    $size = Amp\File\size($path);

    printf("%s has a size of %.2fMB\n", $path, (float) $size / 1024 / 1024);
} catch (Amp\Artax\HttpException $error) {
    print $error;
}
