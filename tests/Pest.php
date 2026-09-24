<?php

declare(strict_types=1);

use Codefy\Framework\Application;
use Codefy\Framework\Auth\Gate;
use Codefy\Framework\Http\RequestContext;
use Defuse\Crypto\Key;
use Gettext\Translator;
use Gettext\TranslatorFunctions;

pest()->beforeEach(function () {
    $this->testPath = sys_get_temp_dir() . '/codefy-skeleton-' . bin2hex(random_bytes(8));
    mkdir($this->testPath . '/config', 0700, true);
    foreach (glob(dirname(__DIR__) . '/config/*.php') as $file) {
        copy($file, $this->testPath . '/config/' . basename($file));
    }
    foreach (['.env', '.env.local', '.env.staging', '.env.development', '.env.production'] as $name) {
        touch($this->testPath . '/' . $name);
    }
    TranslatorFunctions::register(new Translator());
    $this->app = new Application(['basePath' => $this->testPath]);
    $this->config = $this->app->configContainer;
    $this->config->setConfigKey('app', [
        'crypto_key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
        'debug' => false,
    ]);
    $this->config->setConfigKey('cookies', ['secure' => false, 'domain' => '']);
    $this->gate = Mockery::mock(Gate::class);
    $this->gate->shouldReceive('can')->withAnyArgs()->andReturn(false)->byDefault();
    $this->gate->shouldReceive('current')->andReturn(false)->byDefault();
    $this->app->share($this->gate);
    $this->app->alias(Gate::class, $this->gate::class);
})->afterEach(function () {
    RequestContext::clear();
    Mockery::close();
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->testPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->testPath);
})->in('.');

function userInput(array $overrides = []): array
{
    return array_replace([
        'username' => 'example_user',
        'first_name' => 'Alice',
        'last_name' => 'Example',
        'email' => 'alice@example.com',
        'password' => 'a sufficiently long test password',
        'role' => 'admin',
        'user_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ], $overrides);
}
