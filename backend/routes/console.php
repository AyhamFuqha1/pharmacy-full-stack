<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Input\InputOption;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-admin', function () {
    $name = $this->option('name') ?: $this->ask('Administrator name');
    $email = $this->option('email') ?: $this->ask('Administrator email');

    if ($this->option('password')) {
        $password = $this->option('password');
        $confirm = $this->option('password_confirmation') ?? $password;
    } else {
        $password = $this->secret('Password');
        $confirm = $this->secret('Confirm password');
    }

    if (! $name || ! $email || ! $password) {
        $this->error('Name, email, and password are required.');

        return 1;
    }

    if ($password !== $confirm) {
        $this->error('Password confirmation does not match.');

        return 1;
    }

    if (! DB::getSchemaBuilder()->hasTable('roles')) {
        $this->error('Roles table is missing. Run migrations first.');

        return 1;
    }

    $administratorRole = Role::query()->where('name', 'Administrator')->first();
    if (! $administratorRole) {
        $this->error('The Administrator role is missing. Run php artisan db:seed first.');

        return 1;
    }

    if (User::where('role', $administratorRole->id)->exists()) {
        $this->error('An administrator already exists.');

        return 1;
    }

    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'role' => $administratorRole->id,
        'force_change_password' => false,
    ]);

    $this->info('Administrator created successfully.');
    $this->line('Name: '.$user->name);
    $this->line('Email: '.$user->email);

    return 0;
})->purpose('Create the first administrator account')
    ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Administrator name')
    ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Administrator email')
    ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Administrator password')
    ->addOption('password_confirmation', null, InputOption::VALUE_REQUIRED, 'Administrator password confirmation');

if (config('backup.schedule') === 'daily') {
    Schedule::command('backup:create')->daily()->withoutOverlapping();
}
