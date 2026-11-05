<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily Microsoft Clarity snapshots for the admin App Analytics page.
     * Clarity's API keeps only 3 days, so history is stored here raw.
     */
    public function up(): void
    {
        Schema::create('clarity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('captured_on')->comment('UTC day the numbers cover');
            $table->string('dimension', 32)->default('')->comment("'' = overall, else OS / Device / Country/Region");
            $table->json('payload')->comment('Raw Clarity Data Export response');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['captured_on', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_snapshots');
    }
};
