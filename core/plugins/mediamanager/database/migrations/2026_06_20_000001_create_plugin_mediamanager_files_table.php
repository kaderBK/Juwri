<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_mediamanager_files', function (Blueprint $table) {
            $table->id();
            $table->string('directory');
            $table->string('filename');
            $table->string('full_path');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('modified_at')->nullable();
            $table->boolean('is_referenced')->default(false);
            $table->string('reference_table')->nullable();
            $table->string('reference_column')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_name')->nullable();       // Human-readable name of referenced record
            $table->string('reference_owner')->nullable();      // Human-readable owner (e.g. restaurant name)
            $table->boolean('is_duplicate')->default(false);
            $table->unsignedBigInteger('duplicate_group_id')->nullable();
            $table->boolean('is_protected')->default(false);
            $table->string('status')->default('unknown'); // used, orphaned, unknown
            $table->timestamp('scanned_at')->useCurrent();
            $table->timestamps();

            $table->index(['directory', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['is_referenced', 'status']);
            $table->index('is_duplicate');
            $table->index('duplicate_group_id');
            $table->index('is_protected');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_mediamanager_files');
    }
};
