<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Shared\Enums\Role;
use App\Shared\Export\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The audit log: who created, changed or deleted what and when. Read only, for the school management. */
class AuditLogController extends Controller
{
    /** Columns that say nothing a person cares about. */
    private const NOISE = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);
        $rows = $this->query($request)->with('user:id,name,role')->paginate((int) ($request->query('per_page') ?: 25));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (AuditLog $l) => $this->present($l))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** What the filters can choose from: the kinds of record that were logged and the people who logged changes. */
    public function filters(Request $request): JsonResponse
    {
        $this->allow($request);
        $types = AuditLog::query()->distinct()->pluck('auditable_type')->map(fn ($t) => class_basename($t))->unique()->sort()->values();
        $users = User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->get(['id', 'name']);

        return response()->json(['types' => $types, 'users' => $users]);
    }

    /** The same list as an Excel file (up to 10,000 rows, newest first). */
    public function export(Request $request, XlsxExport $xlsx): BinaryFileResponse
    {
        $this->allow($request);
        $rows = $this->query($request)->with('user:id,name')->limit(10000)->get()->map(function (AuditLog $l) {
            $changes = collect($this->changes($l))->map(fn ($c) => $c['field'].': '.($c['old'] !== null ? $this->text($c['old']).' → ' : '').$this->text($c['new']))->implode('; ');

            return [$l->created_at->format('Y-m-d H:i:s'), $l->user?->name ?? '—', __('audit.actions.'.$l->action), class_basename($l->auditable_type), $l->auditable_id, $changes];
        });

        return $xlsx->download(__('audit.file').'_'.now()->format('Y-m-d'), __('audit.sheet'), [__('audit.when'), __('audit.who'), __('audit.action'), __('audit.record'), __('audit.record_id'), __('audit.changes')], $rows);
    }

    /** @return Builder<AuditLog> */
    private function query(Request $request): Builder
    {
        $f = $request->validate([
            'action' => ['nullable', 'in:created,updated,deleted'], 'type' => ['nullable', 'string', 'max:80'], 'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'record_id' => ['nullable', 'integer'],
        ]);

        return AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', 'like', '%\\'.addcslashes($v, '%_\\')))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['record_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'));
    }

    /** @return array<string, mixed> */
    private function present(AuditLog $l): array
    {
        return [
            'id' => $l->id, 'at' => $l->created_at->toIso8601String(), 'action' => $l->action, 'type' => class_basename($l->auditable_type), 'record_id' => $l->auditable_id,
            'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'role' => $l->user->role->value] : null, 'changes' => $this->changes($l),
        ];
    }

    /**
     * What changed, as field / old / new: an update shows each changed field, a creation the values it started with, a deletion what it had.
     *
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    private function changes(AuditLog $l): array
    {
        $old = $l->old_values ?? [];
        $new = $l->new_values ?? [];
        $fields = array_values(array_diff(array_keys($l->action === 'deleted' ? $old : $new), self::NOISE));

        return array_map(fn ($f) => ['field' => $f, 'old' => $l->action === 'created' ? null : ($old[$f] ?? null), 'new' => $l->action === 'deleted' ? null : ($new[$f] ?? null)] + ($l->action === 'deleted' ? ['old' => $old[$f] ?? null] : []), array_slice($fields, 0, 20));
    }

    private function text(mixed $v): string
    {
        return is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    private function allow(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Admin), 403, __('audit.not_allowed'));
    }
}
