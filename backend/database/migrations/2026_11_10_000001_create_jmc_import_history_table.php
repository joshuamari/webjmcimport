<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webjmr';

    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection('webjmr');

        if (! $schema->hasTable('jmc_import_history')) {
            $schema->create('jmc_import_history', function (Blueprint $table) {
                $table->char('id', 32)->primary();
                $table->dateTime('imported_at');
                $table->string('filename', 255);
                $table->string('group_name', 100);
                $table->unsignedInteger('added')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->string('result', 32)->default('Completed');
                $table->string('imported_by', 100)->default('');
                $table->unsignedInteger('employee_number')->nullable();
                $table->json('projects')->nullable();
                $table->string('note', 255)->default('');
                $table->index(['group_name', 'imported_at']);
            });
        }

        $this->importLegacyFiles();
    }

    public function down(): void
    {
        Schema::connection('webjmr')->dropIfExists('jmc_import_history');
    }

    private function importLegacyFiles(): void
    {
        $dir = storage_path('app/private/jmc-history');
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }

            $id = (string) ($decoded['id'] ?? '');
            if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
                $id = basename($file, '.json');
            }
            if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
                continue;
            }

            $projects = $decoded['projects'] ?? [];
            if (! is_array($projects)) {
                $projects = [];
            }

            $at = (string) ($decoded['at'] ?? gmdate('c'));
            $timestamp = strtotime($at);
            $employee = $decoded['employeeNumber'] ?? null;

            DB::connection('webjmr')->table('jmc_import_history')->insertOrIgnore([
                'id' => $id,
                'imported_at' => gmdate('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp),
                'filename' => (string) ($decoded['filename'] ?? ''),
                'group_name' => (string) ($decoded['group'] ?? ''),
                'added' => (int) ($decoded['added'] ?? 0),
                'skipped' => (int) ($decoded['skipped'] ?? 0),
                'result' => (string) ($decoded['result'] ?? 'Completed'),
                'imported_by' => (string) ($decoded['importedBy'] ?? ''),
                'employee_number' => $employee === null || $employee === '' ? null : (int) $employee,
                'projects' => json_encode(array_values($projects), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'note' => (string) ($decoded['note'] ?? ''),
            ]);
        }
    }
};
