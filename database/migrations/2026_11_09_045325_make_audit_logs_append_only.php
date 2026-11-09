<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUD-2: on PostgreSQL, audit rows can't be updated, deleted or truncated, even by a direct query. The only exception
 * is a delete made while the session setting `app.audit_prune` is 'on', which `audit:prune` switches on for its own
 * transaction only. Other databases rely on the model guard in `AuditLog`. No data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('app.audit_prune', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'Audit records cannot be changed or deleted.' USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_append_only_rows ON audit_logs;
            CREATE TRIGGER audit_logs_append_only_rows
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();

            DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
            CREATE TRIGGER audit_logs_append_only_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_append_only_rows ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
            DROP FUNCTION IF EXISTS audit_logs_append_only();
            SQL);
    }
};
