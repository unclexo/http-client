<?php

namespace Amp\Artax;

use Amp\Artax\Internal\Parser;
use Amp\Cancellation\Token;

/**
 * Interface definition for an HTTP client.
 */
interface Client
{
    /** Transfer timeout in milliseconds until an HTTP request is automatically aborted, use 0 to disable. */
    public const OP_TRANSFER_TIMEOUT = 'amp.artax.client.transfer-timeout';

    /** How many redirects to follow, might be 0 to not follow any redirects. */
    public const OP_MAX_REDIRECTS = 'amp.artax.client.max-redirects';

    /** Whether to automatically add a "Referer" header on redirects. */
    public const OP_AUTO_REFERER = 'amp.artax.client.auto-referer';

    /** Whether to directly discard the HTTP response body or not. */
    public const OP_DISCARD_BODY = 'amp.artax.client.discard-body';

    /** Default headers to use. */
    public const OP_DEFAULT_HEADERS = 'amp.artax.client.default-headers';

    /** Maximum header size, usually doesn't have to be adjusted. */
    public const OP_MAX_HEADER_BYTES = Parser::OP_MAX_HEADER_BYTES;

    /** Maximum body size. Needs to be adjusted for streaming large responses, e.g. Streaming APIs. */
    public const OP_MAX_BODY_BYTES = Parser::OP_MAX_BODY_BYTES;

    /**
     * Asynchronously request an HTTP resource.
     *
     * @param Request $request An HTTP URI string or a Request instance.
     * @param array   $options An array specifying options applicable only for this request.
     * @param Token   $cancellation A cancellation token to optionally cancel requests.
     *
     * @return Response A promise to resolve to a response object as soon as its headers are received.
     *
     * @throws HttpException
     */
    public function request(Request $request, array $options = [], Token $cancellation = null): Response;
}
