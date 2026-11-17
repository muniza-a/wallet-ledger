<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ledger_entries (
              id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              type            ENUM('credit','debit') NOT NULL,
              amount          BIGINT NOT NULL,
              balance_after   BIGINT NOT NULL,
              idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
              created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              PRIMARY KEY (id),
              KEY idx_ledger_user_id_id (user_id, id),
              UNIQUE KEY uq_ledger_user_idempotency (user_id, idempotency_key),
              CONSTRAINT chk_ledger_amount_positive CHECK (amount > 0),
              CONSTRAINT chk_ledger_balance_after_non_negative CHECK (balance_after >= 0)
            ) ENGINE=InnoDB
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ledger_entries');
    }
};
