<?php

use App\Services\Money\Allocator;

it('splits 100 across three equal shares as 34, 33, 33', function () {
    expect(Allocator::allocate(100, [1 => 1, 2 => 1, 3 => 1]))->toBe([1 => 34, 2 => 33, 3 => 33]);
});

it('gives leftover units to the largest remainders, not always to the first share', function () {
    // exact shares 1.1, 1.1, 8.8: the one leftover unit goes to the .8 remainder
    expect(Allocator::allocate(11, [1 => 1, 2 => 1, 3 => 8]))->toBe([1 => 1, 2 => 1, 3 => 9]);
});

it('breaks remainder ties by input order so the same input always gives the same output', function () {
    $first = Allocator::allocate(101, [7 => 1, 3 => 1, 5 => 1, 9 => 1]);
    $second = Allocator::allocate(101, [7 => 1, 3 => 1, 5 => 1, 9 => 1]);

    expect($first)->toBe([7 => 26, 3 => 25, 5 => 25, 9 => 25])->and($second)->toBe($first);
});

it('splits by 50/30/20 basis points exactly', function () {
    expect(Allocator::allocate(80_000, [1 => 5_000, 2 => 3_000, 3 => 2_000]))->toBe([1 => 40_000, 2 => 24_000, 3 => 16_000]);
});

it('never loses or creates a minor unit, whatever the weights', function () {
    mt_srand(42);

    for ($i = 0; $i < 500; $i++) {
        $amount = mt_rand(0, 10_000_000);
        $weights = [];
        for ($k = 1, $n = mt_rand(1, 8); $k <= $n; $k++) {
            $weights[$k] = mt_rand(0, 10_000);
        }
        $weights[1] += 1;

        expect(array_sum(Allocator::allocate($amount, $weights)))->toBe($amount);
    }
});

it('spreads an annual amount evenly across twelve months', function () {
    expect(Allocator::evenly(1_200_000, 12))->toBe(array_fill(0, 12, 100_000))
        ->and(Allocator::evenly(100, 12))->toBe([9, 9, 9, 9, 8, 8, 8, 8, 8, 8, 8, 8])
        ->and(array_sum(Allocator::evenly(100, 12)))->toBe(100);
});

it('floors percentages in integer arithmetic', function () {
    expect(Allocator::percentage(125, 2_000))->toBe(25)
        ->and(Allocator::percentage(99, 2_000))->toBe(19)
        ->and(Allocator::percentage(300_000, 2_000))->toBe(60_000);
});

it('rejects impossible allocations', function (int $amount, array $weights) {
    Allocator::allocate($amount, $weights);
})->throws(InvalidArgumentException::class)->with([
    'negative amount' => [-1, [1 => 1]],
    'no parts' => [100, []],
    'all zero weights' => [100, [1 => 0, 2 => 0]],
    'negative weight' => [100, [1 => 2, 2 => -1]],
]);

it('formats minor units for humans', function () {
    expect(Allocator::format(123_456, 'EGP'))->toBe('EGP 1,234.56')
        ->and(Allocator::format(-50_000, 'EGP'))->toBe('-EGP 500.00');
});
