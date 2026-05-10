<?php

declare(strict_types=1);

namespace X402\Client;

use BN\BN;
use Elliptic\EC;
use InvalidArgumentException;
use RuntimeException;
use X402\Support\Hex;

/**
 * BIP-32 hierarchical-deterministic wallet sibling to {@see PrivateKeyWallet}.
 *
 * Derives a signing key from a master seed plus a derivation path
 * (e.g. "m/44'/60'/0'/0/<tenant-id>"). Composition over inheritance:
 * once the path resolves to a 32-byte private key, the rest of the flow
 * delegates to PrivateKeyWallet, so signatures are byte-identical.
 *
 * Phase 1 surface — fromSeed only. xprv import + xpub-only public derivation
 * are deferred until an adopter pulls them in (see spec-hd-wallet).
 */
final readonly class HdWallet implements Wallet
{
    /** secp256k1 curve order n. */
    private const CURVE_ORDER_HEX = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /** Highest valid path component digit; combined with hardened bit caps at uint32 max. */
    private const MAX_COMPONENT_DIGIT = 2_147_483_647;

    /** BIP-32 mandates 128..512-bit seeds. */
    private const MIN_SEED_BYTES = 16;

    private const MAX_SEED_BYTES = 64;

    private PrivateKeyWallet $inner;

    private function __construct(private string $derivedPrivateKeyHex)
    {
        $this->inner = new PrivateKeyWallet($this->derivedPrivateKeyHex);
    }

    /**
     * @param  string  $seedHex         Hex-encoded master seed (typically 64 bytes / 128 hex chars).
     *                                  Generated from a BIP-39 mnemonic via PBKDF2, or supplied
     *                                  directly by adopters who manage their own seed entropy.
     * @param  string  $derivationPath  BIP-32 path, e.g. "m/44'/60'/0'/0/0". The leading "m/"
     *                                  is optional; hardened indices use "'" suffix.
     */
    public static function fromSeed(string $seedHex, string $derivationPath): self
    {
        $seed = self::normalizeHex($seedHex);

        if ($seed === '' || \strlen($seed) % 2 !== 0 || ! ctype_xdigit($seed)) {
            throw new InvalidArgumentException('Seed must be non-empty even-length hex.');
        }

        $seedBin = Hex::toBinary($seed);
        $seedBytes = \strlen($seedBin);

        if ($seedBytes < self::MIN_SEED_BYTES || $seedBytes > self::MAX_SEED_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Seed must be %d..%d bytes per BIP-32 (got %d).',
                self::MIN_SEED_BYTES,
                self::MAX_SEED_BYTES,
                $seedBytes,
            ));
        }

        $master = hash_hmac('sha512', $seedBin, 'Bitcoin seed', true);
        $privBin = substr($master, 0, 32);
        $chainBin = substr($master, 32, 32);

        $n = new BN(self::CURVE_ORDER_HEX, 16);
        self::assertValidScalar($privBin, $n, 'Invalid master key from seed.');

        $ec = new EC('secp256k1');

        foreach (self::parsePath($derivationPath) as $component) {
            [$privBin, $chainBin] = self::deriveChild($ec, $n, $privBin, $chainBin, $component['digit'], $component['hardened']);
        }

        return new self(bin2hex($privBin));
    }

    public function address(): string
    {
        return $this->inner->address();
    }

    public function signDigest(string $digest): string
    {
        return $this->inner->signDigest($digest);
    }

    /**
     * Returns the raw 32-byte derived private key as hex (no 0x prefix).
     */
    public function privateKeyHex(): string
    {
        return $this->derivedPrivateKeyHex;
    }

    /**
     * @return list<array{digit: int, hardened: bool}>  Path components split into the raw
     *                                                  digit (0..2^31-1, fits a 32-bit signed
     *                                                  int) and a hardened flag, so we never
     *                                                  materialize the combined uint32 in PHP
     *                                                  int — keeping this 32-bit-PHP-safe.
     */
    private static function parsePath(string $path): array
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Derivation path is empty.');
        }

        $parts = explode('/', $trimmed);

        if ($parts[0] === 'm' || $parts[0] === 'M') {
            array_shift($parts);
        }

        $components = [];

        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidArgumentException(sprintf('Malformed derivation path "%s".', $path));
            }

            $hardened = str_ends_with($part, "'");
            $digits = $hardened ? substr($part, 0, -1) : $part;

            if ($digits === '' || ! ctype_digit($digits)) {
                throw new InvalidArgumentException(sprintf('Malformed derivation path component "%s".', $part));
            }

            // Float comparison up to 2^53 is exact; this stays correct even on 32-bit PHP
            // where (int) "2147483648" would silently saturate to PHP_INT_MAX.
            if ((float) $digits > (float) self::MAX_COMPONENT_DIGIT) {
                throw new InvalidArgumentException(sprintf('Derivation index "%s" is out of range (must be < 2^31).', $part));
            }

            $components[] = ['digit' => (int) $digits, 'hardened' => $hardened];
        }

        return $components;
    }

    /**
     * @return array{0: string, 1: string}  [childPrivBin, childChainBin]
     */
    private static function deriveChild(EC $ec, BN $n, string $parentPrivBin, string $parentChainBin, int $digit, bool $hardened): array
    {
        $indexBe = self::serializeIndexBe($digit, $hardened);

        if ($hardened) {
            $data = "\x00" . $parentPrivBin . $indexBe;
        } else {
            $kp = $ec->keyFromPrivate(bin2hex($parentPrivBin), 'hex');
            $compressed = $kp->getPublic(true, 'hex');

            if (! is_string($compressed)) {
                throw new RuntimeException('KeyPair::getPublic did not return a string.');
            }

            $data = Hex::toBinary($compressed) . $indexBe;
        }

        $i = hash_hmac('sha512', $data, $parentChainBin, true);
        $iL = substr($i, 0, 32);
        $iR = substr($i, 32, 32);

        $iLBn = new BN(bin2hex($iL), 16);

        if ($iLBn->gte($n)) {
            throw new RuntimeException('Derivation produced I_L >= n; pick the next index per BIP-32.');
        }

        $parentBn = new BN(bin2hex($parentPrivBin), 16);
        $childBn = $iLBn->add($parentBn)->mod($n);

        if ($childBn->isZero()) {
            throw new RuntimeException('Derivation produced zero child key; pick the next index per BIP-32.');
        }

        $childHex = str_pad($childBn->toString(16), 64, '0', STR_PAD_LEFT);

        return [Hex::toBinary($childHex), $iR];
    }

    /**
     * Serialize a uint32 BIP-32 child number as 4 big-endian bytes without ever holding
     * the combined value in a PHP int — `digit` always fits a 32-bit signed int and the
     * hardened bit is OR'd into the top byte.
     */
    private static function serializeIndexBe(int $digit, bool $hardened): string
    {
        $topByte = ($digit >> 24) & 0x7F;

        if ($hardened) {
            $topByte |= 0x80;
        }

        return chr($topByte)
            . chr(($digit >> 16) & 0xFF)
            . chr(($digit >> 8) & 0xFF)
            . chr($digit & 0xFF);
    }

    private static function assertValidScalar(string $privBin, BN $n, string $message): void
    {
        $bn = new BN(bin2hex($privBin), 16);

        if ($bn->isZero() || $bn->gte($n)) {
            throw new InvalidArgumentException($message);
        }
    }

    private static function normalizeHex(string $value): string
    {
        return str_starts_with($value, '0x') ? substr($value, 2) : $value;
    }
}
