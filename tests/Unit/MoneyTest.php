<?php

use App\Domain\Money\Money;

it('never uses floating point for arithmetic', function () {
    $money = Money::of(199, 'EGP');

    expect($money->shareBps(7000)->cents)->toBe(139);
});

it('keeps the platform remainder so pool + cut === original', function () {
    $gross = Money::of(100, 'EGP');
    $pool = $gross->shareBps(7000);

    expect($pool->cents)->toBe(70);
    expect($gross->subtract($pool)->cents)->toBe(30);
});

it('splits leftover cents with the largest remainder method', function () {
    $shares = Money::of(70, 'EGP')->splitByWeights([1 => 1, 2 => 1, 3 => 1]);

    expect($shares[1]->cents + $shares[2]->cents + $shares[3]->cents)->toBe(70);
    expect(max($shares[1]->cents, $shares[2]->cents, $shares[3]->cents))->toBe(24);
    expect(min($shares[1]->cents, $shares[2]->cents, $shares[3]->cents))->toBe(23);
});

it('is deterministic when remainders tie: lower id wins the extra cent', function () {
    $shares = Money::of(70, 'EGP')->splitByWeights([10 => 1, 20 => 1, 30 => 1]);

    expect($shares[10]->cents)->toBe(24);
    expect($shares[20]->cents)->toBe(23);
    expect($shares[30]->cents)->toBe(23);
});

it('weights a 2:1 split exactly', function () {
    $shares = Money::of(70, 'EGP')->splitByWeights([1 => 2, 2 => 1]);

    expect($shares[1]->cents)->toBe(47);
    expect($shares[2]->cents)->toBe(23);
});

it('formats negative balances for clawbacks', function () {
    expect(Money::of(-3500, 'EGP')->format())->toBe('-35.00 EGP');
});

it('rejects mixed currencies', function () {
    Money::of(1, 'EGP')->add(Money::of(1, 'USD'));
})->throws(InvalidArgumentException::class);
