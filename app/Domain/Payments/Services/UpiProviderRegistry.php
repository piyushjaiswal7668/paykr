<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

final class UpiProviderRegistry
{
    /** @return list<array{id: string, name: string, shortName: string, packageName: string, iconUrl: string, brandColor: string, searchKeywords: list<string>, enabled: bool, svgLogo: string, localIconUrl: string}> */
    public function all(): array
    {
        return config('upi_providers');
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->find('id', $id);
    }

    /** @return array<string, mixed>|null */
    public function findByPackage(string $package): ?array
    {
        return $this->find('packageName', $package);
    }

    /**
     * @param  'id'|'packageName'  $field
     * @return array<string, mixed>|null
     */
    private function find(string $field, string $value): ?array
    {
        $value = strtolower(trim($value));
        foreach ($this->all() as $provider) {
            if (strtolower($provider[$field]) === $value) {
                return $provider;
            }
        }

        return null;
    }
}
