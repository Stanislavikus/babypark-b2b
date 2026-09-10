<?php

namespace App\Services\Catalog;

use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class TagManager
{
    public function create(string $workspaceId, string $name): Tag
    {
        $displayName = $this->validatedDisplayName($name);
        $this->assertUniqueInWorkspace($workspaceId, $displayName);

        try {
            return Tag::withoutWorkspaceScope()->create([
                'workspace_id' => $workspaceId,
                'name' => $displayName,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'name' => 'Тег із такою назвою вже існує.',
            ]);
        }
    }

    public function rename(string $workspaceId, Tag $tag, string $name): Tag
    {
        if ($tag->workspace_id !== $workspaceId) {
            throw ValidationException::withMessages([
                'name' => 'Тег належить іншому робочому простору.',
            ]);
        }

        $displayName = $this->validatedDisplayName($name);
        $this->assertUniqueInWorkspace($workspaceId, $displayName, $tag);

        try {
            $tag->update(['name' => $displayName]);

            return $tag->fresh();
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'name' => 'Тег із такою назвою вже існує.',
            ]);
        }
    }

    public function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function validatedDisplayName(string $name): string
    {
        $displayName = trim($name);

        if ($displayName === '') {
            throw ValidationException::withMessages([
                'name' => 'Поле назви обов\'язкове.',
            ]);
        }

        if (mb_strlen($displayName) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Поле назви не може містити більше 255 символів.',
            ]);
        }

        return $displayName;
    }

    protected function assertUniqueInWorkspace(string $workspaceId, string $displayName, ?Tag $except = null): void
    {
        $normalized = $this->normalizeName($displayName);

        $existingTags = Tag::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->pluck('name');

        foreach ($existingTags as $existingName) {
            if ($this->normalizeName($existingName) === $normalized) {
                throw ValidationException::withMessages([
                    'name' => 'Тег із такою назвою вже існує.',
                ]);
            }
        }
    }
}
