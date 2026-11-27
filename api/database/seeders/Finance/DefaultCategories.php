<?php

namespace Database\Seeders\Finance;

use Ramsey\Uuid\Uuid;

/**
 * Catálogo das categorias padrão (financas.md §3.6).
 * No banco só vão system_key e kind; name fica nulo e a tela traduz pela chave
 * "categories.<system_key>" (arquivos em ../i18n).
 */
final class DefaultCategories
{
    /**
     * Namespace UUIDv5 do app (financas.md §4.3). É público e fixo: a API e a web
     * precisam usar o mesmo valor, e ele nunca muda depois do primeiro uso.
     */
    public const UUID_NAMESPACE = 'bea5d7e4-d920-42eb-a455-3d5ac8f6abca';

    /** system_key => kind. A ordem define sort_order. */
    public const ITEMS = [
        'expense.housing' => 'expense',
        'expense.food' => 'expense',
        'expense.transport' => 'expense',
        'expense.health' => 'expense',
        'expense.education' => 'expense',
        'expense.leisure' => 'expense',
        'expense.shopping' => 'expense',
        'expense.subscriptions' => 'expense',
        'expense.fees_taxes' => 'expense',
        'expense.other' => 'expense',
        'income.salary' => 'income',
        'income.freelance' => 'income',
        'income.refunds' => 'income',
        'income.other' => 'income',
    ];

    /** Id determinístico: UUIDv5 de "category|user_id|system_key", com user_id em minúsculas. */
    public static function idFor(string $userId, string $systemKey): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, 'category|'.strtolower($userId).'|'.$systemKey)->toString();
    }
}
