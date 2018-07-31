<?php

namespace Amp\Artax;

use Amp\Artax\Internal\CombinedCancellationToken;
use Amp\Artax\Internal\Parser;
use Amp\Artax\Internal\RequestCycle;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\Message;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\ZlibInputStream;
use Amp\Cancellation\CancelledException;
use Amp\Cancellation\NullToken;
use Amp\Cancellation\TimeoutToken;
use Amp\Cancellation\Token;
use Amp\Emitter;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Rfc7230;
use Amp\Loop;
use Amp\Socket\ClientSocket;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectException;
use Concurrent\Awaitable;
use Concurrent\Deferred;
use Concurrent\Task;
use function Amp\delay;

/**
 * Standard client implementation.
 *
 * Use the `Client` interface for your type declarations so people can use composition to add layers like caching.
 *
 * @see Client
 */
final class DefaultClient implements Client
{
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; Artax)';

    private $socketPool;
    private $tlsContext;
    private $hasZlib;
    private $options = [
        self::OP_TRANSFER_TIMEOUT => 15000,
        self::OP_MAX_REDIRECTS => 5,
        self::OP_AUTO_REFERER => true,
        self::OP_DISCARD_BODY => false,
        self::OP_DEFAULT_HEADERS => [],
        self::OP_MAX_HEADER_BYTES => Parser::DEFAULT_MAX_HEADER_BYTES,
        self::OP_MAX_BODY_BYTES => Parser::DEFAULT_MAX_BODY_BYTES,
    ];

    public function __construct(
        HttpSocketPool $socketPool = null,
        ClientTlsContext $tlsContext = null
    ) {
        $this->tlsContext = $tlsContext ?? new ClientTlsContext;
        $this->socketPool = $socketPool ?? new HttpSocketPool;
        $this->hasZlib = \extension_loaded('zlib');
    }

    /** @inheritdoc */
    public function request(Request $request, array $options = [], Token $cancellation = null): Response
    {
        $cancellation = $cancellation ?? new NullToken;

        foreach ($options as $option => $value) {
            $this->validateOption($option, $value);
        }

        $options = $options ? array_merge($this->options, $options) : $this->options;

        foreach ($this->options[self::OP_DEFAULT_HEADERS] as $name => $header) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeaders([$name => $header]);
            }
        }

        /** @var array $headers */
        $headers = $request->getBody()->getHeaders();
        foreach ($headers as $name => $header) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeaders([$name => $header]);
            }
        }

        $request = $this->normalizeRequestHeaders($request);

        // Always normalize this as last item, because we need to strip sensitive headers
        $request = $this->normalizeTraceRequest($request);

        return $this->doRequest($request, $options, null, $cancellation);
    }

    /**
     * @param Request       $request
     * @param array         $options
     * @param Response|null $previousResponse
     * @param Token         $cancellation
     *
     * @return Response
     * @throws HttpException
     */
    private function doRequest(
        Request $request,
        array $options,
        ?Response $previousResponse = null,
        Token $cancellation
    ): Response {
        $deferred = new Deferred;

        $requestCycle = new RequestCycle;
        $requestCycle->request = $request;
        $requestCycle->options = $options;
        $requestCycle->previousResponse = $previousResponse;
        $requestCycle->deferred = $deferred;
        $requestCycle->bodyDeferred = new Deferred;
        $requestCycle->body = new Emitter;
        $requestCycle->cancellation = $cancellation;

        $protocolVersions = $request->getProtocolVersions();

        if (\in_array("1.1", $protocolVersions, true)) {
            $requestCycle->protocolVersion = "1.1";
        } elseif (\in_array("1.0", $protocolVersions, true)) {
            $requestCycle->protocolVersion = "1.0";
        } else {
            throw new HttpException(
                "None of the requested protocol versions are supported: " . \implode(", ", $protocolVersions)
            );
        }

        Task::async(function () use ($requestCycle) {
            try {
                $this->doWrite($requestCycle);
            } catch (\Throwable $e) {
                $this->fail($requestCycle, $e);
            }
        });

        /** @noinspection PhpUnhandledExceptionInspection */
        return Task::await($deferred->awaitable());
    }

    private function doRead(
        RequestCycle $requestCycle,
        ClientSocket $socket,
        ConnectionInfo $connectionInfo
    ): void {
        try {
            $backpressure = Deferred::value();
            $bodyCallback = $requestCycle->options[self::OP_DISCARD_BODY]
                ? null
                : static function ($data) use ($requestCycle, &$backpressure) {
                    $backpressure = Task::async([$requestCycle->body, 'emit'], $data);
                };

            $parser = new Parser($bodyCallback);

            $parser->enqueueResponseMethodMatch($requestCycle->request->getMethod());
            $parser->setAllOptions([
                Parser::OP_MAX_HEADER_BYTES => $requestCycle->options[self::OP_MAX_HEADER_BYTES],
                Parser::OP_MAX_BODY_BYTES => $requestCycle->options[self::OP_MAX_BODY_BYTES],
            ]);

            while (null !== $chunk = $socket->read()) {
                $requestCycle->cancellation->throwIfRequested();

                $parseResult = $parser->parse($chunk);

                if (!$parseResult) {
                    continue;
                }

                $parseResult["headers"] = \array_change_key_case($parseResult["headers"], \CASE_LOWER);

                $response = $this->finalizeResponse($requestCycle, $parseResult, $connectionInfo);
                $shouldCloseSocketAfterResponse = $this->shouldCloseSocketAfterResponse($response);
                $ignoreIncompleteBodyCheck = false;
                $responseHeaders = $response->getHeaders();

                if ($requestCycle->deferred) {
                    $deferred = $requestCycle->deferred;
                    $requestCycle->deferred = null;
                    $deferred->resolve($response);
                    $response = null; // clear references
                    $deferred = null; // there's also a reference in the deferred
                } else {
                    return;
                }

                // Required, otherwise responses without body hang
                if ($parseResult["headersOnly"]) {
                    // Directly parse again in case we already have the full body but aborted parsing
                    // to resolve promise with headers.
                    $chunk = null;

                    do {
                        try {
                            $parseResult = $parser->parse($chunk);
                        } catch (ParseException $e) {
                            $this->fail($requestCycle, $e);
                            throw $e;
                        }

                        if ($parseResult) {
                            break;
                        }

                        $this->withCancellation($backpressure, $requestCycle->cancellation);

                        if ($requestCycle->bodyTooLarge) {
                            throw new StreamException("Response body exceeded the specified size limit");
                        }
                    } while (null !== $chunk = $socket->read());

                    delay(0); // Required, because body chunk emitting is async

                    $parserState = $parser->getState();
                    if ($parserState !== Parser::AWAITING_HEADERS) {
                        // Ignore check if neither content-length nor chunked encoding are given.
                        $ignoreIncompleteBodyCheck = $parserState === Parser::BODY_IDENTITY_EOF &&
                            !isset($responseHeaders["content-length"]) &&
                            strcasecmp('identity', $responseHeaders['transfer-encoding'][0] ?? "");

                        if (!$ignoreIncompleteBodyCheck) {
                            throw new StreamException(sprintf(
                                'Socket disconnected prior to response completion (Parser state: %s)',
                                $parserState
                            ));
                        }
                    }
                }

                if ($shouldCloseSocketAfterResponse || $ignoreIncompleteBodyCheck) {
                    $this->socketPool->clear($socket);
                    $socket->close();
                } else {
                    $this->socketPool->checkin($socket);
                }

                $requestCycle->socket = null;

                // Complete body AFTER socket checkin, so the socket can be reused for a potential redirect
                $body = $requestCycle->body;
                $requestCycle->body = null;

                $bodyDeferred = $requestCycle->bodyDeferred;
                $requestCycle->bodyDeferred = null;

                $body->complete();
                $bodyDeferred->resolve();

                return;
            }
        } catch (\Throwable $e) {
            $this->fail($requestCycle, $e);

            return;
        }

        if ($socket->getResource() !== null) {
            $requestCycle->socket = null;
            $this->socketPool->clear($socket);
            $socket->close();
        }

        // Required, because if the write fails, the read() call immediately resolves.
        delay(0);

        if ($requestCycle->deferred === null) {
            return;
        }

        $parserState = $parser->getState();

        if ($parserState === Parser::AWAITING_HEADERS && $requestCycle->retryCount < 1) {
            $requestCycle->retryCount++;
            $this->doWrite($requestCycle);
        } else {
            $this->fail($requestCycle, new SocketException(sprintf(
                'Socket disconnected prior to response completion (Parser state: %s)',
                $parserState
            )));
        }
    }

    private function withCancellation(Awaitable $awaitable, Token $cancellationToken): void
    {
        $deferred = new Deferred;
        $newAwaitable = $deferred->awaitable();

        Deferred::transform($awaitable, function ($error, $value) use (&$deferred) {
            if ($deferred) {
                if ($error) {
                    $deferred->fail($error);
                    $deferred = null;
                } else {
                    $deferred->resolve($value);
                    $deferred = null;
                }
            }
        });

        $cancellationSubscription = $cancellationToken->subscribe(function ($e) use (&$deferred) {
            if ($deferred) {
                $deferred->fail($e);
                $deferred = null;
            }
        });

        try {
            /** @noinspection PhpUnhandledExceptionInspection */
            Task::await($newAwaitable);
        } finally {
            $cancellationToken->unsubscribe($cancellationSubscription);
        }
    }

    /**
     * @param RequestCycle $requestCycle
     *
     * @throws CancelledException
     * @throws HttpException
     * @throws StreamException
     */
    private function doWrite(RequestCycle $requestCycle): void
    {
        $timeout = $requestCycle->options[self::OP_TRANSFER_TIMEOUT];
        $timeoutToken = new NullToken;

        if ($timeout > 0) {
            $transferTimeoutWatcher = Loop::delay($timeout, function () use ($requestCycle, $timeout) {
                $this->fail($requestCycle, new TimeoutException(
                    sprintf('Response for "%s" didn\'t finish within %d ms, aborting', $requestCycle->request->getUri(), $timeout)
                ));
            });

            Deferred::transform($requestCycle->bodyDeferred->awaitable(), static function () use (
                $transferTimeoutWatcher
            ) {
                Loop::cancel($transferTimeoutWatcher);
            });

            $timeoutToken = new TimeoutToken($timeout);
        }

        $uri = $requestCycle->request->getUri();
        $authority = $uri->getHost() . ":" . $uri->getPort();
        $socketCheckoutUri = $uri->getScheme() . "://" . $authority;
        $connectTimeoutToken = new CombinedCancellationToken($requestCycle->cancellation, $timeoutToken);

        try {
            $socket = $this->socketPool->checkout($socketCheckoutUri, $connectTimeoutToken);
            $requestCycle->socket = $socket;
        } catch (ConnectException $e) {
            throw new SocketException(\sprintf("Connection to '%s' failed", $socketCheckoutUri), 0, $e);
        } catch (CancelledException $e) {
            // In case of a user cancellation request, throw the expected exception
            $requestCycle->cancellation->throwIfRequested();

            // Otherwise we ran into a timeout of our TimeoutCancellationToken
            throw new TimeoutException(\sprintf("Connection to '%s' timed out", $authority), 0, $e);
        }

        $cancellation = $requestCycle->cancellation->subscribe(function ($error) use ($requestCycle) {
            $this->fail($requestCycle, $error);
        });

        try {
            if ($requestCycle->request->getUri()->getScheme() === 'https') {
                $tlsContext = $this->tlsContext
                    ->withPeerName($requestCycle->request->getUri()->getHost())
                    ->withPeerCapturing();

                $socket->enableCrypto($tlsContext);
            }

            // Collect this here, because it fails in case the remote closes the connection directly.
            $connectionInfo = $this->collectConnectionInfo($socket);

            $socket->write($this->generateRawRequestHeaders($requestCycle->request, $requestCycle->protocolVersion));

            $body = $requestCycle->request->getBody()->createBodyStream();
            $chunking = $requestCycle->request->getHeader("transfer-encoding") === "chunked";
            $remainingBytes = $requestCycle->request->getHeader("content-length");

            if ($chunking && $requestCycle->protocolVersion === "1.0") {
                throw new HttpException("Can't send chunked bodies over HTTP/1.0");
            }

            // We always buffer the last chunk to make sure we don't write $contentLength bytes if the body is too long.
            $buffer = "";

            while (null !== $chunk = $body->read()) {
                $requestCycle->cancellation->throwIfRequested();

                if ($chunk === "") {
                    continue;
                }

                if ($chunking) {
                    $chunk = \dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                } elseif ($remainingBytes !== null) {
                    $remainingBytes -= \strlen($chunk);

                    if ($remainingBytes < 0) {
                        throw new HttpException("Body contained more bytes than specified in Content-Length, aborting request");
                    }
                }

                $socket->write($buffer);
                $buffer = $chunk;
            }

            // Flush last buffered chunk.
            $socket->write($buffer);

            if ($chunking) {
                $socket->write("0\r\n\r\n");
            } elseif ($remainingBytes !== null && $remainingBytes > 0) {
                throw new HttpException("Body contained fewer bytes than specified in Content-Length, aborting request");
            }

            $this->doRead($requestCycle, $socket, $connectionInfo);
        } finally {
            $requestCycle->cancellation->unsubscribe($cancellation);
        }
    }

    private function fail(RequestCycle $requestCycle, \Throwable $error): void
    {
        $toFails = [];
        $socket = null;

        if ($requestCycle->deferred) {
            $toFails[] = $requestCycle->deferred;
            $requestCycle->deferred = null;
        }

        if ($requestCycle->body) {
            $toFails[] = $requestCycle->body;
            $requestCycle->body = null;
        }

        if ($requestCycle->bodyDeferred) {
            $toFails[] = $requestCycle->bodyDeferred;
            $requestCycle->bodyDeferred = null;
        }

        if ($requestCycle->socket) {
            $this->socketPool->clear($requestCycle->socket);
            $socket = $requestCycle->socket;
            $requestCycle->socket = null;
            $socket->close();
        }

        foreach ($toFails as $toFail) {
            $toFail->fail($error);
        }
    }

    private function normalizeRequestBodyHeaders(Request $request): Request
    {
        if ($request->hasHeader("Transfer-Encoding")) {
            return $request->withoutHeader("Content-Length");
        }

        if ($request->hasHeader("Content-Length")) {
            return $request;
        }

        $body = $request->getBody();
        $bodyLength = $body->getBodyLength();

        if ($bodyLength === 0) {
            $request = $request->withHeader('Content-Length', '0');
            $request = $request->withoutHeader('Transfer-Encoding');
        } else if ($bodyLength > 0) {
            $request = $request->withHeader("Content-Length", $bodyLength);
            $request = $request->withoutHeader("Transfer-Encoding");
        } else {
            $request = $request->withHeader("Transfer-Encoding", "chunked");
        }

        return $request;
    }

    private function normalizeRequestHeaders($request): Request
    {
        $request = $this->normalizeRequestBodyHeaders($request);
        $request = $this->normalizeRequestEncodingHeaderForZlib($request);
        $request = $this->normalizeRequestHostHeader($request);
        $request = $this->normalizeRequestUserAgent($request);
        $request = $this->normalizeRequestAcceptHeader($request);

        return $request;
    }

    private function normalizeTraceRequest(Request $request): Request
    {
        $method = $request->getMethod();

        if ($method !== 'TRACE') {
            return $request;
        }

        // Remove body and sensitive headers
        // https://tools.ietf.org/html/rfc7231#section-4.3.8
        $request = $request->withBody(null);
        $request = $request->withHeaders([
            "Transfer-Encoding" => [],
            "Content-Length" => [],
            "Authorization" => [],
            "Proxy-Authorization" => [],
            "Cookie" => [],
        ]);

        return $request;
    }

    private function normalizeRequestEncodingHeaderForZlib(Request $request): Request
    {
        if ($request->hasHeader('Accept-Encoding')) {
            return $request;
        }

        if ($this->hasZlib) {
            return $request->withHeader('Accept-Encoding', 'gzip, deflate, identity');
        }

        return $request;
    }

    private function normalizeRequestHostHeader(Request $request): Request
    {
        if ($request->hasHeader('Host')) {
            return $request;
        }

        return $request->withHeader('Host', $request->getUri()->getAuthority());
    }

    private function normalizeRequestUserAgent(Request $request): Request
    {
        if ($request->hasHeader('User-Agent')) {
            return $request;
        }

        return $request->withHeader('User-Agent', self::DEFAULT_USER_AGENT);
    }

    private function normalizeRequestAcceptHeader(Request $request): Request
    {
        if ($request->hasHeader('Accept')) {
            return $request;
        }

        return $request->withHeader('Accept', '*/*');
    }

    /**
     * @param RequestCycle   $requestCycle
     * @param array          $parserResult
     * @param ConnectionInfo $connectionInfo
     *
     * @return Response
     *
     * @throws StreamException
     */
    private function finalizeResponse(
        RequestCycle $requestCycle,
        array $parserResult,
        ConnectionInfo $connectionInfo
    ): Response {
        $body = new IteratorStream($requestCycle->body->extractIterator());

        // Handle (double) encoded content
        while ($encoding = $this->determineCompressionEncoding($parserResult["headers"])) {
            array_shift($parserResult["headers"]["content-encoding"]);
            if (!$parserResult["headers"]["content-encoding"]) {
                unset($parserResult["headers"]["content-encoding"]);
            }

            $body = new ZlibInputStream($body, $encoding);
        }

        // Wrap the input stream so we can discard the body in case it's destructed but hasn't been consumed.
        // This allows reusing the connection for further requests. It's important to have __destruct in InputStream and
        // not in Message, because an InputStream might be pulled out of Message and used separately.
        $body = new class($body, $requestCycle, $this->socketPool) implements InputStream
        {
            private $body;
            private $bodySize = 0;
            private $requestCycle;
            private $socketPool;
            private $successfulEnd = false;

            public function __construct(InputStream $body, RequestCycle $requestCycle, HttpSocketPool $socketPool)
            {
                $this->body = $body;
                $this->requestCycle = $requestCycle;
                $this->socketPool = $socketPool;
            }

            public function read(): ?string
            {
                $data = $this->body->read();
                if ($data === null) {
                    $this->successfulEnd = true;
                } else {
                    $this->bodySize += \strlen($data);
                    $maxBytes = $this->requestCycle->options[Client::OP_MAX_BODY_BYTES];
                    if ($maxBytes !== 0 && $this->bodySize >= $maxBytes) {
                        $this->requestCycle->bodyTooLarge = true;
                    }
                }

                return $data;
            }

            public function __destruct()
            {
                if (!$this->successfulEnd && $this->requestCycle->socket) {
                    $this->socketPool->clear($this->requestCycle->socket);
                    $socket = $this->requestCycle->socket;
                    $this->requestCycle->socket = null;
                    $socket->close();
                }
            }
        };

        $response = new class($parserResult["protocol"], $parserResult["status"], $parserResult["reason"], $parserResult["headers"], $body, $requestCycle->request, $requestCycle->previousResponse, new MetaInfo($connectionInfo)) implements Response
        {
            private $protocolVersion;
            private $status;
            private $reason;
            private $request;
            private $previousResponse;
            private $headers;
            private $body;
            private $metaInfo;

            public function __construct(
                string $protocolVersion,
                int $status,
                string $reason,
                array $headers,
                InputStream $body,
                Request $request,
                Response $previousResponse = null,
                MetaInfo $metaInfo
            ) {
                $this->protocolVersion = $protocolVersion;
                $this->status = $status;
                $this->reason = $reason;
                $this->headers = $headers;
                $this->body = new Message($body);
                $this->request = $request;
                $this->previousResponse = $previousResponse;
                $this->metaInfo = $metaInfo;
            }

            public function getProtocolVersion(): string
            {
                return $this->protocolVersion;
            }

            public function getStatus(): int
            {
                return $this->status;
            }

            public function getReason(): string
            {
                return $this->reason;
            }

            public function getRequest(): Request
            {
                return $this->request;
            }

            public function getOriginalRequest(): Request
            {
                if (null === $this->previousResponse) {
                    return $this->request;
                }

                return $this->previousResponse->getOriginalRequest();
            }

            public function getPreviousResponse(): ?Response
            {
                return $this->previousResponse;
            }

            public function hasHeader(string $field): bool
            {
                return isset($this->headers[\strtolower($field)]);
            }

            public function getHeader(string $field): ?string
            {
                return $this->headers[\strtolower($field)][0] ?? null;
            }

            public function getHeaderArray(string $field): array
            {
                return $this->headers[\strtolower($field)] ?? [];
            }

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function getBody(): Message
            {
                return $this->body;
            }

            public function getMetaInfo(): MetaInfo
            {
                return $this->metaInfo;
            }
        };

        return $response;
    }

    private function shouldCloseSocketAfterResponse(Response $response): bool
    {
        $request = $response->getRequest();

        $requestConnection = $request->getHeader('Connection');
        $responseConnection = $response->getHeader('Connection');

        if ($requestConnection !== null && !strcasecmp($requestConnection, 'close')) {
            return true;
        }

        if ($responseConnection !== null && !strcasecmp($responseConnection, 'close')) {
            return true;
        }

        if ($responseConnection === null && $response->getProtocolVersion() === '1.0') {
            return true;
        }

        return false;
    }

    private function determineCompressionEncoding(array $responseHeaders): int
    {
        if (!$this->hasZlib) {
            return 0;
        }

        if (!isset($responseHeaders["content-encoding"])) {
            return 0;
        }

        $contentEncodingHeader = \trim($responseHeaders["content-encoding"][0]);

        if (strcasecmp($contentEncodingHeader, 'gzip') === 0) {
            return \ZLIB_ENCODING_GZIP;
        }

        if (strcasecmp($contentEncodingHeader, 'deflate') === 0) {
            return \ZLIB_ENCODING_DEFLATE;
        }

        return 0;
    }

    /**
     * @param Request $request
     * @param string  $protocolVersion
     *
     * @return string
     *
     * @TODO Send absolute URIs in the request line when using a proxy server
     *       Right now this doesn't matter because all proxy requests use a CONNECT
     *       tunnel but this likely will not always be the case.
     *
     * @throws HttpException
     */
    private function generateRawRequestHeaders(Request $request, string $protocolVersion): string
    {
        $uri = $request->getUri();

        $requestUri = $uri->getPath() ?: '/';
        if ($query = $uri->getQuery()) {
            $requestUri .= '?' . $query;
        }

        $startLine = $request->getMethod() . ' ' . $requestUri . ' HTTP/' . $protocolVersion . "\r\n";

        try {
            return $startLine . Rfc7230::formatHeaders($request->getHeaders(true)) . "\r\n";
        } catch (InvalidHeaderException $exception) {
            throw new HttpException("Sending the request failed due to a header injection attempt", 0, $exception);
        }
    }

    /**
     * Set multiple options at once.
     *
     * @param array $options An array of the form [OP_CONSTANT => $value]
     *
     * @throws \Error On unknown option key or invalid value.
     */
    public function setOptions(array $options): void
    {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * Set an option.
     *
     * @param string $option A Client option constant
     * @param mixed  $value The option value to assign
     *
     * @throws \Error On unknown option key or invalid value.
     */
    public function setOption(string $option, $value): void
    {
        $this->validateOption($option, $value);
        $this->options[$option] = $value;
    }

    private function validateOption(string $option, $value): void
    {
        switch ($option) {
            case self::OP_TRANSFER_TIMEOUT:
                if (!\is_int($value) || $value < 0) {
                    throw new \Error("Invalid value for OP_TRANSFER_TIMEOUT, int >= 0 expected");
                }

                break;

            case self::OP_MAX_REDIRECTS:
                if (!\is_int($value) || $value < 0) {
                    throw new \Error("Invalid value for OP_MAX_REDIRECTS, int >= 0 expected");
                }

                break;

            case self::OP_AUTO_REFERER:
                if (!\is_bool($value)) {
                    throw new \TypeError("Invalid value for OP_AUTO_REFERER, bool expected");
                }

                break;

            case self::OP_DISCARD_BODY:
                if (!\is_bool($value)) {
                    throw new \TypeError("Invalid value for OP_DISCARD_BODY, bool expected");
                }

                break;

            case self::OP_DEFAULT_HEADERS:
                // We attempt to set the headers here, because they're automatically validated then.
                Request::fromString("https://example.com/")->withHeaders($value);

                break;

            case self::OP_MAX_HEADER_BYTES:
                if (!\is_int($value) || $value < 0) {
                    throw new \Error("Invalid value for OP_MAX_HEADER_BYTES, int >= 0 expected");
                }

                break;

            case self::OP_MAX_BODY_BYTES:
                if (!\is_int($value) || $value < 0) {
                    throw new \Error("Invalid value for OP_MAX_BODY_BYTES, int >= 0 expected");
                }

                break;

            default:
                throw new \Error(
                    sprintf("Unknown option: %s", $option)
                );
        }
    }

    /**
     * @param ClientSocket $socket
     *
     * @return ConnectionInfo
     *
     * @throws SocketException
     */
    private function collectConnectionInfo(ClientSocket $socket): ConnectionInfo
    {
        $stream = $socket->getResource();

        if ($stream === null) {
            throw new SocketException("Socket closed before connection information could be collected");
        }

        $crypto = \stream_get_meta_data($stream)["crypto"] ?? null;

        return new ConnectionInfo(
            $socket->getLocalAddress(),
            $socket->getRemoteAddress(),
            $crypto ? TlsInfo::fromMetaData($crypto, \stream_context_get_options($stream)["ssl"]) : null
        );
    }
}
