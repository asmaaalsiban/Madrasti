<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->enum('type', ['direct', 'group']);
            $t->string('title')->nullable();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete(); // class group chat
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('last_read_at')->nullable();
            $t->unique(['conversation_id', 'user_id']);
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users');
            $t->text('body')->nullable();
            $t->string('attachment_path')->nullable();
            $t->index(['conversation_id', 'created_at']);
            $t->timestamps();
            $t->softDeletes();
        });

        // Read receipts
        Schema::create('message_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at')->useCurrent();
            $t->unique(['message_id', 'user_id']);
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('author_id')->constrained('users');
            $t->string('title');
            $t->text('body');
            $t->enum('audience', ['all', 'role', 'classroom']);
            $t->enum('audience_role', ['admin', 'teacher', 'student', 'parent'])->nullable();
            $t->foreignId('classroom_id')->nullable()->constrained()->cascadeOnDelete();
            $t->boolean('is_pinned')->default(false);
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('stories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('author_id')->constrained('users');
            $t->foreignId('classroom_id')->nullable()->constrained()->cascadeOnDelete(); // null = whole school
            $t->text('caption')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('story_media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('story_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['image', 'video']);
            $t->string('path');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['story_media', 'stories', 'announcements', 'message_reads', 'messages',
                  'conversation_participants', 'conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
