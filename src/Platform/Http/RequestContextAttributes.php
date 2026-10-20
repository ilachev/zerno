<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Runtime\RequestContext;

final class RequestContextAttributes
{
    public const CONTEXT = 'requestContext';
    public const REQUEST_ID = 'requestId';

    public static function attach(Request $request, RequestContext $context): Request
    {
        return $request
            ->withAttribute(self::CONTEXT, $context)
            ->withAttribute(self::REQUEST_ID, $context->requestId);
    }

    public static function get(Request $request): ?RequestContext
    {
        $context = $request->getAttribute(self::CONTEXT);

        return $context instanceof RequestContext ? $context : null;
    }

    public static function require(Request $request): RequestContext
    {
        $context = self::get($request);
        if ($context !== null) {
            return $context;
        }

        throw new \LogicException('Request context is missing from request attributes');
    }
}
