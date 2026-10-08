<?php
declare(strict_types=1);

namespace App\Providers;

use App\Database;
use App\Settings;

/** Optional explicit S2S contract; NOT a claim that any vendor supports it. */
final class Callback
{
    public static function process(string $provider, string $timestamp, string $signature, string $body): bool
    {
        $secret = Settings::get('provider_' . $provider . '_callback_secret', '');
        if (Catalog::get($provider) === null || strlen($secret) < 32 || strlen($body) > 8192
            || !preg_match('/^\d{10}$/D', $timestamp) || abs(time() - (int)$timestamp) > 300
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
            throw new \InvalidArgumentException('Invalid callback authentication.');
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !is_string($data['token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['token'])
            || ($data['status'] ?? null) !== 'paid' || !is_string($data['remote_id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid callback payload.');
        }
        $row = Database::one('SELECT provider, remote_id, status FROM provider_attempts WHERE token = ?', [$data['token']]);
        if ($row === null || $row['provider'] !== $provider || $row['remote_id'] !== $data['remote_id']) {
            throw new \InvalidArgumentException('Unknown provider attempt.');
        }
        if ($row['status'] === 'credited') {
            return true; // Acknowledge authenticated retries without crediting twice.
        }
        return Tasks::credit($data['token'], 'signed_callback', $provider);
    }
}