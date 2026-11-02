<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('osync.connection');
    }

    public function up(): void
    {
        Schema::create('osync_scopes', function (Blueprint $table): void {
            $table->string('scope', 191)->primary();
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('compacted_through')->default(0);
        });

        Schema::create('osync_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191);
            $table->string('collection', 64);
            $table->string('record_id', 191);
            $table->unsignedBigInteger('version');
            $table->string('op', 10);
            $table->json('data')->nullable();
            $table->string('hlc', 96);
            $table->timestamp('created_at');

            $table->unique(['scope', 'collection', 'record_id']);
            $table->index(['scope', 'collection', 'version']);
            $table->index(['op', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osync_changes');
        Schema::dropIfExists('osync_scopes');
    }
};
