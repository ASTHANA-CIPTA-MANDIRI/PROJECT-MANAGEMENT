<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * users.email lost its unique index in 2022_11_09_110740_remove_unique_from_users.php
 * and 2023_01_12_203211_remove_unique_constraint_from_users.php, and it was never
 * restored. Uniqueness has only been enforced at the application layer (the
 * `unique:users,email` validation rule) since, so a race between two concurrent
 * registrations/imports can create two accounts sharing an email — ambiguous for
 * login and password-reset lookups, which resolve a user by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->disambiguateDuplicateEmails();

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    /**
     * Renames (never deletes) duplicate accounts: users rows are referenced by many
     * foreign keys (tickets, comments, timesheet hours, ...), so silently deleting
     * one could orphan or cascade-delete unrelated data. The oldest account per
     * email keeps its address; later duplicates get a disambiguated but still
     * valid-looking email (plus-addressed) and are logged so an admin can manually
     * merge or contact the affected user.
     */
    private function disambiguateDuplicateEmails(): void
    {
        $seen = [];

        DB::table('users')->orderBy('id')->get(['id', 'email'])
            ->each(function ($row) use (&$seen) {
                $key = mb_strtolower($row->email);

                if (! isset($seen[$key])) {
                    $seen[$key] = $row->id;

                    return;
                }

                $newEmail = $this->disambiguate($row->email, $row->id);

                Log::warning('Migrasi unique-index users.email: mengganti email duplikat', [
                    'user_id' => $row->id,
                    'kept_id' => $seen[$key],
                    'old_email' => $row->email,
                    'new_email' => $newEmail,
                ]);

                DB::table('users')->where('id', $row->id)->update(['email' => $newEmail]);
            });
    }

    private function disambiguate(string $email, int $id): string
    {
        if (! str_contains($email, '@')) {
            return $email.'+dup'.$id;
        }

        [$local, $domain] = explode('@', $email, 2);

        return $local.'+dup'.$id.'@'.$domain;
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
