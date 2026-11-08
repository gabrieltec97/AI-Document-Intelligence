<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->string('section')->nullable()->after('content');
            $table->string('company')->nullable()->after('section');
            $table->string('position')->nullable()->after('company');

            $table->index(['section', 'company']);
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex(['section', 'company']);

            $table->dropColumn([
                'section',
                'company',
                'position',
            ]);
        });
    }
};
