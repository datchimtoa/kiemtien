<?php
declare(strict_types=1);

namespace App;

/**
 * Audit trail for privileged actions.
 */
final class Audit
{
    public static function log(string $actorType, int $actorId, string $action, string $target = '', ?array $detail = null): void
    {
        Database::run(
            'INSERT INTO audit_log(actor_type, actor_id, action, target, detail, ip, created_at) VALUES(?,?,?,?,?,?,?)',
            [$actorType, $actorId, $action, $target, $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), client_ip(), now()]
        );
    }
}
