<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('boots and operates a fresh install with secure HTTP defaults', function () {
    $root = dirname(__DIR__);
    foreach (['bootstrap', 'database', 'routes', 'resources', 'locale'] as $directory) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        mkdir($this->testPath . '/' . $directory, 0700, true);
        foreach ($files as $file) {
            $destination = $this->testPath . substr($file->getPathname(), strlen($root));
            $file->isDir() ? mkdir($destination, 0700, true) : copy($file->getPathname(), $destination);
        }
    }
    mkdir($this->testPath . '/public');
    symlink($root . '/public/themes', $this->testPath . '/public/themes');
    symlink($root . '/vendor', $this->testPath . '/vendor');
    unlink($this->testPath . '/.env');
    copy($root . '/.env.example', $this->testPath . '/.env.example');

    $process = new Process([PHP_BINARY, 'bootstrap/setup-environment.php'], $this->testPath);
    expect($process->run())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->testPath . '/.env'))
        ->toContain('APP_BASE_PATH=' . json_encode($this->testPath, JSON_UNESCAPED_SLASHES));
    $environment = file_get_contents($this->testPath . '/.env');
    expect($process->run())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->testPath . '/.env'))->toBe($environment);

    $keyScript = <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';
    ini_set('session.save_path', sys_get_temp_dir());
    $app = Codefy\Framework\Helpers\get_fresh_bootstrap();
    exit($app->make(Application\Console\Kernel::class)->handle(
        new Symfony\Component\Console\Input\ArrayInput(['command' => 'generate:key:file']),
        new Symfony\Component\Console\Output\BufferedOutput()
    ));
    PHP;
    $process = new Process([PHP_BINARY, '-r', $keyScript, $root], $this->testPath);
    expect($process->run())->toBe(0);
    $key = file_get_contents($this->testPath . '/.enc.key');
    expect($key)->not->toBeEmpty();
    if (DIRECTORY_SEPARATOR === '/') {
        expect(fileperms($this->testPath . '/.enc.key') & 0777)->toBe(0600);
    }
    expect($process->run())->toBe(1)
        ->and(file_get_contents($this->testPath . '/.enc.key'))->toBe($key);

    $commandScript = <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';
    ini_set('session.save_path', sys_get_temp_dir());
    $app = Codefy\Framework\Helpers\get_fresh_bootstrap();
    $kernel = $app->make(Application\Console\Kernel::class);
    foreach (['migrate', 'migrate:check', 'queue:list', 'schedule:run'] as $command) {
        try {
            $output = new Symfony\Component\Console\Output\BufferedOutput();
            $status = $kernel->handle(
                new Symfony\Component\Console\Input\ArrayInput(['command' => $command]),
                $output
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, $command . ': ' . $exception->getMessage() . PHP_EOL);
            exit(1);
        }
        if ($status !== 0) {
            fwrite(STDERR, $command . ': ' . $output->fetch());
            foreach (glob(getcwd() . '/storage/logs/*.log') ?: [] as $log) {
                fwrite(STDERR, file_get_contents($log));
            }
            exit($status);
        }
    }
    PHP;
    $process = new Process([PHP_BINARY, '-r', $commandScript, $root], $this->testPath);
    expect($process->run())->toBe(0, $process->getErrorOutput());

    $httpScript = <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';
    ini_set('session.save_path', sys_get_temp_dir());
    $_SERVER['DOCUMENT_ROOT'] = getcwd() . '/public';
    $app = Codefy\Framework\Helpers\get_fresh_bootstrap();
    $app->bootstrapWith([
        Codefy\Framework\Bootstrap\RegisterProviders::class,
        Codefy\Framework\Bootstrap\BootProviders::class,
    ]);
    $kernel = $app->make(Codefy\Framework\Contracts\Http\Kernel::class);
    $login = $kernel->handle(new Qubus\Http\ServerRequest(uri: 'http://localhost:8080/login/', method: 'GET',
        headers: ['Accept' => 'text/html', 'X-Enable-Debug-Bar' => 'true']
    ));
    $preflight = $kernel->handle(new Qubus\Http\ServerRequest(
        uri: 'http://localhost:8080/auth/', method: 'OPTIONS', headers: [
            'Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'POST',
        ]
    ));
    $register = $kernel->handle(new Qubus\Http\ServerRequest(
        uri: 'http://localhost:8080/register/', method: 'GET', headers: ['Accept' => 'text/html']
    ));
    $missing = $kernel->handle(new Qubus\Http\ServerRequest(
        uri: 'http://localhost:8080/does-not-exist', method: 'GET', headers: ['Accept' => 'text/html']
    ));
    $missingOptions = $kernel->handle(new Qubus\Http\ServerRequest(
        uri: 'http://localhost:8080/does-not-exist', method: 'OPTIONS', headers: ['Accept' => 'text/html']
    ));
    $blocked = $kernel->handle(
        (new Qubus\Http\ServerRequest(uri: 'http://localhost:8080/login/', method: 'GET'))
            ->withQueryParams(['url' => 'http://127.0.0.1/private'])
    );
    $firewallLog = implode('', array_map('file_get_contents', glob(getcwd() . '/storage/logs/*.log') ?: []));
    preg_match('/name="_token" value="([a-f0-9]{64})"/', (string) $login->getBody(), $matches);
    $token = $matches[1];
    $key = Defuse\Crypto\Key::loadFromAsciiSafeString(file_get_contents(getcwd() . '/.enc.key'));
    $cookie = Defuse\Crypto\Crypto::encrypt($token, $key);

    $pdo = $app->make(PDO::class);
    $pdo->prepare('INSERT INTO users (user_id, username, email, password, token, role) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'test_admin', 'admin@example.com', 'unused', 'admin-token', 'admin']);
    $authCookie = Defuse\Crypto\Crypto::encrypt(json_encode([
        'version' => 1, 'token' => 'admin-token', 'expires' => time() + 3600,
    ]), $key);
    $adminRequest = static function ($method, $path, $query = [], $body = [], $withCsrf = true) use (
        $kernel, $authCookie, $cookie, $token
    ) {
        return $kernel->handle(new Qubus\Http\ServerRequest(
            uri: 'http://localhost:8080' . $path, method: $method,
            headers: ['Accept' => 'application/json'], queryParams: $query,
            parsedBody: $withCsrf ? array_merge($body, ['_token' => $token]) : $body,
            cookies: ['USERSESSID' => $authCookie, 'CSRFSESSID' => $cookie]
        ));
    };
    $manager = $adminRequest('GET', '/admin/manager');
    $accountData = [
        'username' => 'new_user', 'first_name' => 'Alice', 'last_name' => 'Example',
        'email' => 'new@example.com', 'password' => 'a sufficiently long test password', 'role' => 'admin',
    ];
    $createdAccount = $adminRequest('POST', '/create/', [], $accountData);
    $eventCount = (int) $pdo->query('SELECT COUNT(*) FROM event_store')->fetchColumn();
    $adminRequest('POST', '/create/', [], $accountData);
    $duplicateAccountRolledBack = (int) $pdo->query('SELECT COUNT(*) FROM event_store')->fetchColumn() === $eventCount;
    $create = $adminRequest('POST', '/admin/manager', ['route' => 'page_settings', 'action' => 'create'], [
        'name' => 'Test page', 'layout' => 'main', 'show_in_nav' => '1', 'nav_position' => '1', 'nav_type' => 'page',
        'title' => ['en' => 'Test page'], 'meta_title' => ['en' => 'Test'],
        'meta_description' => ['en' => 'Test'], 'route' => ['en' => 'test-page'],
    ]);
    $page = new Vihzhuo\Repositories\PageRepository()->getAll()[0];
    $pageId = $page->getId();
    $editor = $adminRequest('GET', '/admin/manager/pagebuilder', ['page' => $pageId]);
    $editSettings = $adminRequest('GET', '/admin/manager', [
        'route' => 'page_settings', 'action' => 'edit', 'page' => $pageId,
    ]);
    $saveQuery = ['action' => 'store', 'page' => $pageId];
    $pageData = ['components' => [['type' => 'text', 'content' => '<iframe src="https://www.youtube.com/embed/test"></iframe>']]];
    $saveGet = $adminRequest('GET', '/admin/manager/pagebuilder', $saveQuery);
    $saveWithoutToken = $adminRequest('POST', '/admin/manager/pagebuilder', $saveQuery,
        ['data' => json_encode($pageData)], false);
    $savePost = $adminRequest('POST', '/admin/manager/pagebuilder', $saveQuery, ['data' => json_encode($pageData)]);
    $deleteQuery = ['route' => 'page_settings', 'action' => 'destroy', 'page' => $pageId];
    $deleteGet = $adminRequest('GET', '/admin/manager', $deleteQuery);
    $deleteWithoutToken = $adminRequest('POST', '/admin/manager', $deleteQuery, [], false);
    $pdo->exec("UPDATE users SET role = 'user' WHERE token = 'admin-token'");
    $deleteAsUser = $adminRequest('POST', '/admin/manager', $deleteQuery);
    $pageStillExists = new Vihzhuo\Repositories\PageRepository()->findWithId($pageId) !== null;
    $pdo->exec("UPDATE users SET role = 'admin' WHERE token = 'admin-token'");
    $deletePost = $adminRequest('POST', '/admin/manager', $deleteQuery);
    $logoutGet = $adminRequest('GET', '/logout/');
    $logoutWithoutToken = $adminRequest('POST', '/logout/', [], [], false);
    $logoutPost = $adminRequest('POST', '/logout/');
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $limited = $kernel->handle(new Qubus\Http\ServerRequest(
            uri: 'http://localhost:8080/auth/', method: 'POST',
            serverParams: ['REMOTE_ADDR' => '192.0.2.1'],
            headers: ['X-Forwarded-For' => '192.0.2.' . ($attempt + 2)],
            parsedBody: ['_token' => $token], cookies: ['CSRFSESSID' => $cookie]
        ));
    }
    echo json_encode([
        'manager' => $manager->getStatusCode(),
        'manager_csrf' => str_contains((string) $manager->getBody(), 'name="_token"'),
        'account_created' => $createdAccount->getStatusCode(),
        'registered_role' => $pdo->query("SELECT role FROM users WHERE email = 'new@example.com'")->fetchColumn(),
        'account_events' => $eventCount,
        'duplicate_account_rolled_back' => $duplicateAccountRolledBack,
        'create_page' => $create->getStatusCode(),
        'editor' => $editor->getStatusCode(),
        'editor_csrf' => str_contains((string) $editor->getBody(), 'const token = "' . $token . '"'),
        'edit_settings' => $editSettings->getStatusCode(),
        'edit_settings_csrf' => str_contains((string) $editSettings->getBody(), 'name="_token"'),
        'save_get' => $saveGet->getStatusCode(),
        'save_without_token' => $saveWithoutToken->getStatusCode(),
        'save_post' => $savePost->getStatusCode(),
        'delete_get' => $deleteGet->getStatusCode(),
        'delete_without_token' => $deleteWithoutToken->getStatusCode(),
        'delete_as_user' => $deleteAsUser->getStatusCode(),
        'page_preserved' => $pageStillExists,
        'delete_post' => $deletePost->getStatusCode(),
        'page_deleted' => new Vihzhuo\Repositories\PageRepository()->findWithId($pageId) === null,
        'logout_get' => $logoutGet->getStatusCode(),
        'logout_get_preserves_cookie' => !str_contains($logoutGet->getHeaderLine('Set-Cookie'), 'USERSESSID='),
        'logout_without_token' => $logoutWithoutToken->getStatusCode(),
        'logout_post' => $logoutPost->getStatusCode(),
        'logout_post_expires_cookie' => str_contains($logoutPost->getHeaderLine('Set-Cookie'), 'USERSESSID='),
        'limited' => $limited->getStatusCode(),
        'retry' => (int) $limited->getHeaderLine('Retry-After') > 0,
        'debugbar' => str_contains((string) $login->getBody(), 'phpdebugbar'),
        'login' => $login->getStatusCode(),
        'register' => $register->getStatusCode(),
        'missing' => $missing->getStatusCode(),
        'missing_options' => $missingOptions->getStatusCode(),
        'csrf' => preg_match('/name="_token" value="[a-f0-9]{64}"/', (string) $login->getBody()) === 1,
        'preflight' => $preflight->getStatusCode(),
        'firewall' => $blocked->getStatusCode(),
        'firewall_payload_logged' => str_contains($firewallLog, '127.0.0.1'),
        'context' => Codefy\Framework\Http\RequestContext::has(),
        'pdo' => $app->make(PDO::class) === $app->getDbConnection()->pdo,
    ]);
    PHP;
    $process = new Process([PHP_BINARY, '-r', $httpScript, $root], $this->testPath);
    expect($process->run())->toBe(0, $process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true))->toBe([
            'manager' => 200, 'manager_csrf' => true,
            'account_created' => 302, 'registered_role' => 'user', 'account_events' => 1,
            'duplicate_account_rolled_back' => true, 'create_page' => 302,
            'editor' => 200, 'editor_csrf' => true, 'edit_settings' => 200, 'edit_settings_csrf' => true,
            'save_get' => 405, 'save_without_token' => 412, 'save_post' => 200,
            'delete_get' => 405, 'delete_without_token' => 412, 'delete_as_user' => 302,
            'page_preserved' => true, 'delete_post' => 302, 'page_deleted' => true,
            'logout_get' => 200, 'logout_get_preserves_cookie' => true, 'logout_without_token' => 412,
            'logout_post' => 302, 'logout_post_expires_cookie' => true,
            'limited' => 429, 'retry' => true, 'debugbar' => false,
            'login' => 200, 'register' => 200, 'missing' => 404, 'missing_options' => 404,
            'csrf' => true, 'preflight' => 204, 'firewall' => 403,
            'firewall_payload_logged' => false, 'context' => false, 'pdo' => true,
        ]);
});
