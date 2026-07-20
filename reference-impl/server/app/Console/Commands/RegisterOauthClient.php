<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class RegisterOauthClient extends Command
{
    protected $signature = 'opr:register-oauth-client {name} {redirect_uri} {--confidential}';

    protected $description = 'Register a SMART-on-FHIR OAuth client. Prints the client id (and, once, the secret for confidential clients).';

    public function handle(): int
    {
        $id = (string) Str::uuid7();
        $secret = $this->option('confidential') ? bin2hex(random_bytes(32)) : null;

        DB::table('oauth_clients')->insert([
            'id' => $id,
            'name' => $this->argument('name'),
            'redirect_uri' => $this->argument('redirect_uri'),
            'confidential' => (bool) $this->option('confidential'),
            'secret_hash' => $secret === null ? null : Hash::make($secret),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("client_id: {$id}");
        if ($secret !== null) {
            $this->warn("client_secret (shown once): {$secret}");
        }

        return self::SUCCESS;
    }
}
