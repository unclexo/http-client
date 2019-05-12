<?php

namespace Amp\Artax;

use Concurrent\Stream\ReadableMemoryStream;
use Concurrent\Stream\ReadableStream;

final class StringBody implements RequestBody
{
    private $body;

    public function __construct(string $body)
    {
        $this->body = $body;
    }

    public function createBodyStream(): ReadableStream
    {
        return new ReadableMemoryStream($this->body);
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function getBodyLength(): int
    {
        return \strlen($this->body);
    }
}
