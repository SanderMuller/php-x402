<?php

declare(strict_types=1);

use X402\Webhook\InvalidWebhookSignatureException;
use X402\Webhook\SignatureVerifier;

function signedHeader(string $secret, string $body, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $hex = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return 't=' . $timestamp . ',v1=' . $hex;
}

it('accepts a freshly signed payload', function (): void {
    $verifier = new SignatureVerifier(secret: 'whsec_secret');
    $body = '{"event":"settled"}';

    $verifier->verify($body, signedHeader('whsec_secret', $body));

    expect(true)->toBeTrue();
});

it('rejects a payload signed by a different secret', function (): void {
    $verifier = new SignatureVerifier(secret: 'whsec_secret');
    $body = '{"event":"settled"}';

    $verifier->verify($body, signedHeader('whsec_other', $body));
})->throws(InvalidWebhookSignatureException::class, 'mismatch');

it('rejects a payload outside the clock-skew window', function (): void {
    $verifier = new SignatureVerifier(secret: 'whsec_secret', maxClockSkewSeconds: 60);
    $body = '{"event":"settled"}';

    $verifier->verify($body, signedHeader('whsec_secret', $body, time() - 600));
})->throws(InvalidWebhookSignatureException::class, 'clock-skew');

it('rejects a malformed signature header missing v1', function (): void {
    $verifier = new SignatureVerifier(secret: 's');

    $verifier->verify('{}', 't=' . time());
})->throws(InvalidWebhookSignatureException::class, 'malformed');

it('rejects a malformed signature header missing t', function (): void {
    $verifier = new SignatureVerifier(secret: 's');

    $verifier->verify('{}', 'v1=deadbeef');
})->throws(InvalidWebhookSignatureException::class, 'malformed');

it('rejects an empty signature header', function (): void {
    $verifier = new SignatureVerifier(secret: 's');

    $verifier->verify('{}', '');
})->throws(InvalidWebhookSignatureException::class, 'empty');

it('rejects a signature header whose timestamp is not pure digits', function (): void {
    $verifier = new SignatureVerifier(secret: 's');

    // Crafted suffix would silently coerce via (int) cast otherwise.
    $verifier->verify('{}', 't=12abc,v1=deadbeef');
})->throws(InvalidWebhookSignatureException::class, 'integer');

it('uses constant-time comparison via hash_equals', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../../../src/Webhook/SignatureVerifier.php');

    expect($source)->toContain('hash_equals(');
});

it('rejects a tampered body even if the signature is otherwise valid for a different body', function (): void {
    $verifier = new SignatureVerifier(secret: 'whsec_secret');
    $signedBody = '{"event":"settled"}';
    $header = signedHeader('whsec_secret', $signedBody);

    // Same signature, different body — should fail.
    $verifier->verify('{"event":"cancelled"}', $header);
})->throws(InvalidWebhookSignatureException::class, 'mismatch');
