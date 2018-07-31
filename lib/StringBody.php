<?php

namespace Amp\Artax;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;

final class StringBody implements RequestBody
{
    private $body;

    public function __construct(string $body)
    {
        $this->body = $body;
    }

    public function createBodyStream(): InputStream
    {
        return new InMemoryStream($this->body);
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
