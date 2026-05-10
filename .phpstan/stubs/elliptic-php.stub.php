<?php

declare(strict_types=1);

// PHPStan stubs for simplito/elliptic-php + simplito/bn-php — neither library
// ships PHPDoc types, so we declare the surface this package uses. Method
// signatures match the real classes byte-for-byte; the @return / property
// annotations are added so PHPStan can narrow.

namespace BN;

class BN
{
    /**
     * @param  int|string|\BN\BN  $val
     * @param  int|string  $base
     * @param  string  $endian
     */
    public function __construct($val = 0, $base = 10, $endian = 'be') {}

    /**
     * @param  int|string  $base
     * @param  int  $padding
     * @return string
     */
    public function toString($base = 10, $padding = 0)
    {
        return '';
    }

    /**
     * @param  \BN\BN  $num
     * @return \BN\BN
     */
    public function add($num)
    {
        return new \BN\BN;
    }

    /**
     * @param  \BN\BN  $num
     * @return \BN\BN
     */
    public function mod($num)
    {
        return new \BN\BN;
    }

    /**
     * @return bool
     */
    public function isZero()
    {
        return false;
    }

    /**
     * @param  \BN\BN  $num
     * @return bool
     */
    public function gte($num)
    {
        return false;
    }
}

namespace Elliptic;

class EC
{
    /**
     * @param  string|array<string, mixed>  $options
     */
    public function __construct($options) {}

    /**
     * @param  string  $priv
     * @param  string|false  $enc
     * @return \Elliptic\EC\KeyPair
     */
    public function keyFromPrivate($priv, $enc = false)
    {
        return new \Elliptic\EC\KeyPair($this, '');
    }

    /**
     * @param  string  $msg
     * @param  array<string, mixed>|\Elliptic\EC\Signature  $signature
     * @param  int  $j
     * @param  string|false  $enc
     * @return \Elliptic\Curve\BaseCurve\Point
     */
    public function recoverPubKey($msg, $signature, $j, $enc = false)
    {
        return new \Elliptic\Curve\BaseCurve\Point;
    }
}

namespace Elliptic\EC;

class KeyPair
{
    /**
     * @param  \Elliptic\EC  $ec
     * @param  array<string, mixed>|string  $options
     */
    public function __construct($ec, $options) {}

    /**
     * @param  string  $msg
     * @param  string|false  $enc
     * @param  array<string, mixed>|false  $options
     * @return \Elliptic\EC\Signature
     */
    public function sign($msg, $enc = false, $options = false)
    {
        return new \Elliptic\EC\Signature(null);
    }

    /**
     * @param  bool  $compact
     * @param  string  $enc
     * @return string
     */
    public function getPublic($compact = false, $enc = '')
    {
        return '';
    }
}

class Signature
{
    public \BN\BN $r;

    public \BN\BN $s;

    public int $recoveryParam = 0;

    /**
     * @param  array{r: string, s: string, recoveryParam?: int}|string|null  $options
     * @param  string|false  $enc
     */
    public function __construct($options, $enc = false)
    {
        $this->r = new \BN\BN;
        $this->s = new \BN\BN;
    }
}

namespace Elliptic\Curve\BaseCurve;

class Point
{
    /**
     * @param  string  $enc
     * @param  bool  $compact
     */
    public function encode($enc, $compact = false): string
    {
        return '';
    }
}
