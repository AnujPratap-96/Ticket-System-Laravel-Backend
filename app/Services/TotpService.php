<?php

namespace App\Services;

/**
 * RFC 6238 time-based one-time passwords (SHA-1, 6 digits, 30s), compatible with
 * Google Authenticator, Authy, 1Password, etc.
 */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function otpauthUrl(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode("{$issuer}:{$account}")
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Accepts the current step plus/minus $window steps to tolerate clock drift.
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $time = null): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = intdiv($time ?? time(), 30);
        $ok = false;
        for ($i = -$window; $i <= $window; $i++) {
            // No early return: constant-ish time regardless of which step matches.
            $ok = hash_equals($this->code($secret, $step + $i), $code) || $ok;
        }

        return $ok;
    }

    public function code(string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            $bits .= str_pad(decbin($pos === false ? 0 : $pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
