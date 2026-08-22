<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnsureApplicationKeyCommandTest extends TestCase
{
    private string $environmentDirectory;

    private string $originalEnvironmentPath;

    private string $originalEnvironmentFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironmentPath = $this->app->environmentPath();
        $this->originalEnvironmentFile = $this->app->environmentFile();
        $this->environmentDirectory = storage_path('framework/testing/app-key-'.Str::uuid());

        File::ensureDirectoryExists($this->environmentDirectory);
        $this->app->useEnvironmentPath($this->environmentDirectory)->loadEnvironmentFrom('.env');
    }

    protected function tearDown(): void
    {
        $this->app
            ->useEnvironmentPath($this->originalEnvironmentPath)
            ->loadEnvironmentFrom($this->originalEnvironmentFile);
        File::deleteDirectory($this->environmentDirectory);

        parent::tearDown();
    }

    public function test_empty_key_is_generated_once_and_a_second_run_preserves_it(): void
    {
        $environmentFile = $this->environmentDirectory.DIRECTORY_SEPARATOR.'.env';
        File::put($environmentFile, "APP_NAME=Project Desk\nAPP_KEY=\nAPP_ENV=local\n");

        $this->artisan('project-desk:ensure-app-key')
            ->expectsOutputToContain('Application key generated successfully.')
            ->assertSuccessful();

        $firstContents = File::get($environmentFile);
        $generatedKey = $this->extractKey($firstContents);
        $decodedKey = base64_decode(substr($generatedKey, strlen('base64:')), true);

        $this->assertStringStartsWith('base64:', $generatedKey);
        $this->assertIsString($decodedKey);
        $this->assertSame(32, strlen($decodedKey));

        $this->artisan('project-desk:ensure-app-key')
            ->expectsOutputToContain('Application key is already configured; it was left unchanged.')
            ->assertSuccessful();

        $this->assertSame($firstContents, File::get($environmentFile));
    }

    public function test_existing_key_and_composer_setup_contract_are_preserved(): void
    {
        $environmentFile = $this->environmentDirectory.DIRECTORY_SEPARATOR.'.env';
        $existingKey = 'base64:'.base64_encode(str_repeat('k', 32));
        $originalContents = "APP_KEY={$existingKey}\nAPP_ENV=production\n";
        File::put($environmentFile, $originalContents);

        $this->artisan('project-desk:ensure-app-key')->assertSuccessful();

        $this->assertSame($originalContents, File::get($environmentFile));

        $composer = json_decode(File::get(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $setup = $composer['scripts']['setup'];
        $postCreate = $composer['scripts']['post-create-project-cmd'];

        $this->assertContains('@php artisan project-desk:ensure-app-key', $setup);
        $this->assertContains('@php artisan project-desk:ensure-app-key --ansi', $postCreate);
        $this->assertFalse(collect([...$setup, ...$postCreate])->contains(
            fn (string $step): bool => str_contains($step, 'key:generate'),
        ));
    }

    private function extractKey(string $contents): string
    {
        $matched = preg_match('/^APP_KEY=(?<key>[^\r\n]+)$/m', $contents, $matches);

        $this->assertSame(1, $matched);

        return $matches['key'];
    }
}
