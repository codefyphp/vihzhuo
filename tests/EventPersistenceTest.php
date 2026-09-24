<?php

declare(strict_types=1);

use Application\Service\DatabaseService;
use Codefy\Domain\Aggregate\RecordsEvents;
use Codefy\Domain\EventSourcing\AggregateChanged;
use Codefy\Domain\EventSourcing\DomainEvents;
use Codefy\Domain\Metadata;
use Domain\User\Service\UserProjection;
use Domain\User\ValueObject\UserId;
use Infrastructure\Persistence\PdoTransactionalEventStore;
use Infrastructure\Persistence\Repository\EventSourcedUserRepository;
use Qubus\Expressive\Connection\DriverConnection;

beforeEach(function () {
    $this->connection = DriverConnection::make(['driver' => 'sqlite', 'dsn' => 'sqlite::memory:']);
    $this->db = new DatabaseService($this->connection);
    $this->pdo = $this->connection->pdo;
    $this->pdo->exec('CREATE TABLE event_store (
        event_id TEXT PRIMARY KEY, transaction_id TEXT, event_type TEXT, event_classname TEXT,
        payload TEXT, metadata TEXT, aggregate_id TEXT, aggregate_type TEXT,
        aggregate_playhead INTEGER, recorded_at TEXT, UNIQUE(aggregate_id, aggregate_playhead)
    )');
    $this->store = new PdoTransactionalEventStore($this->db);
    $this->userId = new UserId();
    $this->events = array_map(fn($playhead) => AggregateChanged::occur($this->userId, ['value' => $playhead], [
        Metadata::AGGREGATE_TYPE => 'user', Metadata::AGGREGATE_PLAYHEAD => $playhead,
    ]), [1, 2, 3]);
});

it('rolls back the entire event batch when a later insert fails', function () {
    expect(fn() => $this->store->commit($this->events[0], $this->events[1], $this->events[0]))
        ->toThrow(Exception::class);
    expect($this->pdo->query('SELECT COUNT(*) FROM event_store')->fetchColumn())->toBe(0)
        ->and($this->pdo->inTransaction())->toBeFalse();
    $this->store->commit(...$this->events);
    expect($this->pdo->query('SELECT COUNT(*) FROM event_store')->fetchColumn())->toBe(3);
});

it('loads every event from the requested playhead in chronological order', function () {
    $this->store->commit($this->events[2], $this->events[0], $this->events[1]);
    $playheads = fn($stream) => array_map(fn($event) => $event->playhead(), iterator_to_array($stream));
    expect($playheads($this->store->getAggregateHistoryFor($this->userId)))->toBe([1, 2, 3])
        ->and($playheads($this->store->loadFromPlayhead($this->userId, 2)))->toBe([2, 3]);
});

it('rejects unencodable event payloads without persisting a partial batch', function () {
    $invalid = AggregateChanged::occur($this->userId, ['value' => "\xFF"], [
        Metadata::AGGREGATE_TYPE => 'user', Metadata::AGGREGATE_PLAYHEAD => 2,
    ]);
    expect(fn() => $this->store->commit($this->events[0], $invalid))->toThrow(JsonException::class)
        ->and($this->pdo->query('SELECT COUNT(*) FROM event_store')->fetchColumn())->toBe(0);
});

it('rolls back events and projection changes together without clearing pending events', function () {
    $this->pdo->exec('CREATE TABLE projection (value TEXT)');
    $aggregate = Mockery::mock(RecordsEvents::class);
    $aggregate->shouldReceive('aggregateId')->andReturn($this->userId);
    $aggregate->shouldReceive('getRecordedEvents')->andReturn(DomainEvents::fromArray($this->events));
    $aggregate->shouldNotReceive('clearRecordedEvents');
    $projection = Mockery::mock(UserProjection::class);
    $projection->shouldReceive('project')->once()->andReturnUsing(function () {
        $this->db->transactional(fn() => $this->pdo->exec("INSERT INTO projection VALUES ('partial')"));
        throw new RuntimeException('Projection failed');
    });
    $repository = new EventSourcedUserRepository($this->store, $projection, $this->db);
    expect(fn() => $repository->saveAggregateRoot($aggregate))->toThrow(RuntimeException::class)
        ->and($this->pdo->query('SELECT COUNT(*) FROM event_store')->fetchColumn())->toBe(0)
        ->and($this->pdo->query('SELECT COUNT(*) FROM projection')->fetchColumn())->toBe(0)
        ->and($this->pdo->inTransaction())->toBeFalse();
});
