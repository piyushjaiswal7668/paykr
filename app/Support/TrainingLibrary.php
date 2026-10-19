<?php

declare(strict_types=1);

namespace App\Support;

final class TrainingLibrary
{
    /** @return list<array{id: string, title: string, audience: string}> */
    public function lessons(bool $admin): array
    {
        $file = storage_path('app/private/training/catalog.json');
        $lessons = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];

        return array_values(array_filter($lessons, fn (array $lesson): bool => ($admin || $lesson['audience'] !== 'admin') && is_file($this->path($lesson['audience'], $lesson['id']))));
    }

    public function path(string $audience, string $id): string
    {
        abort_unless(in_array($audience, ['merchant', 'admin', 'integration'], true) && preg_match('/^[0-9]{2}$/D', $id), 404);

        return storage_path('app/private/training/'.$audience.'/'.$id.'.mp4');
    }
}
