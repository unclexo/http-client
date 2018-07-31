<?php

namespace Amp\Test\Artax;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Artax\HttpSocketPool;
use Amp\Artax\Request;
use Amp\Artax\TimeoutException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation\Token;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;
use Amp\Socket\ClientSocket;
use Concurrent\Deferred;
use Concurrent\Task;

class TimeoutTest extends TestCase
{
    /** @var DefaultClient */
    private $client;

    public function setUp()
    {
        $this->client = new DefaultClient;
    }

    public function testTimeoutDuringBody(): void
    {
        $server = Socket\listen("tcp://127.0.0.1:0");

        Task::async(function () use ($server) {
            while ($client = $server->accept()) {
                $client->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n.");

                Loop::delay(3000, function () use ($client) {
                    $client->close();
                });
            }
        });

        try {
            $uri = "http://" . $server->getAddress() . "/";

            $start = \microtime(true);
            $response = $this->client->request(Request::fromString($uri), [Client::OP_TRANSFER_TIMEOUT => 100]);

            $this->expectException(StreamException::class);
            $this->expectExceptionMessage("Unexpected exception during read()");
            $response->getBody()->buffer();
        } finally {
            $this->assertLessThan(0.6, \microtime(true) - $start);
            $server->close();
        }
    }

    public function testTimeoutDuringConnect(): void
    {
        Loop::repeat(1000, function () {
            // dummy watcher, because socket pool doesn't do anything
        });

        $this->client = new DefaultClient(new HttpSocketPool(new class implements Socket\SocketPool
        {
            public function checkout(string $uri, Token $token = null): ClientSocket
            {
                $deferred = new Deferred;

                if ($token) {
                    $token->subscribe(function ($error) use ($deferred) {
                        $deferred->fail($error);
                    });
                }

                Task::await($deferred->awaitable()); // never resolve
            }

            public function checkin(ClientSocket $socket): void
            {
                // ignore
            }

            public function clear(ClientSocket $socket): void
            {
                // ignore
            }
        }));

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage("Connection to 'localhost:1337' timed out");

        $this->assertRunTimeLessThan(function () {
            $this->client->request(Request::fromString("http://localhost:1337/"), [Client::OP_TRANSFER_TIMEOUT => 100]);
        }, 600);
    }

    public function testTimeoutDuringTlsEnable(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)
            ->withDefaultCertificate(new Socket\Certificate(__DIR__ . "/tls/amphp.org.pem"));

        $server = Socket\listen("tcp://127.0.0.1:0", null, $tlsContext);

        Task::async(function () use ($server) {
            while ($client = $server->accept()) {
                Loop::delay(3000, function () use ($client) {
                    $client->close();
                });
            }
        });

        try {
            $uri = "http://" . $server->getAddress() . "/";

            $start = \microtime(true);

            $this->expectException(TimeoutException::class);
            $this->expectExceptionMessageRegExp("(Response for \"http://127.0.0.1:\d+/\" didn't finish within 100 ms, aborting)");

            $this->client->request(Request::fromString($uri), [Client::OP_TRANSFER_TIMEOUT => 100]);

        } finally {
            $this->assertLessThan(0.6, \microtime(true) - $start);
            $server->close();
        }
    }
}
