# HTTP request lifecycle

Type: `architecture`. Status: `current`. Reviewed against code: 2026-10-28. English edition: 2026-11-13.

This describes implemented application compositions. See [architecture](overview.md) for components and [verification](../development/infrastructure-and-testing.md) for commands.

## Composition and entry point

| Composition | Configuration | Routes | Pipeline |
| --- | --- | --- | --- |
| Core | [config/core.php](../../config/core.php) | [Explicit routes](../../config/routes.php) | RequestContext → ErrorHandler → Routing → RouteHandlerResolver |
| Catalog | [config](../../examples/document-catalog/config.php), [application](../../examples/document-catalog/application.php) | Constructed GET/HEAD handlers | Same middleware, HTML ErrorResponse |
| JSON POST | [config](../../examples/json-echo/config.php) | GET/HEAD /health, POST /echo | Same middleware, EchoHandler with 64 KiB limit |
| Generated API | [config](../../examples/contract-echo/config.php) | GET/HEAD /health, POST /api/echo | Same middleware, endpoint and EchoSendHttp |

GET/HEAD /health returns JSON {"status":"ok"}. Core creates no Storage/migration service. Catalog builds Storage and handlers upfront but connects on first SQL; /health/404/405 are independent of the database.

[public/index.php](../../public/index.php) loads [bootstrap](../../bootstrap.php), rejects PHP below the [security floor](../development/security.md), selects APP_CONFIG (default config/core.php), invokes Closure(Logger): App with RoadRunnerLogger, and starts native transport. An absolute APP_CONFIG avoids cwd dependence. [App](../../src/Platform/Runtime/App.php) delegates handle/handleRequest without reading config or starting a loop. CLI migration/seed/generation remain ordinary processes.

```text
RoadRunner HTTP → RoadRunnerHttpWorker::waitRequest()
               → App::handle(Request)
               → RoadRunnerHttpWorker::respond(response)
               → next waitRequest()
```

[RoadRunnerRuntime](../../src/Platform/Runtime/RoadRunnerRuntime.php) exits on null from waitRequest; worker errors propagate. [Adapter](../../src/Platform/Runtime/RoadRunnerHttpWorker.php) converts native requests without mutating superglobals. It transfers headers, query, cookies, parsed forms, attributes, and nested uploads, including files[name]. Server params include REMOTE_ADDR, REQUEST_METHOD, and original REQUEST_URI. JSON remains raw text; parsed forms are separate.

