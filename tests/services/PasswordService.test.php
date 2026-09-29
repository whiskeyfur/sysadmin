<?php

use App\Models\User;
use App\Services\PasswordService;

test('passwords are hashed with Argon2id', function () {
    $user = new User();
    $this->passwords->setChosenPassword($user, 'correct-horse-1');

    expect($user->password)->toStartWith('$argon2id$')
        ->and($this->passwords->verify($user, 'correct-horse-1'))->toBeTrue()
        ->and($this->passwords->verify($user, 'wrong'))->toBeFalse();
});

test('the policy needs 12 characters and a change', function () {
    expect($this->passwords->policyError('short'))->toContain((string) PasswordService::MIN_LENGTH)
        ->and($this->passwords->policyError('long-enough-1', 'long-enough-1'))->toContain('different')
        ->and($this->passwords->policyError('long-enough-1', 'something-else'))->toBeNull();
});

test('temporary passwords count as expired and must be changed', function () {
    $user = new User();
    $this->passwords->setTemporaryPassword($user, 'temp');

    expect($user->must_change_password)->toBeTrue()
        ->and($this->passwords->isExpired($user))->toBeTrue();
});

test('chosen passwords expire exactly after 30 days', function () {
    $user = new User();
    $this->passwords->setChosenPassword($user, 'correct-horse-1');
    $this->clock->advance(PasswordService::MAX_AGE_DAYS * 86400 - 1);

    expect($this->passwords->isExpired($user))->toBeFalse();

    $this->clock->advance(1);

    expect($this->passwords->isExpired($user))->toBeTrue();
});
