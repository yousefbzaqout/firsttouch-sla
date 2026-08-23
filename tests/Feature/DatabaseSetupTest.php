<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('connects to the database successfully', function (): void {
    $result = DB::select('SELECT 1 AS value');

    expect($result)->toHaveCount(1)
        ->and($result[0]->value)->toBe(1);
});

it('has the pgvector extension available', function (): void {
    $extensions = DB::select("SELECT name FROM pg_available_extensions WHERE name = 'vector'");

    expect($extensions)->toHaveCount(1)
        ->and($extensions[0]->name)->toBe('vector');
});