HEAD/1xx/204/205/304 bodies are suppressed without reading or rewinding. 1xx/204 remove Content-Length. 205 removes previous Content-Length/Transfer-Encoding and sets Content-Length: 0, including HEAD, following [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html#section-15.3.6). Runtime closes the body in finally, including transport errors; App does not.

The recorded HTTP check used PHP 8.5.12/RR 3.0.1 with worker 3.8.0, HTTP 4.2.1, Goridge 4.6.0, DTO 2.1.0. Native HttpWorker owns frames/protocol. RR_HTTP_CHUNK_SIZE=8192 enables one-chunk lookahead (up to two chunks in the adapter); default 0 materializes text. Seekable bodies rewind; non-seekable bodies start at their current position. Suppressed bodies send empty text even in chunk mode. A saved read error is rethrown after native completion. Cancellation remains upstream behavior; arbitrary-stream endurance is not established. Large regular files are checked.

## Concrete HTTP types

The contract is handle(Request): Response. A small response is `new Response(200, ['Content-Type' => 'text/plain'], 'hello')`. Use Body::create(resource) or Body::fromFile with AttachmentResponse for files. Types implement no PSR-7/17 contract and have no duplicate factory/interface hierarchy.

Request stores method, Uri, headers, Body, protocol, server/query/cookie params, parsed body, uploads, and attributes. With-methods copy metadata; body references remain shared. Uri preserves encoded path/query bytes, normalizes scheme/host and standard ports, and rejects credentials, fragments, bad escapes, control/space/backslash, and unsupported schemes.

Headers accept string/int array keys because PHP converts numeric names into integers. Boundary names become strings; RR preserves numeric fields. Values remain separate; lookup ignores case. Invalid names and CR/LF/control values fail. Set-Cookie is never joined. Values are string or list<string>; status range is 100–599. Transport chooses reason phrase and protocol.

Body stores text plus position or owns a PHP stream. read/getContents use current position; string conversion rewinds seekable bodies and reads to EOF. Read failures throw. close is idempotent; closed reads fail and getSize returns null. Body cannot be cloned; message copies share it. String bodies create no temporary resource. Replacing a body does not close the old one; the caller owns that cleanup.

Body::readLimited(maxBytes) reads the remainder without rewind/close. It returns the whole string or throws LengthException after at most limit+1 logical bytes. Negative limits throw InvalidArgumentException; I/O failure/no progress before EOF throws RuntimeException. Headers/getSize are not trusted as bounds; wrappers may prefetch internally. The call is explicit, with no global JSON parsing/limit. [Manual Echo](../../examples/json-echo/README.md) chooses 64 KiB, closes input in finally, and defines 400/413/415.

This bounds handler reading/parsing, not reception before PHP. RR already materializes input; the example separately caps server input at 1 MiB. Buffers and owners differ.

UploadedFile lazily opens its body and retains error/size/untrusted client name/type. Failed or moved uploads cannot be read/moved. moveTo closes an open body and renames RR's temporary file once; no move_uploaded_file mode exists. Client names never choose storage paths. Server/application set upload limits and policy.

## Middleware and errors

[Pipeline](../../src/Platform/Http/Middleware/Pipeline.php) follows array order, with responses returning in reverse. Each inner layer sees the Request passed to it; attribute changes do not mutate an outer saved request.

[RequestContextMiddleware](../../src/Platform/Http/Middleware/RequestContextMiddleware.php) runs first: keep a nonempty client X-Request-ID or generate one; set requestContext/requestId attributes; update mutable RoadRunnerLogger.requestId; append X-Request-ID, X-Response-Time, and Server-Timing. The unused deadline was removed with the outgoing client.

[ErrorHandlerMiddleware](../../src/Platform/Http/Middleware/ErrorHandlerMiddleware.php) wraps later layers, logs the Throwable, and uses ErrorResponse for generic 500. It does not wrap earlier context code, app construction, incoming conversion, or emission. Logging/rendering failure propagates; no fallback renderer is implied. Log access/retention belong to deployment policy.

[ErrorResponse](../../src/Platform/Http/ErrorResponse.php) has error(Error, status): Response. Core passes public [Error](../../src/Platform/Error/Error.php), without private trace/Throwable. Routing uses it for 404/405 and adds Allow; ErrorHandler uses it for 500. Completed handler responses are preserved.

Core explicitly constructs JsonResponse. Applications supply another renderer to both routing and error middleware; every call returns a fresh response/body with the requested status. Catalog [Errors](../../examples/document-catalog/src/Errors.php) builds HTML directly. Templates, language, and cache policy remain local. Composition selects format once, without Accept negotiation or added network/database I/O. Numeric latency benefit is unmeasured.

Direct App::handle returns a normal HEAD response with a body; the adapter suppresses sending. Bootstrap/conversion errors are outside rendering. Renderers must not retain request state. Tests cover generic private errors, completed responses, HTML without JsonResponse loading, repeated errors/health, and the real JSON/HTML wire matrix.

## Attachments and ownership

[AttachmentResponse::create](../../src/Platform/Http/AttachmentResponse.php) builds status 200, Content-Type (default application/octet-stream), explicit Content-Length, attachment disposition, and nosniff. It receives an open body, name, and caller-verified size; it performs no file open/read/move.

```php
return AttachmentResponse::create(
    $body,
    $originalName,
    $verifiedSize,
    contentType: 'application/pdf',
    fallbackFilename: 'document-' . $id . '.pdf',
)->withHeader('Cache-Control', 'no-store');
```

Disposition contains ASCII filename and UTF-8 filename*. Fallback retains ASCII letters/digits/dot/underscore/hyphen, replaces other bytes, trims edge dots, and defaults to download. Unicode names lose control/format characters; slash/backslash become underscore; edge dots/spaces are trimmed; rawurlencode encodes the result. Invalid/empty UTF-8 uses fallback. The name is a download hint, never a storage path. Negative sizes/invalid headers fail; size and MIME content are not revalidated.

The helper accepts ownership immediately and closes the body on construction failure. After success, the caller owns it until transport transfer. Direct App users must send or close it. Runtime closes after sending/failure. Each request needs a fresh stream; withBody does not close the replaced body.

[DownloadHandler](../../examples/document-catalog/src/DownloadHandler.php) owns publication/access checks, FileStore, regular-file/size verification, PDF policy, and no-store. HEAD validates the file and preserves headers, closes its stream unread, and returns an empty body. Range/conditionals/cache/upload are outside the helper contract. Files must remain unchanged until sending ends.

Unused helpers add no service registration or file I/O. Calling performs filename/header/response work proportional to name length, independent of payload size. RR needs explicit chunks for bounded delivery; closing a resource does not establish complete transmission after mutation/cancellation. See [file measurements](../history/2026-10-18-file-responses.md).

## Routing

[Router](../../src/Platform/Routing/Router.php) compiles parameter patterns at construction and escapes literal parts. Dispatch prefers the requested method's static path, then its parameterized routes in registration order. Another method's static path does not block a matching parameter route. Re-registering method/path replaces its handler in place.

Static paths preserve trailing-slash fallback: /item/ and /item/// can select /item; exact matches win. Root stays /. Parameter routes require exact trailing slashes. HEAD never implicitly becomes GET.

If a path matches only other methods, allowed methods are deduplicated in registration order. Routing returns 405 with Allow; unknown paths return 404 without Allow. Default JSON is {"error":"Method not allowed"} or {"error":"Not found"}. Inner middleware/handlers are skipped. getHandler no longer carries allowed methods.

Found routes set RouteResult::class, routeParams, and handler attributes. [Resolver](../../src/Platform/Http/RouteHandlerResolver.php) requires a constructed RequestHandler, not a class name. Explicit RouteEntry lists come from composition. The [HTTP guide](../development/http-and-baseline.md) covers actual RR dispatch/status/header/state checks; default verify starts no server.

## Diagnostics and state

[HttpLoggingMiddleware](../../src/Platform/Http/Middleware/HttpLoggingMiddleware.php) is explicitly placed after routing. It logs query params before the handler and status/body_size/body_seekable afterward. It never reads/rewinds payload. If response metadata logging fails, it closes the body before propagating to ErrorHandler. This is not a full access log: routing failures and handler exceptions do not complete both entries. Unknown size is null.

The App/pipeline owns objects for the worker lifetime. No container, autowiring cache, or automatic request scope exists. Context/routing attributes stay per request. The graph is built once; repositories/storage may be shared, with lazy PDO and bounded statements. Do not retain request data in long-lived services.
