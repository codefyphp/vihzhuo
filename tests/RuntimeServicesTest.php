<?php

declare(strict_types=1);

use Application\Provider\AppServiceProvider;
use Codefy\Framework\Pipeline\Pipeline;
use Codefy\Framework\Providers\DatabaseConnectionServiceProvider;
use Codefy\Framework\Providers\PdoServiceProvider;
use Codefy\Framework\Scheduler\Mutex\FileLocker;
use Codefy\Framework\Scheduler\Mutex\Locker;
use Codefy\Framework\Scheduler\Processor\Processor;

it('rolls back pipeline work on the same PDO connection supplied to application services', function () {
    $this->config->setConfigKey('database', [
        'default' => 'sqlite',
        'connections' => ['sqlite' => ['driver' => 'sqlite', 'dsn' => 'sqlite::memory:']],
    ]);
    new DatabaseConnectionServiceProvider($this->app)->register();
    new PdoServiceProvider($this->app)->register();
    $pdo = $this->app->make(PDO::class);
    expect($pdo)->toBe($this->app->getDbConnection()->pdo);
    $pdo->exec('CREATE TABLE pipeline_work (value TEXT)');
    $pipeline = new Pipeline($this->app)->withTransaction();
    expect(fn() => $pipeline->send('work')->then(function () use ($pdo) {
        $pdo->exec("INSERT INTO pipeline_work VALUES ('uncommitted')");
        throw new RuntimeException('Abort transaction');
    }))->toThrow(RuntimeException::class);
    expect($pdo->query('SELECT COUNT(*) FROM pipeline_work')->fetchColumn())->toBe(0)
        ->and($pdo->inTransaction())->toBeFalse();
});

it('registers a local scheduler locker that prevents a second worker from acquiring a held lock', function () {
    new AppServiceProvider($this->app)->register();
    $locker = $this->app->make(Locker::class);
    expect($locker)->toBeInstanceOf(FileLocker::class);
    $other = new FileLocker($this->testPath . '/storage/scheduler-locks');
    $processor = Mockery::mock(Processor::class);
    $processor->shouldReceive('mutexName')->andReturn('test-schedule');
    expect($locker->tryLock($processor))->toBeTrue()
        ->and($other->tryLock($processor))->toBeFalse()
        ->and($locker->unlock($processor))->toBeTrue()
        ->and($other->tryLock($processor))->toBeTrue();
    $other->unlock($processor);
});
