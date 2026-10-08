<?php
declare(strict_types=1);

namespace App\Providers;

use App\Settings;

final class Configuration
{
    /** Validate all provider values before saving anything. Blank secrets are preserved. */
    public static function validate(array $input): array
    {
        $values = [];
        foreach (Catalog::definitions() as $id => $def) {
            $prefix = 'provider_' . $id . '_';
            $values[$prefix . 'enabled'] = isset($input[$prefix . 'enabled']) ? '1' : '0';
            foreach (['api_key', 'callback_secret'] as $field) {
                $key = $prefix . $field;
                if (isset($input[$key]) && (!is_string($input[$key]) || strlen($input[$key]) > 512 || preg_match('/[\r\n]/', $input[$key]))) {
                    throw new \InvalidArgumentException('API key / secret không hợp lệ.');
                }
                if (isset($input[$key]) && trim($input[$key]) !== '') {
                    if ($field === 'callback_secret' && strlen(trim($input[$key])) < 32) {
                        throw new \InvalidArgumentException('Callback secret cần ít nhất 32 ký tự.');
                    }
                    $values[$key] = trim($input[$key]);
                }
            }
            $ranges = ['reward_vnd' => [0, 2000000], 'share_percent' => [0, 100], 'daily_limit' => [1, 1000], 'ip_daily_limit' => [1, 1000], 'min_seconds' => [1, 86400]];
            foreach ($def['services'] as $service) {
                if ($service['id'] !== 'no_ads') {
                    $ranges['service_' . $service['id'] . '_reward_vnd'] = [0, 2000000];
                }
            }
            foreach ($ranges as $field => [$min, $max]) {
                $key = $prefix . $field;
                if (!array_key_exists($key, $input)) {
                    continue;
                }
                if (!is_string($input[$key]) || !preg_match('/^\d{1,8}$/D', $input[$key]) || (int)$input[$key] < $min || (int)$input[$key] > $max) {
                    throw new \InvalidArgumentException('Giá trị cấu hình không hợp lệ: ' . $key);
                }
                $values[$key] = (string)(int)$input[$key];
            }
            if ($values[$prefix . 'enabled'] === '1' && ($values[$prefix . 'api_key'] ?? Settings::get($prefix . 'api_key', '')) === '') {
                throw new \InvalidArgumentException('Cần API key trước khi bật ' . $def['label']);
            }
        }
        return $values;
    }
}