<?php

use Nepicsoft\Osync\Hlc;

it('round-trips through its string form', function () {
    $hlc = new Hlc(1_730_000_000_123, 7, 'phone-a');

    expect((string) $hlc)->toBe('001730000000123:00007:phone-a')
        ->and(Hlc::parse((string) $hlc))->toEqual($hlc);
});

it('orders as strings the way it orders as a clock', function () {
    $stamps = [
        new Hlc(5, 0, 'b'),
        new Hlc(10_000, 0, 'a'),
        new Hlc(5, 1, 'a'),
        new Hlc(5, 0, 'a'),
    ];
    $strings = array_map('strval', $stamps);
    sort($strings);

    expect($strings)->toBe([
        '000000000000005:00000:a',
        '000000000000005:00000:b',
        '000000000000005:00001:a',
        '000000000010000:00000:a',
    ]);
});

it('stays ahead of a stamp from a clock running fast', function () {
    $fast = new Hlc(2_000, 3, 'phone');

    expect(Hlc::next('server', 1_000, $fast)->isAfter($fast))->toBeTrue()
        ->and(Hlc::next('server', 3_000, $fast))->toEqual(new Hlc(3_000, 0, 'server'));
});

it('rejects malformed stamps', function (string $value) {
    expect(Hlc::isValid($value))->toBeFalse();
    Hlc::parse($value);
})->with(['', '1:2:a', '000000000000005:00000:', '000000000000005:00000:a b'])
    ->throws(InvalidArgumentException::class);
