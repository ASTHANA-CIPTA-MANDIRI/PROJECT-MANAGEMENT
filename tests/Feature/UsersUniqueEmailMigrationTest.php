<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersUniqueEmailMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_email_is_unique_at_the_database_level(): void
    {
        $this->insertUser('dup@example.com');

        $this->expectException(QueryException::class);

        $this->insertUser('dup@example.com');
    }

    public function test_pre_existing_duplicate_emails_are_disambiguated_and_logged_before_the_index_is_added(): void
    {
        // Simulate a DB that still has pre-migration duplicates by temporarily
        // dropping the unique index this migration itself adds.
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['email']));

        $keptId = $this->insertUser('dup2@example.com');
        $renamedId = $this->insertUser('dup2@example.com');

        Log::spy();

        $migration = require database_path('migrations/2026_08_05_000003_add_unique_index_to_users_email.php');
        $migration->up();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($keptId, $renamedId) {
                return str_contains($message, 'mengganti email duplikat')
                    && $context['user_id'] === $renamedId
                    && $context['kept_id'] === $keptId
                    && $context['old_email'] === 'dup2@example.com';
            });

        $this->assertSame('dup2@example.com', DB::table('users')->where('id', $keptId)->value('email'));
        $this->assertSame('dup2+dup'.$renamedId.'@example.com', DB::table('users')->where('id', $renamedId)->value('email'));

        $this->expectException(QueryException::class);
        $this->insertUser('dup2@example.com');
    }

    private function insertUser(string $email): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
