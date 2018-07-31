<?php

namespace Amp\Artax\Test;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Artax\FileBody;
use Amp\Artax\FormBody;
use Amp\Artax\HttpException;
use Amp\Artax\Request;
use Amp\Artax\RequestBody;
use Amp\Artax\SocketException;
use Amp\Artax\TooManyRedirectsException;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation\CancelledException;
use Amp\Cancellation\TokenSource;
use Amp\Socket;
use Concurrent\Task;
use PHPUnit\Framework\TestCase;

class ClientHttpBinIntegrationTest extends TestCase
{
    public function testHttp10Response(): void
    {
        $client = new DefaultClient;
        $server = Socket\listen("tcp://127.0.0.1:0/");

        Task::async(function () use ($server) {
            $client = $server->accept();
            $client->end("HTTP/1.0 200 OK\r\n\r\n");
        });

        $uri = "http://" . $server->getAddress();

        $response = $client->request(Request::fromString($uri)->withProtocolVersions(["1.0"]));

        $this->assertSame("", $response->getBody()->buffer());
    }

    public function testCloseAfterConnect(): void
    {
        $client = new DefaultClient;
        $server = Socket\listen("tcp://127.0.0.1:0");

        Task::async(function () use ($server) {
            while ($client = $server->accept()) {
                $client->close();
            }
        });

        try {
            $uri = "http://" . $server->getAddress() . "/";

            $this->expectException(SocketException::class);
            $this->expectExceptionMessage("Socket disconnected prior to response completion (Parser state: 0)");

            $client->request(Request::fromString($uri)->withProtocolVersions(["1.0"]));
        } finally {
            $server->close();
        }
    }

    public function testIncompleteHttpResponseWithContentLength(): void
    {
        $client = new DefaultClient;
        $server = Socket\listen("tcp://127.0.0.1:0");

        Task::async(function () use ($server) {
            while ($client = $server->accept()) {
                $client->end("HTTP/1.0 200 OK\r\nContent-Length: 2\r\n\r\n.");
            }
        });

        try {
            $uri = "http://" . $server->getAddress() . "/";

            $this->expectException(StreamException::class);
            $this->expectExceptionMessage("Socket disconnected prior to response completion (Parser state: 1)");

            $response = $client->request(Request::fromString($uri)->withProtocolVersions(["1.0"]));
            $response->getBody()->buffer();
        } finally {
            $server->close();
        }
    }

    public function testIncompleteHttpResponseWithChunkedEncoding(): void
    {
        $client = new DefaultClient;
        $server = Socket\listen("tcp://127.0.0.1:0");

        Task::async(function () use ($server) {
            while ($client = $server->accept()) {
                $client->end("HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n0\r"); // missing \n
            }
        });

        try {
            $uri = "http://" . $server->getAddress() . "/";

            $this->expectException(StreamException::class);
            $this->expectExceptionMessage("Socket disconnected prior to response completion (Parser state: 3)");

            $response = $client->request(Request::fromString($uri)->withProtocolVersions(["1.0"]));;
            $response->getBody()->buffer();
        } finally {
            $server->close();
        }
    }

    public function testIncompleteHttpResponseWithoutChunkedEncodingAndWithoutContentLength(): void
    {
        $client = new DefaultClient;
        $server = Socket\listen("tcp://127.0.0.1:0");

        Task::async(function () use ($server) {
            while ($client = $server->accept()) {
                $client->end("HTTP/1.1 200 OK\r\n\r\n00000000000");
            }
        });

        try {
            $uri = "http://" . $server->getAddress() . "/";
            $response = $client->request(Request::fromString($uri)->withProtocolVersions(["1.0"]));
            $this->assertSame("00000000000", $response->getBody()->buffer());
        } finally {
            $server->close();
        }
    }

    public function testDefaultUserAgentSent(): void
    {
        $client = new DefaultClient;

        $response = $client->request(Request::fromString('http://httpbin.org/user-agent'));
        $body = $response->getBody()->buffer();
        $this->assertNotEquals("", $body);

        $result = json_decode($body);
        $this->assertSame(DefaultClient::DEFAULT_USER_AGENT, $result->{'user-agent'});
    }

    public function testCustomUserAgentSentIfAssigned(): void
    {
        $customUserAgent = 'test-user-agent';
        $uri = 'http://httpbin.org/user-agent';
        $client = new DefaultClient;

        $request = Request::fromString($uri)->withHeader('User-Agent', $customUserAgent);
        $response = $client->request($request);

        $body = $response->getBody();
        $result = json_decode($body->buffer());

        $this->assertSame($customUserAgent, $result->{'user-agent'});
    }

