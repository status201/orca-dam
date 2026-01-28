<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class JwtGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:generate
                            {email : Email of the user to generate a JWT secret for}
                            {--force : Regenerate even if secret already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a JWT secret for a user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User not found: {$email}");
            return Command::FAILURE;
        }

        if ($user->hasJwtSecret() && !$this->option('force')) {
            $this->error('User already has a JWT secret. Use --force to regenerate.');
            return Command::FAILURE;
        }

        $isRegenerate = $user->hasJwtSecret();

        // Generate a 64-character random secret (256-bit)
        $secret = Str::random(64);

        $user->update([
            'jwt_secret' => $secret,
            'jwt_secret_generated_at' => now(),
        ]);

        $action = $isRegenerate ? 'regenerated' : 'generated';
        $this->info("JWT secret {$action} successfully!");
        $this->newLine();

        $this->warn('IMPORTANT: Copy this secret now. It will NOT be shown again!');
        $this->newLine();

        $this->line('<fg=green;options=bold>Secret: ' . $secret . '</>');
        $this->newLine();

        $this->info('User Details:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Name', $user->name],
                ['Email', $user->email],
                ['Role', $user->role],
            ]
        );
        $this->newLine();

        $this->info('Example JWT generation (Node.js):');
        $this->line('  const jwt = require("jsonwebtoken");');
        $this->line('  const token = jwt.sign(');
        $this->line("    { sub: {$user->id} },  // User ID");
        $this->line('    "YOUR_SECRET_HERE",  // The secret above');
        $this->line('    { expiresIn: "1h", algorithm: "HS256" }');
        $this->line('  );');
        $this->newLine();

        $this->info('Example JWT generation (PHP):');
        $this->line('  use Firebase\JWT\JWT;');
        $this->line('  $payload = [');
        $this->line("      'sub' => {$user->id},");
        $this->line("      'iat' => time(),");
        $this->line("      'exp' => time() + 3600,");
        $this->line('  ];');
        $this->line('  $token = JWT::encode($payload, $secret, "HS256");');

        return Command::SUCCESS;
    }
}
