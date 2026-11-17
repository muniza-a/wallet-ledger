<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Raw SQL on purpose: the schema builder cannot express CHECK constraints
 * or a per-column ascii_bin collation cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE wallets (
              user_id    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              balance    BIGINT NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              PRIMARY KEY (user_id),
              CONSTRAINT chk_wallet_balance_non_negative CHECK (balance >= 0)
            ) ENGINE=InnoDB
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS wallets');
    }
};
