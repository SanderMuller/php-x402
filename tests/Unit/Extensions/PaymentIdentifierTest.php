<?php

declare(strict_types=1);

use X402\Extensions\PaymentIdentifier;

it('generates a valid pay_-prefixed 36-char identifier', function (): void {
    $id = PaymentIdentifier::generate();

    expect($id)->toStartWith('pay_')
        ->and(strlen($id))->toBe(36)
        ->and(PaymentIdentifier::isValid($id))->toBeTrue();
});

it('isValid rejects ids shorter than 16 chars', function (): void {
    expect(PaymentIdentifier::isValid(str_repeat('a', 15)))->toBeFalse();
});

it('isValid accepts ids at the 16-char boundary', function (): void {
    expect(PaymentIdentifier::isValid(str_repeat('a', 16)))->toBeTrue();
});

it('isValid accepts ids at the 128-char boundary', function (): void {
    expect(PaymentIdentifier::isValid(str_repeat('a', 128)))->toBeTrue();
});

it('isValid rejects ids longer than 128 chars', function (): void {
    expect(PaymentIdentifier::isValid(str_repeat('a', 129)))->toBeFalse();
});

it('isValid accepts hyphen and underscore characters', function (): void {
    expect(PaymentIdentifier::isValid('pay_id-with-hyphens_and_under'))->toBeTrue();
});

it('isValid rejects whitespace', function (): void {
    expect(PaymentIdentifier::isValid('pay_id with whitespace_xx'))->toBeFalse();
});

it('isValid rejects punctuation like dot', function (): void {
    expect(PaymentIdentifier::isValid('pay_id.with.dots.xxxxxxxxx'))->toBeFalse();
});

it('paymentExtension throws on invalid id', function (): void {
    PaymentIdentifier::paymentExtension('too-short');
})->throws(InvalidArgumentException::class, 'is invalid');

it('paymentExtension wraps a valid id in the wire envelope', function (): void {
    $id = PaymentIdentifier::generate();
    $envelope = PaymentIdentifier::paymentExtension($id);

    expect($envelope)->toHaveKey(PaymentIdentifier::EXTENSION_KEY)
        ->and($envelope[PaymentIdentifier::EXTENSION_KEY]['info']['id'])->toBe($id);
});

it('challengeExtension carries the required flag through (true)', function (): void {
    $envelope = PaymentIdentifier::challengeExtension(required: true);

    expect($envelope[PaymentIdentifier::EXTENSION_KEY]['info']['required'])->toBeTrue();
});

it('challengeExtension carries the required flag through (false)', function (): void {
    $envelope = PaymentIdentifier::challengeExtension(required: false);

    expect($envelope[PaymentIdentifier::EXTENSION_KEY]['info']['required'])->toBeFalse();
});

it('challengeExtension embeds a JSON Schema descriptor with id length bounds', function (): void {
    // Schema is a literal in the source; serialize-and-string-match keeps the
    // assertion meaningful without dragging mixed-type narrowing into tests.
    $envelope = PaymentIdentifier::challengeExtension();
    $json = json_encode($envelope[PaymentIdentifier::EXTENSION_KEY]['schema'], JSON_THROW_ON_ERROR);

    expect($json)->toContain('"type":"object"')
        ->and($json)->toContain('"minLength":16')
        ->and($json)->toContain('"maxLength":128');
});

it('extractId returns null for null extensions', function (): void {
    expect(PaymentIdentifier::extractId(null))->toBeNull();
});

it('extractId returns null when the extension key is missing', function (): void {
    expect(PaymentIdentifier::extractId(['some-other-extension' => []]))->toBeNull();
});

it('extractId returns null when the extension entry is not an array', function (): void {
    expect(PaymentIdentifier::extractId([PaymentIdentifier::EXTENSION_KEY => 'not-an-array']))->toBeNull();
});

it('extractId returns null when info is missing', function (): void {
    expect(PaymentIdentifier::extractId([PaymentIdentifier::EXTENSION_KEY => []]))->toBeNull();
});

it('extractId returns null when info is not an array', function (): void {
    expect(PaymentIdentifier::extractId([PaymentIdentifier::EXTENSION_KEY => ['info' => 'nope']]))->toBeNull();
});

it('extractId returns null when info.id is not a string', function (): void {
    expect(PaymentIdentifier::extractId([PaymentIdentifier::EXTENSION_KEY => ['info' => ['id' => 123]]]))->toBeNull();
});

it('extractId returns null when info.id fails isValid (too short)', function (): void {
    expect(PaymentIdentifier::extractId([PaymentIdentifier::EXTENSION_KEY => ['info' => ['id' => 'short']]]))->toBeNull();
});

it('extractId returns the id when present and valid', function (): void {
    $id = PaymentIdentifier::generate();

    expect(PaymentIdentifier::extractId([PaymentIdentifier::EXTENSION_KEY => ['info' => ['id' => $id]]]))->toBe($id);
});

it('isRequired returns false for null extensions', function (): void {
    expect(PaymentIdentifier::isRequired(null))->toBeFalse();
});

it('isRequired returns false when the extension key is missing', function (): void {
    expect(PaymentIdentifier::isRequired(['unrelated' => []]))->toBeFalse();
});

it('isRequired returns false when info is missing', function (): void {
    expect(PaymentIdentifier::isRequired([PaymentIdentifier::EXTENSION_KEY => []]))->toBeFalse();
});

it('isRequired returns false when info is not an array', function (): void {
    expect(PaymentIdentifier::isRequired([PaymentIdentifier::EXTENSION_KEY => ['info' => 'not-array']]))->toBeFalse();
});

it('isRequired returns false when required is not strictly true', function (): void {
    expect(PaymentIdentifier::isRequired([PaymentIdentifier::EXTENSION_KEY => ['info' => ['required' => 1]]]))->toBeFalse()
        ->and(PaymentIdentifier::isRequired([PaymentIdentifier::EXTENSION_KEY => ['info' => ['required' => 'true']]]))->toBeFalse();
});

it('isRequired returns true only when required is strictly true', function (): void {
    expect(PaymentIdentifier::isRequired([PaymentIdentifier::EXTENSION_KEY => ['info' => ['required' => true]]]))->toBeTrue();
});
