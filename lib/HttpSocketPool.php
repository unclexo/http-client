<?php

namespace Amp\Artax;

use Amp\Cancellation\Token;
use Amp\Socket\BasicSocketPool;
use Amp\Socket\ClientSocket;
use Amp\Socket\SocketPool;
use Amp\Uri\Uri;

class HttpSocketPool implements SocketPool
{
    public const OP_PROXY_HTTP = 'amp.artax.httpsocketpool.proxy-http';
    public const OP_PROXY_HTTPS = 'amp.artax.httpsocketpool.proxy-https';

    private $socketPool;
    private $tunneler;

    private $options = [
        self::OP_PROXY_HTTP => null,
        self::OP_PROXY_HTTPS => null,
    ];

    public function __construct(SocketPool $sockPool = null, HttpTunneler $tunneler = null)
    {
        $this->socketPool = $sockPool ?? new BasicSocketPool;
        $this->tunneler = $tunneler ?? new HttpTunneler;
        $this->autoDetectProxySettings();
    }

    private function autoDetectProxySettings(): void
    {
        // See CVE-2016-5385, due to (emulation of) header copying with PHP web SAPIs into HTTP_* variables,
        // HTTP_PROXY can be set by an user to any value he wants by setting the Proxy header.
        // Mitigate the vulnerability by only allowing CLI SAPIs to use HTTP(S)_PROXY environment variables.
        if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg" && PHP_SAPI !== "embed") {
            return;
        }

        if (($httpProxy = \getenv('http_proxy')) || ($httpProxy = \getenv('HTTP_PROXY'))) {
            $this->options[self::OP_PROXY_HTTP] = $this->getUriAuthority($httpProxy);
        }

        if (($httpsProxy = \getenv('https_proxy')) || ($httpsProxy = \getenv('HTTPS_PROXY'))) {
            $this->options[self::OP_PROXY_HTTPS] = $this->getUriAuthority($httpsProxy);
        }
    }

    private function getUriAuthority(string $uri): string
    {
        $parsedUri = new Uri($uri);

        return $parsedUri->getHost() . ":" . $parsedUri->getPort();
    }

    /** @inheritdoc */
    public function checkout(string $uri, Token $cancellationToken = null): ClientSocket
    {
        $parsedUri = new Uri($uri);

        $scheme = $parsedUri->getScheme();

        if ($scheme === 'tcp' || $scheme === 'http') {
            $proxy = $this->options[self::OP_PROXY_HTTP];
        } elseif ($scheme === 'tls' || $scheme === 'https') {
            $proxy = $this->options[self::OP_PROXY_HTTPS];
        } else {
            throw new \Error(
                'Either tcp://, tls://, http:// or https:// URI scheme required for HTTP socket checkout'
            );
        }

        // The underlying TCP pool will ignore the URI fragment when connecting but retain it in the
        // name when tracking hostname connection counts. This allows us to expose host connection
        // limits transparently even when connecting through a proxy.
        $authority = $parsedUri->getHost() . ":" . $parsedUri->getPort();

        if (!$proxy) {
            return $this->socketPool->checkout("tcp://{$authority}", $cancellationToken);
        }

        $socket = $this->socketPool->checkout("tcp://{$proxy}#{$authority}", $cancellationToken);
        $this->tunneler->tunnel($socket, $authority);

        return $socket;
    }

    /** @inheritdoc */
    public function checkin(ClientSocket $socket): void
    {
        $this->socketPool->checkin($socket);
    }

    /** @inheritdoc */
    public function clear(ClientSocket $socket): void
    {
        $this->socketPool->clear($socket);
    }

    /** @inheritdoc */
    public function setOption(string $option, $value): void
    {
        switch ($option) {
            case self::OP_PROXY_HTTP:
                $this->options[self::OP_PROXY_HTTP] = (string) $value;
                break;
            case self::OP_PROXY_HTTPS:
                $this->options[self::OP_PROXY_HTTPS] = (string) $value;
                break;
            default:
                throw new \Error("Invalid option: $option");
        }
    }
}
