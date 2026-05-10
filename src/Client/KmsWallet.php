<?php

declare(strict_types=1);

namespace X402\Client;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;
use Throwable;
use X402\Schemes\Evm\EcdsaRecovery;
use X402\Support\Asn1DerDecoder;
use X402\Support\Hex;

/**
 * Abstract `Wallet` for KMS-backed signers (AWS KMS, GCP Cloud KMS, Azure
 * Key Vault, HashiCorp Vault, HSM bridges, remote signers). Subclasses
 * provide two operations:
 *
 *   - `fetchPublicKeySpki()` — return the binary `SubjectPublicKeyInfo`
 *     DER blob the KMS hands back for the configured key.
 *   - `rawSign(string $digestBytes)` — return the binary DER signature
 *     `SEQUENCE { r INTEGER, s INTEGER }` the KMS produces for the
 *     32-byte digest.
 *
 * The abstract owns the three rules from `docs/kms.md`:
 *
 *   1. Address derivation from the SPKI is memoised — one network round
 *      trip per wallet, never one per `address()` call.
 *   2. DER → raw `(r, s)` conversion via `Asn1DerDecoder`.
 *   3. Low-s normalisation per EIP-2 + recovery-id brute force via
 *      `EcdsaRecovery::deriveV`.
 *
 * Output is always EIP-2 canonical (low-s). `PrivateKeyWallet` and
 * `HdWallet` rely on simplito's `canonical: true` flag, which the
 * library's option dispatch occasionally drops on the floor — those two
 * paths therefore agree on recovery (the produced address) but not always
 * on the raw `s` byte. If you need a third party to verify a byte-exact
 * canonical signature, use `KmsWallet` (or any of its subclasses).
 */
abstract class KmsWallet implements Wallet
{
    /** secp256k1 curve order (n). */
    private const SECP256K1_N_HEX = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /** Half of n — the EIP-2 low-s threshold. */
    private const SECP256K1_HALF_N_HEX = '7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0';

    private ?string $cachedAddress = null;

    /**
     * Return the binary `SubjectPublicKeyInfo` DER blob carrying the
     * uncompressed secp256k1 point for this wallet's key. AWS KMS:
     * `$client->getPublicKey(['KeyId' => $id])->get('PublicKey')`.
     */
    abstract protected function fetchPublicKeySpki(): string;

    /**
     * Sign the 32-byte digest at the KMS and return the binary DER blob
     * the provider hands back. AWS KMS: `$client->sign([...])->get('Signature')`
     * with `MessageType=DIGEST` + `SigningAlgorithm=ECDSA_SHA_256`.
     */
    abstract protected function rawSign(string $digestBytes): string;

    public function address(): string
    {
        if ($this->cachedAddress !== null) {
            return $this->cachedAddress;
        }

        $spki = $this->fetchPublicKeySpki();
        $point = Asn1DerDecoder::decodeSpkiUncompressedPoint($spki);

        $this->assertPointOnCurve($point);

        $xy = substr($point, 1); // drop the 0x04 uncompressed-marker byte
        $hash = Keccak::hash($xy, 256);

        return $this->cachedAddress = '0x' . substr($hash, -40);
    }

    public function signDigest(string $digest): string
    {
        $digestHex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;

        if (\strlen($digestHex) !== 64 || ! ctype_xdigit($digestHex)) {
            throw new InvalidArgumentException('Digest must be a 32-byte hex string (optionally 0x-prefixed).');
        }

        // Materialise the address before the sign call so a SPKI-fetch
        // failure surfaces before we consume a KMS sign quota / nonce.
        $address = $this->address();

        $digestBytes = Hex::toBinary($digestHex);
        $der = $this->rawSign($digestBytes);

        $decoded = Asn1DerDecoder::decodeSignature($der);
        $rHex = $decoded['r'];
        $sHex = $this->normalizeLowS($decoded['s']);

        $v = EcdsaRecovery::deriveV($digest, $rHex, $sHex, $address);

        return '0x' . $rHex . $sHex . str_pad(dechex($v), 2, '0', \STR_PAD_LEFT);
    }

    /**
     * Verify the 65-byte uncompressed point decodes as a valid secp256k1
     * public key — on-curve, non-infinity, and order-n. A KMS that returns
     * a syntactically-correct SPKI with garbage point bytes would otherwise
     * yield a Keccak-derived address that no signature will recover to;
     * catching it here means `address()` fails fast on bad config rather
     * than producing a "valid-looking" string that misroutes funds.
     */
    private function assertPointOnCurve(string $point): void
    {
        try {
            $keyPair = (new EC('secp256k1'))->keyFromPublic(bin2hex($point), 'hex');
            $verdict = $keyPair->validate();
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException('SPKI carries a public key that is not a valid secp256k1 point.', 0, $throwable);
        }

        if (! is_array($verdict) || ($verdict['result'] ?? false) !== true) {
            $reason = is_array($verdict) && is_string($verdict['reason'] ?? null) ? $verdict['reason'] : 'unknown';
            throw new InvalidArgumentException(sprintf('SPKI public key fails secp256k1 validation: %s.', $reason));
        }
    }

    /**
     * EIP-2: if s > n/2, replace it with (n - s). KMS providers don't do
     * this for you, and Ethereum tooling rejects "high-s" signatures.
     */
    private function normalizeLowS(string $sHex): string
    {
        $s = \gmp_init($sHex, 16);

        if (\gmp_cmp($s, \gmp_init(self::SECP256K1_HALF_N_HEX, 16)) <= 0) {
            return $sHex;
        }

        $flipped = \gmp_sub(\gmp_init(self::SECP256K1_N_HEX, 16), $s);

        return str_pad(\gmp_strval($flipped, 16), 64, '0', \STR_PAD_LEFT);
    }
}
