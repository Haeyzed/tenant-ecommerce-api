<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spreadsheet imports (D-135). The uploaded file is a private media item of
| the import. last_row is the checkpoint: each row and the checkpoint
| commit together, so a retried job resumes after it and never applies a
| row twice.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('import_type', 32);
            $table->string('mode', 16)->default('upsert');
            $table->string('status', 24)->default('queued');
            $table->string('original_filename', 255);
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('last_row')->default(1);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('data_import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_import_id')->constrained('data_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('field', 64)->nullable();
            $table->string('message', 500);
            $table->string('value', 255)->nullable();
            $table->timestamps();

            $table->index(['data_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_errors');
        Schema::dropIfExists('data_imports');
    }
};
