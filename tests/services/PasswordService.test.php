<?php

use App\Models\User;
use App\Services\PasswordService;

test('one-time passwords are hashed with Argon2id and discarded once used', function () {
    $user = new User();
    $this->passwords->setOneTimePassword($user, 'hand-over-1');

    expect($user->password)->toStartWith('$argon2id$')
        ->and($user->must_change_password)->toBeTrue()
        ->and($this->passwords->verify($user, 'hand-over-1'))->toBeTrue()
        ->and($this->passwords->verify($user, 'wrong'))->toBeFalse();

    $this->passwords->discard($user);

    expect($user->password)->toBeNull()
        ->and($user->must_change_password)->toBeFalse()
        ->and($this->passwords->verify($user, 'hand-over-1'))->toBeFalse();
});

test('one-time passwords need a minimum length and must not look like a code', function () {
    expect($this->passwords->policyError('short'))->toContain((string) PasswordService::MIN_LENGTH)
        ->and($this->passwords->policyError('12345678'))->toBeNull()
        ->and($this->passwords->policyError('changeme'))->toBeNull()
        ->and(PasswordService::MIN_LENGTH)->toBeGreaterThan(6);
});
