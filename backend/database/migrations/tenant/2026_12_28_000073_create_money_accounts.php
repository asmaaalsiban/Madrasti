<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The school's cash boxes and bank accounts, and the book of everything that goes in and out of them. Every student payment lands
     * in one of them. Existing payments are moved in (cash and cheques to the cash box, the rest to the bank), so the books start complete.
     */
    public function up(): void
    {
        if (! Schema::hasTable('money_accounts')) {
            Schema::create('money_accounts', function (Blueprint $t): void {
                $t->id();
                $t->string('name', 120);
                $t->string('type', 10); // cash | bank
                $t->decimal('opening_balance', 14, 2)->default(0);
                $t->date('opened_on');
                $t->string('bank_name', 120)->nullable();
                $t->string('iban', 60)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('reconciliations')) {
            Schema::create('reconciliations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('money_account_id')->constrained('money_accounts')->cascadeOnDelete();
                $t->date('statement_date');
                $t->decimal('statement_balance', 14, 2);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('account_entries')) {
            Schema::create('account_entries', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('money_account_id')->constrained('money_accounts')->cascadeOnDelete();
                $t->date('entry_date');
                $t->string('kind', 20); // receipt | transfer_in | transfer_out | adjustment
                $t->decimal('amount', 14, 2); // + into the account, - out of it
                $t->string('description', 255)->nullable();
                $t->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $t->string('transfer_ref', 40)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('reconciled_at')->nullable();
                $t->foreignId('reconciliation_id')->nullable()->constrained('reconciliations')->nullOnDelete();
                $t->timestamps();
                $t->index(['money_account_id', 'entry_date']);
            });
        }
        if (! Schema::hasColumn('payments', 'money_account_id')) {
            Schema::table('payments', function (Blueprint $t): void {
                $t->foreignId('money_account_id')->nullable()->constrained('money_accounts')->nullOnDelete();
            });
        }

        $this->start();
    }

    /** Two accounts to begin with, and every payment already taken moved into one. Safe to run twice. */
    private function start(): void
    {
        $ar = config('app.locale') === 'ar';
        $now = now();
        $today = $now->toDateString();
        $ids = [];
        foreach (['cash' => [$ar ? 'الصندوق' : 'Cash box', null], 'bank' => [$ar ? 'الحساب البنكي' : 'Bank account', 'bank']] as $type => [$name]) {
            $ids[$type] = DB::table('money_accounts')->where('type', $type)->orderBy('id')->value('id')
                ?? DB::table('money_accounts')->insertGetId(['name' => $name, 'type' => $type, 'opening_balance' => 0, 'opened_on' => $today, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('payments')->whereNull('money_account_id')->where('method', '!=', 'carry_forward')->orderBy('id')->each(function ($p) use ($ids, $now): void {
            $account = in_array($p->method, ['cash', 'cheque'], true) ? $ids['cash'] : $ids['bank'];
            DB::table('payments')->where('id', $p->id)->update(['money_account_id' => $account]);
            if (! DB::table('account_entries')->where('payment_id', $p->id)->exists()) {
                DB::table('account_entries')->insert(['money_account_id' => $account, 'entry_date' => $p->paid_on, 'kind' => 'receipt', 'amount' => $p->amount, 'payment_id' => $p->id, 'created_by' => $p->received_by, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'money_account_id')) {
            Schema::table('payments', function (Blueprint $t): void {
                $t->dropConstrainedForeignId('money_account_id');
            });
        }
        Schema::dropIfExists('account_entries');
        Schema::dropIfExists('reconciliations');
        Schema::dropIfExists('money_accounts');
    }
};
