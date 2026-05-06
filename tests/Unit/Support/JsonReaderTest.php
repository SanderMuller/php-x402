<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Support\JsonReader;

it('reads a string field', function (): void {
    expect(JsonReader::string(['scheme' => 'exact'], 'scheme'))->toBe('exact');
});

it('coerces int and float fields to string', function (): void {
    expect(JsonReader::string(['value' => 10000], 'value'))->toBe('10000')
        ->and(JsonReader::string(['ratio' => 0.5], 'ratio'))->toBe('0.5');
});

it('throws when a required string field is missing', function (): void {
    JsonReader::string([], 'scheme', 'Test');
})->throws(InvalidPaymentException::class, 'missing required field "scheme"');

it('throws when a string field is the wrong type', function (): void {
    JsonReader::string(['scheme' => true], 'scheme', 'Test');
})->throws(InvalidPaymentException::class, 'expected string, got bool');

it('returns null for missing or null string optionals', function (): void {
    expect(JsonReader::stringOrNull(['x' => null], 'x'))->toBeNull()
        ->and(JsonReader::stringOrNull([], 'x'))->toBeNull();
});

it('returns null when stringOrNull encounters a non-string value', function (): void {
    expect(JsonReader::stringOrNull(['x' => 42], 'x'))->toBeNull();
});

it('reads an int field directly', function (): void {
    expect(JsonReader::int(['t' => 60], 't'))->toBe(60);
});

it('coerces a numeric string to int', function (): void {
    expect(JsonReader::int(['t' => '60'], 't'))->toBe(60)
        ->and(JsonReader::int(['t' => '-1'], 't'))->toBe(-1);
});

it('throws on a non-numeric string for int', function (): void {
    JsonReader::int(['t' => 'sixty'], 't');
})->throws(InvalidPaymentException::class, 'expected int, got string');

it('uses the int default when the key is absent', function (): void {
    expect(JsonReader::int([], 't', default: 42))->toBe(42);
});

it('throws when int has no default and the key is absent', function (): void {
    JsonReader::int([], 't');
})->throws(InvalidPaymentException::class, 'Missing required field "t"');

it('reads a nested array', function (): void {
    $auth = JsonReader::array(['authorization' => ['from' => '0xabc']], 'authorization');

    expect($auth)->toBe(['from' => '0xabc']);
});

it('throws when an array field is missing', function (): void {
    JsonReader::array([], 'authorization', 'Test');
})->throws(InvalidPaymentException::class, 'missing required field "authorization"');

it('throws when an array field is the wrong type', function (): void {
    JsonReader::array(['authorization' => 'not an array'], 'authorization', 'Test');
})->throws(InvalidPaymentException::class, 'expected array, got string');

it('returns empty for arrayOrEmpty when key is missing or non-array', function (): void {
    expect(JsonReader::arrayOrEmpty([], 'x'))->toBe([])
        ->and(JsonReader::arrayOrEmpty(['x' => 'string'], 'x'))->toBe([])
        ->and(JsonReader::arrayOrEmpty(['x' => null], 'x'))->toBe([]);
});

it('returns the array when arrayOrEmpty hits a real array', function (): void {
    expect(JsonReader::arrayOrEmpty(['x' => ['a' => 1]], 'x'))->toBe(['a' => 1]);
});

it('includes the context prefix in error messages when supplied', function (): void {
    JsonReader::string([], 'scheme', 'EVM exact payload');
})->throws(InvalidPaymentException::class, 'EVM exact payload: missing required field "scheme"');
