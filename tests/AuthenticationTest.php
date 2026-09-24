<?php

declare(strict_types=1);

use Codefy\Framework\Auth\Rbac\Rbac;
use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Codefy\Framework\Http\RequestContext;
use Codefy\Framework\Support\Password;
use Infrastructure\Persistence\Repository\PdoAuthUserRespository;
use Infrastructure\Persistence\UserAuth;
use Qubus\Expressive\Connection\DriverConnection;
use Qubus\Http\ServerRequest;

it('treats an absent, malformed, or stale request identity as a guest', function () {
    $repository = Mockery::mock(AuthUserRepository::class);
    $repository->shouldReceive('find')->with('deleted-user')->andReturn(null);
    $auth = new UserAuth(Mockery::mock(Rbac::class), $repository);
    expect($auth->guest())->toBeTrue()->and($auth->can('admin:dashboard'))->toBeFalse();
    foreach ([null, '', [], 'deleted-user'] as $token) {
        RequestContext::set(new ServerRequest()->withAttribute(UserCookieDecryptMiddleware::USER_COOKIE, $token));
        expect($auth->guest())->toBeTrue()->and($auth->can('admin:dashboard'))->toBeFalse();
    }
});

it('rehashes only a verified password and honors configured token columns', function () {
    $connection = DriverConnection::make(['driver' => 'sqlite', 'dsn' => 'sqlite::memory:']);
    $connection->pdo->exec('CREATE TABLE users (email TEXT, password TEXT, session_token TEXT)');
    $oldHash = password_hash('correct password', PASSWORD_BCRYPT, ['cost' => 4]);
    $connection->pdo->prepare('INSERT INTO users VALUES (?, ?, ?)')->execute([
        'alice@example.com', $oldHash, 'test-token',
    ]);
    $this->config->setConfigKey('auth', ['pdo' => [
        'table' => 'users',
        'fields' => ['identity' => 'email', 'password' => 'password', 'token' => 'session_token', 'role' => 'role'],
    ]]);
    $repository = new PdoAuthUserRespository($connection, $this->config);
    expect($repository->authenticate('alice@example.com', 'wrong'))->toBeNull()
        ->and($connection->pdo->query('SELECT password FROM users')->fetchColumn())->toBe($oldHash);
    expect($repository->authenticate('alice@example.com', 'correct password')->token)->toBe('test-token');
    $newHash = $connection->pdo->query('SELECT password FROM users')->fetchColumn();
    expect($newHash)->not->toBe($oldHash)
        ->and(Password::verify('correct password', $newHash))->toBeTrue()
        ->and(Password::needsRehash($newHash))->toBeFalse()
        ->and($repository->find('test-token')->email)->toBe('alice@example.com');
});
