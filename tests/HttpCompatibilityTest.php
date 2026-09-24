<?php

declare(strict_types=1);

use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Codefy\Framework\Http\Middleware\CorsMiddleware;
use Codefy\Framework\Http\Middleware\Csrf\CsrfProtectionMiddleware;
use Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware;
use Codefy\Framework\Http\Middleware\Csrf\TokenMismatchException;
use Codefy\Framework\Security\Firewall\FirewallExclusionPolicy;
use Codefy\Framework\Security\Firewall\NullThreatLogger;
use Codefy\Framework\Security\Firewall\ThreatDetector;
use Codefy\Framework\Security\Firewall\ThreatPatternRegistry;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Http\Cookies\Factory\CookieFactory;
use Qubus\Http\Response;
use Qubus\Http\ServerRequest;

function requestHandler(Closure $callback): RequestHandlerInterface
{
    return new class ($callback) implements RequestHandlerInterface {
        public function __construct(private Closure $callback)
        {
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return ($this->callback)($request);
        }
    };
}

it('accepts configured PATCH preflights without authenticating or preparing CSRF', function () {
    $handler = requestHandler(fn() => throw new RuntimeException('Preflight reached the handler'));
    $response = new CorsMiddleware($this->config)->process(new ServerRequest(method: 'OPTIONS', headers: [
        'Origin' => 'https://client.example',
        'Access-Control-Request-Method' => 'PATCH',
        'Access-Control-Request-Headers' => 'Content-Type, X-CSRF-Token',
    ]), $handler);
    expect($response->getStatusCode())->toBe(204)
        ->and($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*')
        ->and($response->hasHeader('Access-Control-Allow-Credentials'))->toBeFalse();
});

it('requires a submitted CSRF field even after token preparation', function () {
    $cookies = new CookieFactory($this->config);
    $prepare = new CsrfTokenMiddleware($this->config, $cookies);
    $protect = new CsrfProtectionMiddleware($this->config, $cookies);
    $handler = requestHandler(fn() => new Response());
    $protected = requestHandler(fn($request) => $protect->process($request, $handler));
    expect(fn() => $prepare->process(new ServerRequest(method: 'POST'), $protected))
        ->toThrow(TokenMismatchException::class);

    $token = bin2hex(random_bytes(32));
    $key = Key::loadFromAsciiSafeString($this->config->string('app.crypto_key'));
    $request = new ServerRequest(method: 'POST', parsedBody: ['_token' => $token], cookies: [
        'CSRFSESSID' => Crypto::encrypt($token, $key),
    ]);
    expect($prepare->process($request, $protected)->getStatusCode())->toBe(200);
});

it('rejects legacy and expired authentication cookies with the application key', function () {
    $key = Key::loadFromAsciiSafeString($this->config->string('app.crypto_key'));
    $middleware = new UserCookieDecryptMiddleware($this->config);
    $handler = requestHandler(function ($request) {
        expect($request->getAttribute('auth.token'))->toBeNull();
        return new Response();
    });
    foreach (['legacy-token', json_encode(['version' => 1, 'token' => 'expired', 'expires' => time() - 1])] as $value) {
        $request = new ServerRequest(cookies: ['USERSESSID' => Crypto::encrypt($value, $key)]);
        $middleware->process($request->withAttribute('auth.token', 'stale'), $handler);
    }
});

it('allows localhost page URLs while detecting submitted SSRF destinations', function () {
    $detector = new ThreatDetector(
        new ThreatPatternRegistry($this->config),
        new FirewallExclusionPolicy($this->config),
        new NullThreatLogger()
    );
    $request = new ServerRequest(uri: 'http://localhost:8080/login/');
    expect($detector->detect($request))->toBeNull()
        ->and($detector->detect($request->withQueryParams(['url' => 'http://127.0.0.1/private']))->type)->toBe('ssrf');
});

it('limits the HTML firewall exclusion to editor POST data', function () {
    $detector = new ThreatDetector(
        new ThreatPatternRegistry($this->config),
        new FirewallExclusionPolicy($this->config),
        new NullThreatLogger()
    );
    $html = '<iframe src="https://www.youtube.com/embed/test"></iframe>';
    $request = new ServerRequest(uri: 'https://example.com/admin/manager/pagebuilder', method: 'POST',
        parsedBody: ['data' => $html]);
    expect($detector->detect($request))->toBeNull()
        ->and($detector->detect($request->withMethod('GET')))->not->toBeNull()
        ->and($detector->detect($request->withParsedBody(['other' => $html])))->not->toBeNull()
        ->and($detector->detect(new ServerRequest(uri: 'https://example.com/create/', method: 'POST',
            parsedBody: ['data' => $html])))->not->toBeNull();
});

it('returns a generic JSON 500 for database exceptions with string SQLSTATE codes', function () {
    $filesystem = new \Qubus\FileSystem\FileSystem(new \League\Flysystem\Local\LocalFilesystemAdapter($this->testPath));
    $middleware = new \Application\Http\Middleware\HttpExceptionMiddleware(
        $this->app,
        $filesystem,
        new \Codefy\Framework\View\ErrorViewRenderer($this->app, $filesystem)
    );
    $response = $middleware->process(new ServerRequest(headers: ['Accept' => 'application/json']),
        requestHandler(function () {
            new PDO('sqlite::memory:')->exec('SELECT * FROM private_account_records');
            return new Response();
        }));
    expect($response->getStatusCode())->toBe(500)
        ->and((string) $response->getBody())->not->toContain('private_account_records', 'SQLSTATE', 'PDOException');
});
