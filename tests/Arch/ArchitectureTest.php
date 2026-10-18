<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

arch('controllers stay thin')->expect('App\Http\Controllers')->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Eloquent\Builder', 'App\Models']);
arch('filament uses actions, not models, for writes')->expect('App\Filament')->not->toUse('Illuminate\Support\Facades\DB');
arch('domain is framework-light')->expect('App\Domain')->not->toUse(['Illuminate\Http\Request', 'Filament', 'Livewire']);
arch('dtos are immutable')->expect('App\Domain\*\Data')->toBeFinal()->toBeReadonly();
arch('actions have a single entry point')->expect('App\Domain\*\Actions')->toHaveMethod('handle');
arch('application does not execute shell commands')->expect('App')->not->toUse(['shell_exec', 'exec', 'system', 'passthru', 'popen', 'proc_open']);
arch('no env() outside config')->expect('App')->not->toUse('env');
arch('errors go through the catalog')->expect('App\Domain')->not->toUse('Symfony\Component\HttpKernel\Exception\HttpException');

it('keeps controller methods within fifteen lines', function () {
    foreach (Finder::create()->files()->in(app_path('Http/Controllers'))->name('*.php') as $file) {
        $class = 'App\\Http\\Controllers\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            expect($method->getEndLine() - $method->getStartLine())->toBeLessThanOrEqual(15);
        }
    }
});

it('requires API inputs to use FormRequests and explicit response classes', function () {
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        $method = new ReflectionMethod($route->getControllerClass(), str_contains($route->getActionName(), '@') ? $route->getActionMethod() : '__invoke');
        if (! in_array('GET', $route->methods(), true)) {
            expect(collect($method->getParameters())->contains(fn ($p) => $p->getType() instanceof ReflectionNamedType && is_subclass_of($p->getType()->getName(), FormRequest::class)))->toBeTrue();
        }
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        expect((bool) preg_match('/ApiResponse::|Resource\b/', $source))->toBeTrue();
    }
});

it('requires a merchant scope on merchant-owned models as they are introduced', function () {
    expect(is_dir(app_path('Models')))->toBeTrue();
    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
        $class = 'App\\Models\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
        if (! is_subclass_of($class, Model::class)) {
            continue;
        }
        $model = new $class;
        if (Schema::hasColumn($model->getTable(), 'merchant_id')) {
            expect(class_uses_recursive($class))->toContain('App\Models\Concerns\BelongsToMerchant');
        }
    }
})->uses(RefreshDatabase::class);
