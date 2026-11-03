<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The stories (class feed): a teacher or the principal shares news and photos with a class, a grade or the whole school;
// students and parents can react and comment.
return new class extends Migration
{
    public function up(): void
    {
        // The first version of the schema (never used by any screen) had `stories` with a caption and `story_media` with a type: replace
        // them, refusing to touch them if somebody did put data there.
        if (Schema::hasTable('stories') && Schema::hasColumn('stories', 'caption')) {
            if (DB::table('stories')->exists()) {
                throw new RuntimeException('The old stories table has rows: migrate them by hand first.');
            }
            Schema::dropIfExists('story_media');
            Schema::dropIfExists('stories');
        }
        if (! Schema::hasTable('stories')) {
            Schema::create('stories', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('author_id')->constrained('users')->cascadeOnDelete();
                $t->string('audience', 10); // classroom | grade | school
                $t->foreignId('classroom_id')->nullable()->constrained('classrooms')->cascadeOnDelete();
                $t->foreignId('grade_level_id')->nullable()->constrained('grade_levels')->cascadeOnDelete();
                $t->text('body')->nullable();
                $t->boolean('is_pinned')->default(false);
                $t->boolean('comments_enabled')->default(true);
                $t->timestamps();
                $t->index(['audience', 'created_at']);
            });
            Schema::create('story_media', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('story_id')->constrained()->cascadeOnDelete();
                $t->string('path');
                $t->unsignedSmallInteger('position')->default(1);
                $t->timestamps();
            });
            Schema::create('story_reactions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('story_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['story_id', 'user_id']);
            });
            Schema::create('story_comments', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('story_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('body', 500);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['story_comments', 'story_reactions', 'story_media', 'stories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
