<?php

declare(strict_types=1);

use Codefy\Framework\Auth\Rbac\Exception\UnauthorizedException;
use Codefy\Framework\Support\Password;
use Domain\User\Dto\StoreUserData;
use Domain\User\Validator\RegisterUserValidator;
use Domain\User\Validator\StoreUserValidator;
use Domain\User\Validator\UpdateProfileValidator;
use Domain\User\Validator\UpdateUserPasswordValidator;
use Qubus\Http\ServerRequest;
use Qubus\Validation\ValidationException;

it('creates a registration DTO from accepted fields and assigns the regular role', function () {
    $validator = RegisterUserValidator::make(new ServerRequest(
        method: 'POST',
        parsedBody: userInput(['is_admin' => true])
    ));
    $data = $validator->validated();
    expect($data)->not->toHaveKeys(['is_admin', 'user_id'])
        ->and($data['username'])->toBe('example_user')
        ->and($data['password'])->toBe(userInput()['password'])
        ->and($data['role'])->toBe('user');
    $dto = $validator->toDto();
    expect($dto)->toBeInstanceOf(StoreUserData::class)
        ->and((string) $dto->role)->toBe('user')
        ->and(Password::verify(userInput()['password'], (string) $dto->password))->toBeTrue();
});

it('rejects missing and invalid creation credentials', function (array $fields) {
    $validator = RegisterUserValidator::make(new ServerRequest(method: 'POST', parsedBody: userInput($fields)));
    expect(fn() => $validator->validated())->toThrow(ValidationException::class);
})->with([
    [['username' => '']],
    [['username' => 'bad name']],
    [['password' => '']],
    [['password' => 'short']],
    [['password' => ['array']]],
]);

it('requires administrator permission for administrative user creation', function () {
    $validator = StoreUserValidator::make(new ServerRequest(method: 'POST', parsedBody: userInput()));
    expect(fn() => $validator->validated())->toThrow(UnauthorizedException::class);
    $this->gate->shouldReceive('can')->with('admin:create:user', [])->andReturn(true);
    expect($validator->validated()['role'])->toBe('admin');
});

it('binds profile changes to the authenticated identity and stored role', function () {
    $this->gate->shouldReceive('can')->with('admin:profile', [])->andReturn(true);
    $this->gate->shouldReceive('current')->andReturn((object) [
        'user_id' => userInput()['user_id'], 'role' => 'user',
    ]);
    $validator = UpdateProfileValidator::make(new ServerRequest(method: 'PUT', parsedBody: userInput([
        'user_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'role' => 'admin',
    ])));
    $dto = $validator->toDto();
    expect((string) $dto->userId)->toBe(userInput()['user_id'])
        ->and((string) $dto->role)->toBe('user')
        ->and($validator->validated())->not->toHaveKeys(['username', 'password']);
});

it('binds password changes to the signed-in user and requires confirmation', function () {
    $this->gate->shouldReceive('can')->with('admin:profile', [])->andReturn(true);
    $this->gate->shouldReceive('current')->andReturn((object) ['user_id' => userInput()['user_id']]);
    $input = userInput(['user_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW']);
    $validator = UpdateUserPasswordValidator::make(new ServerRequest(method: 'PUT', parsedBody: $input));
    expect(fn() => $validator->validated())->toThrow(ValidationException::class);
    $input['confirm_password'] = $input['password'];
    $dto = UpdateUserPasswordValidator::make(new ServerRequest(method: 'PUT', parsedBody: $input))->toDto();
    expect((string) $dto->userId)->toBe(userInput()['user_id'])
        ->and(Password::verify($input['password'], (string) $dto->password))->toBeTrue();
});
