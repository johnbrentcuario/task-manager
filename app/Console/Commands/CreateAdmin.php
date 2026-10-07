<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {name : Full name} {email : Email address}';

    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        $password = $this->secret('Password (minimum 8 characters)');
        $confirmation = $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $password,
        ]);
        $user->role = User::ROLE_ADMIN;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}