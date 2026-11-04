<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'b2bhaat:create-admin
        {email : Email address of the new super admin}
        {--name=Platform Admin : Display name}
        {--phone= : Optional Bangladeshi mobile number}';

    /**
     * @var string
     */
    protected $description = 'Create the first super admin with a prompted password (production-safe replacement for demo seeding)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::where('slug', 'super_admin')->first();
        if (! $role) {
            $this->error('The super_admin role is missing. Run `php artisan db:seed --force` first.');

            return self::FAILURE;
        }

        $user = User::create([
            'role_id' => $role->id,
            'name' => (string) $this->option('name'),
            'email' => $email,
            'phone' => $this->option('phone') ?: null,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $this->info("Super admin #{$user->id} created for {$email}.");

        return self::SUCCESS;
    }
}
