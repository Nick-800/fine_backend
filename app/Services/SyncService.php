<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SyncConflictStatus;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\ExternalEmployer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperatingUnit;
use App\Models\SyncConflict;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SyncService
{
    /**
     * Map of supported table names to Eloquent model classes.
     *
     * @var array<string, class-string<Model>>
     */
    private array $modelMap = [
        'entities' => Entity::class,
        'employees' => Employee::class,
        'clients' => Client::class,
        'external_employers' => ExternalEmployer::class,
        'inventory_items' => InventoryItem::class,
        'work_orders' => WorkOrder::class,
        'inventory_movements' => InventoryMovement::class,
    ];

    /**
     * Tier 3A financial tables that must be strict online-only.
     *
     * @var array<int, string>
     */
    private array $strictOnlineTables = [
        'payment_requests',
        'bank_holds',
        'fx_rates',
        'cash_accounts',
        'payroll_runs',
        'payslips',
        'journal_entries',
        'journal_lines',
    ];

    /**
     * Process batched outbox items pushed from Electron client.
     */
    public function processPush(User $user, OperatingUnit $unit, string $deviceId, array $outbox): array
    {
        return DB::transaction(function () use ($unit, $deviceId, $outbox): array {
            $results = [];

            foreach ($outbox as $item) {
                $tableName = $item['table'];
                $recordId = $item['record_id'];
                $baseVersion = (int) $item['base_version'];
                $data = $item['data'];
                $actionId = $item['action_id'];

                // Rule Check: Tier 3A Strict Online Money Block
                if (in_array($tableName, $this->strictOnlineTables, true)) {
                    $conflict = SyncConflict::create([
                        'operating_unit_id' => $unit->id,
                        'device_id' => $deviceId,
                        'table_name' => $tableName,
                        'record_id' => $recordId,
                        'action' => $item['operation'],
                        'base_version' => $baseVersion,
                        'server_version' => 1,
                        'payload' => $data,
                        'conflict_reason' => 'Tier 3A monetary ledger operations are strict online-only and cannot be pushed via offline outbox.',
                        'status' => SyncConflictStatus::Quarantined,
                    ]);

                    $results[] = [
                        'action_id' => $actionId,
                        'status' => 'quarantined',
                        'conflict_id' => $conflict->id,
                        'reason' => $conflict->conflict_reason,
                    ];

                    continue;
                }

                $modelClass = $this->modelMap[$tableName] ?? null;

                if (! $modelClass) {
                    $results[] = [
                        'action_id' => $actionId,
                        'status' => 'error',
                        'message' => "Unsupported table for sync: {$tableName}",
                    ];

                    continue;
                }

                $formattedData = $this->snakeKeys($data);

                if (Schema::hasColumn((new $modelClass)->getTable(), 'operating_unit_id')) {
                    $formattedData['operating_unit_id'] = $unit->id;
                }

                /** @var Model|null $existing */
                $existing = $modelClass::where('id', $recordId)->first();

                if ($item['operation'] === 'create' && ! $existing) {
                    $newRecord = new $modelClass;
                    $newRecord->fill($formattedData);
                    $newRecord->id = $recordId;

                    if (isset($newRecord->record_version)) {
                        $newRecord->record_version = 1;
                    }

                    $newRecord->save();

                    $results[] = [
                        'action_id' => $actionId,
                        'status' => 'synced',
                        'record_version' => $newRecord->record_version ?? 1,
                    ];

                    continue;
                }

                if (! $existing) {
                    $results[] = [
                        'action_id' => $actionId,
                        'status' => 'error',
                        'message' => "Record not found for update: {$recordId}",
                    ];

                    continue;
                }

                if (isset($existing->operating_unit_id) && $existing->operating_unit_id !== $unit->id) {
                    $results[] = [
                        'action_id' => $actionId,
                        'status' => 'error',
                        'message' => "Unauthorized unit access for record: {$recordId}",
                    ];

                    continue;
                }

                $serverVersion = (int) ($existing->record_version ?? 1);

                // Version Check
                if ($baseVersion !== $serverVersion) {
                    // Attempt field-level merge check
                    $serverChangedKeys = array_keys($existing->getDirty());
                    $incomingKeys = array_keys($formattedData);
                    $overlappingKeys = array_intersect($serverChangedKeys, $incomingKeys);

                    if (! empty($overlappingKeys)) {
                        // Overlapping field conflict -> Quarantine
                        $conflict = SyncConflict::create([
                            'operating_unit_id' => $unit->id,
                            'device_id' => $deviceId,
                            'table_name' => $tableName,
                            'record_id' => $recordId,
                            'action' => $item['operation'],
                            'base_version' => $baseVersion,
                            'server_version' => $serverVersion,
                            'payload' => $data,
                            'conflict_reason' => 'Conflicting changes on same fields: '.implode(', ', $overlappingKeys),
                            'status' => SyncConflictStatus::Quarantined,
                        ]);

                        $results[] = [
                            'action_id' => $actionId,
                            'status' => 'quarantined',
                            'conflict_id' => $conflict->id,
                            'reason' => $conflict->conflict_reason,
                        ];

                        continue;
                    }
                }

                // Apply update
                $existing->fill($formattedData);

                if (isset($existing->record_version)) {
                    $existing->record_version = $serverVersion + 1;
                }

                $existing->save();

                $results[] = [
                    'action_id' => $actionId,
                    'status' => 'synced',
                    'record_version' => $existing->record_version ?? ($serverVersion + 1),
                ];
            }

            return [
                'processed_count' => count($results),
                'results' => $results,
            ];
        });
    }

    /**
     * Pull delta changes for syncable models modified after given timestamp.
     */
    public function processPull(User $user, OperatingUnit $unit, ?string $since = null): array
    {
        $changes = [];

        foreach ($this->modelMap as $tableName => $modelClass) {
            $query = $modelClass::query();

            if (Schema::hasColumn((new $modelClass)->getTable(), 'operating_unit_id')) {
                $query->where('operating_unit_id', $unit->id);
            }

            if ($since) {
                $sinceDate = Carbon::parse($since);

                $query->where(function ($q) use ($sinceDate, $modelClass): void {
                    $q->where('updated_at', '>', $sinceDate);

                    if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                        $q->orWhere('deleted_at', '>', $sinceDate);
                    }
                });
            }

            $records = $query->get();

            $changes[$tableName] = $records->map(function ($rec) {
                $arr = $rec->toArray();
                $camelArr = [];
                foreach ($arr as $key => $value) {
                    $camelArr[Str::camel($key)] = $value;
                }

                return $camelArr;
            })->all();
        }

        return [
            'server_timestamp' => now()->toIso8601String(),
            'changes' => $changes,
        ];
    }

    /**
     * Resolve a quarantined sync conflict.
     */
    public function resolveQuarantine(SyncConflict $conflict, User $manager, string $action, ?array $modifiedData = null): SyncConflict
    {
        return DB::transaction(function () use ($conflict, $manager, $action, $modifiedData): SyncConflict {
            if ($action === 'override') {
                $modelClass = $this->modelMap[$conflict->table_name] ?? null;

                if ($modelClass) {
                    /** @var Model|null $record */
                    $record = $modelClass::where('id', $conflict->record_id)->first();

                    if (! $record) {
                        $record = new $modelClass;
                        $record->id = $conflict->record_id;
                    }

                    $record->fill($this->snakeKeys($conflict->payload));

                    if (isset($record->record_version)) {
                        $record->record_version = $conflict->server_version + 1;
                    }

                    $record->save();
                }

                $conflict->update([
                    'status' => SyncConflictStatus::ResolvedOverride,
                    'resolved_by_user_id' => $manager->id,
                    'resolved_at' => now(),
                ]);
            } elseif ($action === 'edit') {
                $payloadToUse = $modifiedData ?? $conflict->payload;
                $modelClass = $this->modelMap[$conflict->table_name] ?? null;

                if ($modelClass) {
                    /** @var Model|null $record */
                    $record = $modelClass::where('id', $conflict->record_id)->first();

                    if (! $record) {
                        $record = new $modelClass;
                        $record->id = $conflict->record_id;
                    }

                    $record->fill($this->snakeKeys($payloadToUse));

                    if (isset($record->record_version)) {
                        $record->record_version = $conflict->server_version + 1;
                    }

                    $record->save();
                }

                $conflict->update([
                    'status' => SyncConflictStatus::ResolvedEdited,
                    'resolved_by_user_id' => $manager->id,
                    'resolved_at' => now(),
                ]);
            } elseif ($action === 'void') {
                $conflict->update([
                    'status' => SyncConflictStatus::ResolvedVoided,
                    'resolved_by_user_id' => $manager->id,
                    'resolved_at' => now(),
                ]);
            } else {
                throw new InvalidArgumentException("Unsupported quarantine resolution action: {$action}");
            }

            return $conflict;
        });
    }

    /**
     * Convert array keys to snake_case.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snakeKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[Str::snake($key)] = $value;
        }

        return $result;
    }
}
