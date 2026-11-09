<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-9: the database protects the books on its own, so a direct query or a bug can't remove, change or unbalance a
 * posted journal.
 *
 * - Every database: deleting a journal entry no longer deletes its lines; an entry that has lines can't be deleted.
 * - PostgreSQL only:
 *   - each line holds a positive amount on exactly one side;
 *   - new entries start as drafts, and a draft can only become posted when it has two or more lines that balance;
 *   - a posted entry and its lines can't be changed or deleted, except the one change a reversal makes
 *     (posted → reversed, with the reversal's id);
 *   - neither table can be truncated.
 *
 * No data is changed. If old lines already break the amount rule, the rule still applies to new lines, and the
 * migration prints how many old lines need review (see DEPLOYMENT_GUIDE, ACC-9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->foreign('journal_entry_id')->references('id')->on('accounting_journal_entries')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->addLineAmountRule();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounting_journal_entries_guard() RETURNS trigger AS $$
            DECLARE
                line_count integer;
                total_debit numeric;
                total_credit numeric;
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'Journal records cannot be truncated.' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'A journal entry must start as a draft.' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'Posted journal % cannot be deleted. Post a reversal instead.', OLD.journal_number
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'draft' THEN
                    IF NEW.status = 'posted' THEN
                        SELECT count(*), coalesce(sum(debit), 0), coalesce(sum(credit), 0)
                            INTO line_count, total_debit, total_credit
                            FROM accounting_journal_lines WHERE journal_entry_id = NEW.id;

                        IF line_count < 2 OR total_debit <= 0 OR total_debit <> total_credit THEN
                            RAISE EXCEPTION 'Journal % does not balance (debit %, credit %, % lines).',
                                NEW.journal_number, total_debit, total_credit, line_count
                                USING ERRCODE = 'check_violation';
                        END IF;
                    ELSIF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'A draft journal can only be posted.' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'posted'
                    AND NEW.status = 'reversed'
                    AND NEW.reversal_journal_id IS NOT NULL
                    AND (to_jsonb(NEW) - 'status' - 'reversal_journal_id' - 'updated_at')
                        = (to_jsonb(OLD) - 'status' - 'reversal_journal_id' - 'updated_at') THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Posted journal % cannot be changed. Post a reversal instead.', OLD.journal_number
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION accounting_journal_lines_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'Journal records cannot be truncated.' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP IN ('UPDATE', 'DELETE') AND EXISTS (
                    SELECT 1 FROM accounting_journal_entries WHERE id = OLD.journal_entry_id AND status <> 'draft'
                ) THEN
                    RAISE EXCEPTION 'Lines of a posted journal cannot be changed or deleted. Post a reversal instead.'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE') AND EXISTS (
                    SELECT 1 FROM accounting_journal_entries WHERE id = NEW.journal_entry_id AND status <> 'draft'
                ) THEN
                    RAISE EXCEPTION 'Lines cannot be added to a posted journal.' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS accounting_journal_entries_guard_rows ON accounting_journal_entries;
            CREATE TRIGGER accounting_journal_entries_guard_rows
                BEFORE INSERT OR UPDATE OR DELETE ON accounting_journal_entries
                FOR EACH ROW EXECUTE FUNCTION accounting_journal_entries_guard();

            DROP TRIGGER IF EXISTS accounting_journal_entries_guard_truncate ON accounting_journal_entries;
            CREATE TRIGGER accounting_journal_entries_guard_truncate
                BEFORE TRUNCATE ON accounting_journal_entries
                FOR EACH STATEMENT EXECUTE FUNCTION accounting_journal_entries_guard();

            DROP TRIGGER IF EXISTS accounting_journal_lines_guard_rows ON accounting_journal_lines;
            CREATE TRIGGER accounting_journal_lines_guard_rows
                BEFORE INSERT OR UPDATE OR DELETE ON accounting_journal_lines
                FOR EACH ROW EXECUTE FUNCTION accounting_journal_lines_guard();

            DROP TRIGGER IF EXISTS accounting_journal_lines_guard_truncate ON accounting_journal_lines;
            CREATE TRIGGER accounting_journal_lines_guard_truncate
                BEFORE TRUNCATE ON accounting_journal_lines
                FOR EACH STATEMENT EXECUTE FUNCTION accounting_journal_lines_guard();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS accounting_journal_entries_guard_rows ON accounting_journal_entries;
                DROP TRIGGER IF EXISTS accounting_journal_entries_guard_truncate ON accounting_journal_entries;
                DROP TRIGGER IF EXISTS accounting_journal_lines_guard_rows ON accounting_journal_lines;
                DROP TRIGGER IF EXISTS accounting_journal_lines_guard_truncate ON accounting_journal_lines;
                DROP FUNCTION IF EXISTS accounting_journal_entries_guard();
                DROP FUNCTION IF EXISTS accounting_journal_lines_guard();
                ALTER TABLE accounting_journal_lines DROP CONSTRAINT IF EXISTS accounting_journal_lines_amounts_check;
                SQL);
        }

        Schema::table('accounting_journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->foreign('journal_entry_id')->references('id')->on('accounting_journal_entries')->cascadeOnDelete();
        });
    }

    /**
     * Each line holds a positive amount on exactly one side. Old lines that break the rule are left as they are: the
     * rule is then added for new lines only, and the count is printed so they can be reviewed.
     */
    private function addLineAmountRule(): void
    {
        $rule = 'debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0)';
        $badLines = (int) DB::table('accounting_journal_lines')->whereRaw("NOT ({$rule})")->count();
        $notValid = $badLines > 0 ? ' NOT VALID' : '';

        DB::statement("ALTER TABLE accounting_journal_lines ADD CONSTRAINT accounting_journal_lines_amounts_check CHECK ({$rule}){$notValid}");

        if ($badLines > 0) {
            fwrite(STDERR, "ACC-9: {$badLines} existing journal line(s) break the amount rule and need review; the rule applies to new lines.\n");
        }
    }
};
