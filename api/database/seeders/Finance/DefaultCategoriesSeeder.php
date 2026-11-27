<?php

namespace Database\Seeders\Finance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Categorias padrão de UM usuário. Roda como app_runtime (sob RLS).
 * Pode repetir: não duplica, não desfaz edição nem revive categoria excluída.
 * Falha se alguma categoria padrão não existir ao final (ex.: id já ocupado).
 * Um usuário por transação.
 *
 * Uso: $this->callWith(DefaultCategoriesSeeder::class, ['userId' => $id]);
 */
class DefaultCategoriesSeeder extends Seeder
{
    public function run(string $userId): void
    {
        // Dentro de outra transação isto viraria savepoint, e o contexto novo
        // continuaria valendo depois dele.
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('DefaultCategoriesSeeder::run exige nível de transação 0; com o contexto já definido, use insertFor().');
        }

        DB::transaction(function () use ($userId): void {
            DB::select("select set_config('app.user_id', ?, true)", [$userId]);
            self::insertFor($userId);
        });
    }

    /**
     * Para o cadastro: chamar dentro de uma transação que já tenha o contexto
     * do usuário definido (TenantContext).
     */
    public static function insertFor(string $userId): void
    {
        $context = (string) DB::scalar("select nullif(current_setting('app.user_id', true), '')");

        if (DB::transactionLevel() < 1 || strcasecmp($context, $userId) !== 0) {
            throw new RuntimeException('insertFor exige uma transação com o contexto do próprio usuário.');
        }

        // Todo caminho de escrita trava o contador antes de gravar (financas.md §4.2).
        DB::statement('insert into public.user_sync_state (user_id) values (?) on conflict (user_id) do nothing', [$userId]);
        DB::select('select 1 from public.user_sync_state where user_id = ? for update', [$userId]);

        $sortOrder = 0;

        foreach (DefaultCategories::ITEMS as $systemKey => $kind) {
            DB::statement(
                'insert into public.categories (id, user_id, kind, system_key, sort_order) '
                .'values (?, ?, ?, ?, ?) on conflict do nothing',
                [DefaultCategories::idFor($userId, $systemKey), $userId, $kind, $systemKey, $sortOrder++]
            );
        }

        self::assertAllPresent($userId);
    }

    /**
     * "on conflict do nothing" também esconde um id já usado por outra conta (a PK
     * é global). Confere o resultado, contando as excluídas para não revivê-las.
     */
    private static function assertAllPresent(string $userId): void
    {
        $expected = array_keys(DefaultCategories::ITEMS);

        $present = DB::table('categories')
            ->where('user_id', $userId)
            ->whereIn('system_key', $expected)
            ->pluck('system_key')
            ->all();

        $missing = array_diff($expected, $present);

        if ($missing !== []) {
            throw new RuntimeException('Categorias padrão não criadas (id já ocupado?): '.implode(', ', $missing));
        }
    }
}
