<?php

namespace Prolaxu\Osync\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gzip both ways for the sync route. Sync bodies are repetitive JSON and
 * shrink five to ten times; many servers only gzip text/html by default.
 *
 * A request body sent with `Content-Encoding: gzip` is inflated, up to
 * [maxInflatedBytes] so a small upload cannot expand without bound. A response
 * over [minBytes] is compressed when the client accepts gzip.
 */
final class Compression
{
    public function __construct(
        private readonly int $minBytes = 1024,
        private readonly int $maxInflatedBytes = 32 * 1024 * 1024,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (strtolower((string) $request->header('Content-Encoding')) === 'gzip') {
            $this->inflate($request);
        }

        $response = $next($request);

        if ($this->acceptsGzip($request) && ! $response->headers->has('Content-Encoding')) {
            $body = (string) $response->getContent();
            if (strlen($body) >= $this->minBytes) {
                $response->setContent((string) gzencode($body, 6));
                $response->headers->set('Content-Encoding', 'gzip');
                $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
            }
        }
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }

    private function inflate(Request $request): void
    {
        $body = @gzdecode($request->getContent(), $this->maxInflatedBytes);
        if ($body === false) {
            throw new HttpException(400, 'Request body is not valid gzip, or inflates past the limit.');
        }

        $json = json_decode($body, true);
        $request->setJson(new InputBag(is_array($json) ? $json : []));
        $request->headers->remove('Content-Encoding');
    }

    private function acceptsGzip(Request $request): bool
    {
        return str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip');
    }
}
