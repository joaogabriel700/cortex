<?php

namespace Database\Seeders\Finance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeder base de Finanças. Roda como app_runtime.
 *
 * Moedas: a carga é feita pela migration 02 (o runtime não pode inseri-las);
 * aqui só se confere.
 * Categorias: são por usuário. Sem userId, nada é criado (sob RLS o runtime
 * não consegue listar usuários).
 */
class FinanceBaseSeeder extends Seeder
{
    private const CURRENCIES = ['BRL', 'USD', 'EUR'];

    public function run(?string $userId = null): void
    {
        $found = DB::table('currencies')->whereIn('code', self::CURRENCIES)->count();

        if ($found !== count(self::CURRENCIES)) {
            throw new RuntimeException('Moedas base ausentes: rode as migrations de Finanças antes do seed.');
        }

        if ($userId !== null) {
            $this->callWith(DefaultCategoriesSeeder::class, ['userId' => $userId]);
        }
    }
}
