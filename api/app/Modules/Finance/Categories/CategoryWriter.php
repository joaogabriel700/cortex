<?php

namespace App\Modules\Finance\Categories;

use App\Modules\Finance\Support\RecordWriter;
use App\Modules\Finance\Support\Values;
use App\Support\Http\ProblemException;

class CategoryWriter extends RecordWriter
{
    protected function table(): string
    {
        return 'categories';
    }

    protected function rules(?object $current): array
    {
        return [
            'parent_id' => Values::nullableUuid(),
            'kind' => Values::oneOf(['income', 'expense']),
            // A null name means "translate system_key", so only default categories may have it.
            'name' => Values::text(80, nullable: ($current?->system_key) !== null),
            'icon' => Values::text(40, nullable: true),
            'color' => Values::nullableColor(),
            'sort_order' => Values::int32(),
            'archived_at' => Values::nullableInstant(),
        ];
    }

    /** system_key is created only by the server. */
    protected function guardCreate(array $input): void
    {
        if (($input['system_key'] ?? null) !== null) {
            throw new ProblemException('system_key_reserved', 422);
        }
    }

    protected function normalize(array $data): array
    {
        if (is_string($data['parent_id'])) {
            $data['parent_id'] = strtolower($data['parent_id']);
        }

        return parent::normalize($data);
    }

    protected function checkReferences(string $userId, string $id, array $data, ?object $current): void
    {
        if ($current !== null && $data['kind'] !== $current->kind) {
            throw new ProblemException('identity_field_immutable', 422);
        }

        if ($data['parent_id'] === null) {
            return;
        }

        if ($data['parent_id'] === $id) {
            throw new ProblemException('category_depth', 422);
        }

        $parent = $this->find($userId, $data['parent_id']);

        // The same answer for a missing parent, another user's and another kind.
        if ($parent === null || $parent->kind !== $data['kind']) {
            throw new ProblemException('invalid_reference', 422);
        }
    }

    public function toArray(object $row): array
    {
        return $this->syncColumns($row) + [
            'parent_id' => $row->parent_id,
            'kind' => $row->kind,
            'name' => $row->name,
            'system_key' => $row->system_key,
            'icon' => $row->icon,
            'color' => $row->color,
            'sort_order' => (int) $row->sort_order,
            'archived_at' => Values::instant($row->archived_at),
        ];
    }
}
