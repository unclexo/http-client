<?php

namespace Amp\Artax;

use Amp\Artax\Internal\Parser;
use Amp\ByteStream\StreamException;
use Amp\Socket\ClientSocket;

class HttpTunneler
{
    /**
     * Establish an HTTP tunnel to the specified authority over this socket.
     *
     * @param ClientSocket $socket
     * @param string       $authority
     *
     * @throws HttpException
     */
    public function tunnel(ClientSocket $socket, string $authority): void
    {
        if (!empty(stream_context_get_options($socket->getResource())['artax*']['is_tunneled'])) {
            return;
        }

        $parser = new Parser(null);
        $parser->enqueueResponseMethodMatch("CONNECT");

        try {
            $socket->write("CONNECT {$authority} HTTP/1.1\r\n\r\n");
        } catch (StreamException $e) {
            throw new SocketException(
                'Proxy CONNECT failed: Socket went away while writing tunneling request',
                0,
                $e
            );
        }

        try {
            while (null !== $chunk = $socket->read()) {
                if (!$response = $parser->parse($chunk)) {
                    continue;
                }

                if ($response["status"] === 200) {
                    // Tunnel connected! We're finished \o/ #WinningAtLife #DealWithIt
                    \stream_context_set_option($socket->getResource(), 'artax*', 'is_tunneled', true);

                    return;
                }

                throw new HttpException(\sprintf(
                    'Proxy CONNECT failed: Unexpected response status received from proxy: %d',
                    $response["status"]
                ));
            }
        } catch (ParseException $e) {
            throw new HttpException(
                'Proxy CONNECT failed: Malformed HTTP response received from proxy while establishing tunnel',
                0,
                $e
            );
        } catch (StreamException $e) {
            // fall through
        }

        throw new SocketException(
            'Proxy CONNECT failed: Socket went away while awaiting tunneling response',
            0,
            $e ?? null
        );
    }
}
