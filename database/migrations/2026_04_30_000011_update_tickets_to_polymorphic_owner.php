<?php

use App\Models\Student;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->nullableMorphs('owner');
        });

        DB::table('tickets')
            ->whereNotNull('cicid')
            ->orderBy('id')
            ->chunkById(200, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $student = Student::query()
                        ->where('cicid', (string) $ticket->cicid)
                        ->first();

                    if (!$student) {
                        continue;
                    }

                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update([
                            'owner_type' => Student::class,
                            'owner_id' => $student->getKey(),
                        ]);
                }
            });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_cicid_index');
            $table->dropIndex('tickets_cicid_status_index');
            $table->dropColumn('cicid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('cicid')->nullable()->index();
        });

        DB::table('tickets')
            ->where('owner_type', Student::class)
            ->whereNotNull('owner_id')
            ->orderBy('id')
            ->chunkById(200, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $student = Student::query()->find($ticket->owner_id);

                    if (!$student) {
                        continue;
                    }

                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update([
                            'cicid' => $student->cicid,
                        ]);
                }
            });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropMorphs('owner');
            $table->index(['cicid', 'status']);
        });
    }
};