    public function testCustomUserAgentSentIfAssignedViaDefaultHeaders(): void
    {
        $customUserAgent = 'test-user-agent';
        $client = new DefaultClient;
        $client->setOption(Client::OP_DEFAULT_HEADERS, [
            "user-agent" => $customUserAgent,
        ]);

        $response = $client->request(Request::fromString('http://httpbin.org/user-agent'));


        $body = $response->getBody()->buffer();
        $result = json_decode($body);

        $this->assertSame($customUserAgent, $result->{'user-agent'});
    }

    public function testPostStringBody(): void
    {
        $client = new DefaultClient;

        $body = 'zanzibar';
        $request = Request::fromString('http://httpbin.org/post')->withMethod('POST')->withBody($body);
        $response = $client->request($request);

        $result = json_decode($response->getBody()->buffer());

        $this->assertEquals($body, $result->data);
    }

    public function testPutStringBody(): void
    {
        $uri = 'http://httpbin.org/put';
        $client = new DefaultClient;

        $body = 'zanzibar';
        $request = Request::fromString($uri, "PUT")->withBody($body);
        $response = $client->request($request);

        $result = json_decode($response->getBody()->buffer());

        $this->assertEquals($body, $result->data);
    }

    /**
     * @dataProvider provideStatusCodes
     */
    public function testStatusCodeResponses($statusCode): void
    {
        $client = new DefaultClient;

        $response = $client->request(Request::fromString("http://httpbin.org/status/{$statusCode}"));

        $this->assertEquals($statusCode, $response->getStatus());
    }

    public function provideStatusCodes(): array
    {
        return [
            [200],
            [400],
            [404],
            [500],
        ];
    }

    public function testReason(): void
    {
        $client = new DefaultClient;

        $response = $client->request(Request::fromString("http://httpbin.org/status/418"));

        $expectedReason = "I'M A TEAPOT";
        $actualReason = $response->getReason();

        $this->assertSame($expectedReason, $actualReason);
    }

    public function testRedirect(): void
    {
        $statusCode = 299;
        $redirectTo = "/status/{$statusCode}";
        $uri = "http://httpbin.org/redirect-to?url=" . \rawurlencode($redirectTo);

        $client = new DefaultClient;

        $response = $client->request(Request::fromString($uri));

        $this->assertEquals(302, $response->getStatus());

        $originalUri = $response->getOriginalRequest()->getUri();

        $this->assertSame($uri, (string) $originalUri);
    }

    public function testClientAddsZeroContentLengthHeaderForEmptyBodiesOnPost(): void
    {
        $uri = 'http://httpbin.org/post';
        $client = new DefaultClient;

        $response = $client->request(Request::fromString($uri, 'POST'));

        $body = $response->getBody()->buffer();
        $result = json_decode($body);

        $this->assertEquals('0', $result->headers->{'Content-Length'});
    }

    public function testFormEncodedBodyRequest(): void
    {
        $client = new DefaultClient;

        $body = new FormBody;
        $field1 = 'test val';
        $field2 = 'val2';
        $body->addField('field1', $field1);
        $body->addField('field2', $field2);

        $request = Request::fromString('http://httpbin.org/post', "POST")->withBody($body);
        $response = $client->request($request);

        $result = json_decode($response->getBody()->buffer(), true);

        $this->assertEquals($field1, $result['form']['field1']);
        $this->assertEquals($field2, $result['form']['field2']);
        $this->assertEquals('application/x-www-form-urlencoded', $result['headers']['Content-Type']);
    }

    public function testFileBodyRequest(): void
    {
        $uri = 'http://httpbin.org/post';
        $client = new DefaultClient;

        $bodyPath = __DIR__ . '/fixture/answer.txt';
        $body = new FileBody($bodyPath);

        $request = Request::fromString($uri, "POST")->withBody($body);
        $response = $client->request($request);

        $result = json_decode($response->getBody()->buffer(), true);

        $this->assertStringEqualsFile($bodyPath, $result['data']);
    }

