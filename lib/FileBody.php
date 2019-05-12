<?php

namespace Amp\Artax;

use Concurrent\Stream\ReadableMemoryStream;
use Concurrent\Stream\ReadableStream;

final class FileBody implements RequestBody
{
    /** @var string */
    private $path;

    /**
     * @param string $path The filesystem path for the file we wish to send
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function createBodyStream(): ReadableStream
    {
        $contents = \file_get_contents($this->path);
        if ($contents === false) {
            throw new HttpException("Failed to read file content of '{$this->path}'");
        }

        // TODO Stream file contents
        return new ReadableMemoryStream($contents);
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function getBodyLength(): int
    {
        return \filesize($this->path);
    }
}
