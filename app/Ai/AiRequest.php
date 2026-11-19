<?php

namespace App\Ai;

use App\Http\Support\RawCookies;
use Illuminate\Http\Request;

/**
 * What one AI request may read from the browser (the visitor cookie, the
 * verified peer address, headers) and what it wants to answer with besides
 * the JSON body (status, headers, a newly issued visitor cookie).
 */
final class AiRequest
{
    public int $status = 200;

    /** @var array<string, string> */
    public array $headers = [];

    /** A visitor cookie value to set, when one was issued during this request. */
    public ?string $issuedVisitorCookie = null;

    /** @param  \Closure(string): ?string  $header  lower-case header name to its value */
    public function __construct(
        public readonly ?string $visitorCookie,
        public readonly ?string $remoteAddress,
        public readonly \Closure $header,
    ) {}

    public static function from(Request $request): self
    {
        $cookie = RawCookies::from($request)[Quota::VISITOR_COOKIE] ?? null;

        return new self(
            $cookie === null ? null : rawurldecode($cookie),
            $request->server->get('REMOTE_ADDR'),
            fn (string $name) => $request->headers->get($name),
        );
    }
}
