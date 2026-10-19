<?php

declare(strict_types=1);

use App\Support\TrainingLibrary;
use Illuminate\Support\Facades\File;

it('lists only available lessons and hides administrator videos from merchants', function () {
    $original = storage_path();
    $temporary = sys_get_temp_dir().'/paykr-training-'.bin2hex(random_bytes(8));
    app()->useStoragePath($temporary);
    try {
        $library = app(TrainingLibrary::class);
        expect($library->lessons(true))->toBe([]);
        $catalog = [];
        foreach (['merchant', 'admin', 'integration'] as $audience) {
            File::ensureDirectoryExists($temporary.'/app/private/training/'.$audience);
            File::put($library->path($audience, '01'), 'video fixture');
            $catalog[] = ['id' => '01', 'title' => $audience.' lesson', 'audience' => $audience];
        }
        $catalog[] = ['id' => '02', 'title' => 'Missing video', 'audience' => 'merchant'];
        File::put($temporary.'/app/private/training/catalog.json', json_encode($catalog));
        expect($library->lessons(true))->toBe(array_slice($catalog, 0, 3));
        expect($library->lessons(false))->toBe([$catalog[0], $catalog[2]]);
    } finally {
        app()->useStoragePath($original);
        File::deleteDirectory($temporary);
    }
});
