<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogs\Widgets\AuditLogStatsWidget;
use App\Models\AuditLog;
use App\Services\Audit\AuditService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /** AUD-9: the most rows one download holds; narrow the filters for more. */
    public const EXPORT_LIMIT = 50000;

    protected function getHeaderWidgets(): array
    {
        return [
            AuditLogStatsWidget::class,
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * AUD-9: downloads the rows that match the current filters and search, newest first, in Dhaka time. Values
     * are already redacted when written (AUD-7). The download is itself logged.
     */
    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery() ?? AuditLog::query();
        $filename = 'audit-log-'.now(config('app.display_timezone'))->format('Ymd-His').'.csv';

        app(AuditService::class)->recordExport('audit log', $filename);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, [
                'Time (Dhaka)', 'Who acted', 'Actor', 'Actor email', 'Role', 'Seller ID', 'Module', 'Event',
                'Description', 'Record type', 'Record ID', 'Record', 'Changed fields', 'Status', 'Suspicious',
                'IP address', 'Route', 'Correlation ID',
            ]);

            foreach ((clone $query)->limit(self::EXPORT_LIMIT)->cursor() as $log) {
                /** @var AuditLog $log */
                fputcsv($handle, [
                    $log->created_at ? Carbon::parse($log->created_at)->timezone(config('app.display_timezone'))->format('Y-m-d H:i:s') : '',
                    $log->actor_type,
                    $log->actor_name,
                    $log->actor_email,
                    $log->actor_role,
                    $log->seller_id,
                    $log->module,
                    $log->action,
                    $log->description,
                    $log->auditable_type ? class_basename($log->auditable_type) : '',
                    $log->auditable_id,
                    $log->record_label,
                    implode(', ', (array) ($log->changed_fields ?? [])),
                    $log->status,
                    $log->is_suspicious ? 'yes' : 'no',
                    $log->ip_address,
                    $log->route_name,
                    $log->correlation_id,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