    public function testMultipartBodyRequest(): void
    {
        $client = new DefaultClient;

        $field1 = 'test val';
        $file1 = __DIR__ . '/fixture/lorem.txt';
        $file2 = __DIR__ . '/fixture/answer.txt';

        $boundary = 'AaB03x';

        $body = new FormBody($boundary);
        $body->addFields(['field1' => $field1]);
        $body->addFiles(['file1' => $file1, 'file2' => $file2]);

        $request = Request::fromString('http://httpbin.org/post', "POST")->withBody($body);
        $response = $client->request($request);

        $this->assertEquals(200, $response->getStatus());

        $result = json_decode($response->getBody()->buffer(), true);

        $this->assertEquals($field1, $result['form']['field1']);
        $this->assertStringEqualsFile($file1, $result['files']['file1']);
        $this->assertStringEqualsFile($file2, $result['files']['file2']);
        $this->assertEquals('multipart/form-data; boundary=' . $boundary, $result['headers']['Content-Type']);
    }

    /**
     * @requires extension zlib
     */
    public function testGzipResponse(): void
    {
        $client = new DefaultClient;

        $response = $client->request(Request::fromString('http://httpbin.org/gzip'));

        $this->assertEquals(200, $response->getStatus());

        $result = json_decode($response->getBody()->buffer(), true);

        $this->assertTrue($result['gzipped']);
    }

    /**
     * @requires extension zlib
     */
    public function testDeflateResponse(): void
    {
        $client = new DefaultClient;

        $response = $client->request(Request::fromString('http://httpbin.org/deflate'));

        $this->assertEquals(200, $response->getStatus());

        $result = json_decode($response->getBody()->buffer(), true);

        $this->assertTrue($result['deflated']);
    }

    public function testConnectionInfo(): void
    {
        $response = (new DefaultClient)->request(Request::fromString("https://httpbin.org/get"));
        $connectionInfo = $response->getMetaInfo()->getConnectionInfo();

        $this->assertContains(":", $connectionInfo->getLocalAddress());
        $this->assertContains(":", $connectionInfo->getRemoteAddress());
        $this->assertNotNull($connectionInfo->getTlsInfo());
        $this->assertSame("TLSv1.2", $connectionInfo->getTlsInfo()->getProtocol());
        $this->assertNotEmpty($connectionInfo->getTlsInfo()->getPeerCertificates());
        $this->assertContains("httpbin.org", $connectionInfo->getTlsInfo()->getPeerCertificates()[0]->getNames());

        foreach ($connectionInfo->getTlsInfo()->getPeerCertificates() as $certificate) {
            $this->assertGreaterThanOrEqual($certificate->getValidFrom(), time(), "Failed for " . $certificate->getSubject()->getCommonName());
            $this->assertLessThanOrEqual($certificate->getValidTo(), time(), "Failed for " . $certificate->getSubject()->getCommonName());
        }
    }

    public function testRequestCancellation(): void
    {
        $cancellationTokenSource = new TokenSource;
        $response = (new DefaultClient)->request(Request::fromString("http://httpbin.org/drip?code=200&duration=5&numbytes=130000"), [], $cancellationTokenSource->getToken());
        $cancellationTokenSource->cancel();
        $this->expectException(StreamException::class);
        $response->getBody()->buffer();
    }

    public function testContentLengthBodyMismatchWithTooManyBytesSimple(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Body contained more bytes than specified in Content-Length, aborting request");

        $request = Request::fromString("http://httpbin.org/post", "POST")
            ->withBody(new class implements RequestBody
            {
                public function getHeaders(): array
                {
                    return [];
                }

                public function createBodyStream(): InputStream
                {
                    return new InMemoryStream("foo");
                }

                public function getBodyLength(): int
                {
                    return 1;
                }
            });

        (new DefaultClient)->request($request);
    }

    public function testContentLengthBodyMismatchWithTooManyBytesWith3ByteChunksAndLength2(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Body contained more bytes than specified in Content-Length, aborting request");

        $request = Request::fromString("http://httpbin.org/post", "POST")
            ->withBody(new class implements RequestBody
            {
                public function getHeaders(): array
                {
                    return [];
                }

                public function createBodyStream(): InputStream
                {
                    return new IteratorStream(new \ArrayIterator(["a", "b", "c"]));
                }

                public function getBodyLength(): int
                {
                    return 2;
                }
            });

        (new DefaultClient)->request($request);
    }

    public function testContentLengthBodyMismatchWithTooFewBytes(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Body contained fewer bytes than specified in Content-Length, aborting request");

        $request = Request::fromString("http://httpbin.org/post", "POST")
            ->withBody(new class implements RequestBody
            {
                public function getHeaders(): array
                {
                    return [];
                }

                public function createBodyStream(): InputStream
                {
                    return new InMemoryStream("foo");
                }

                public function getBodyLength(): int
                {
                    return 42;
                }
            });

        (new DefaultClient)->request($request);
    }
}
