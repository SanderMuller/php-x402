<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use InvalidArgumentException;

/**
 * Brute-force the recovery-id byte for a `(digest, r, s)` triple by
 * recovering the public key under each candidate v ∈ {27, 28} and
 * matching the derived address to the expected signer.
 *
 * KMS providers (AWS, GCP, Azure) emit `r || s` without a recovery byte —
 * the secp256k1 spec doesn't include one, and the EVM v byte is an
 * Ethereum-specific extension. Reach for this helper from any KMS-backed
 * `Wallet` implementation; pure-PHP signers like `PrivateKeyWallet` get the
 * recovery id directly from simplito/elliptic-php and don't need it.
 */
final class EcdsaRecovery
{
    /**
     * Try both candidate recovery-id bytes against `expectedAddress` and
     * return the v byte (27 or 28) whose recovered public key matches.
     *
     * @param  string  $digest          0x-prefixed 32-byte hex digest.
     * @param  string  $rHex            64-char hex (no 0x).
     * @param  string  $sHex            64-char hex (no 0x).
     * @param  string  $expectedAddress 0x-prefixed 20-byte EVM address (case-insensitive).
     */
    public static function deriveV(string $digest, string $rHex, string $sHex, string $expectedAddress): int
    {
        $verifier = new SignatureVerifier();
        $expected = strtolower($expectedAddress);

        foreach ([27, 28] as $v) {
            $candidate = '0x' . $rHex . $sHex . dechex($v);

            try {
                $recovered = $verifier->recover($digest, $candidate);
            } catch (InvalidArgumentException) {
                continue;
            }

            if (strtolower($recovered) === $expected) {
                return $v;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Could not recover the expected address %s from the given (digest, r, s) triple — KMS signature does not belong to the claimed signer.',
            $expectedAddress,
        ));
    }
}
