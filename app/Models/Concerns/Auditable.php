<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit_logs row for every create / update / delete / restore,
 * with the changed attributes before and after.
 */
trait Auditable
{
    protected static array $auditIgnore = [
        'created_at', 'updated_at', 'deleted_at', 'password', 'remember_token',
        'two_factor_secret', 'smtp_password', 'invitation_token',
    ];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => static::writeAudit($m, 'created', [], static::auditable($m, $m->getAttributes())));

        static::updated(function (Model $m) {
            $changes = static::auditable($m, $m->getChanges());
            if (! $changes) {
                return;
            }
            $old = array_intersect_key(static::auditable($m, $m->getOriginal()), $changes);
            static::writeAudit($m, 'updated', $old, $changes);
        });

        static::deleted(fn (Model $m) => static::writeAudit($m, 'deleted', static::auditable($m, $m->getOriginal()), []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $m) => static::writeAudit($m, 'restored', [], []));
        }
    }

    protected static function auditable(Model $m, array $attributes): array
    {
        $ignore = array_merge(static::$auditIgnore, $m->getHidden(), $m->auditExclude ?? []);

        return array_map(
            fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v,
            array_diff_key($attributes, array_flip($ignore))
        );
    }

    protected static function writeAudit(Model $m, string $action, array $old, array $new): void
    {
        if ($action === 'updated' && $old === [] && $new === []) {
            return;
        }

        $companyId = $m instanceof \App\Models\Company ? $m->getKey() : ($m->company_id ?? app(Tenant::class)->id());
        if (! $companyId) {
            return; // platform-level record (e.g. the super admin): no tenant journal to write to
        }

        AuditLog::create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $m->getMorphClass(),
            'auditable_id' => $m->getKey(),
            'label' => method_exists($m, 'auditLabel') ? $m->auditLabel() : null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
