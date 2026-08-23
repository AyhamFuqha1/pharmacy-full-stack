<?php

use Illuminate\Database\Schema\Builder as Schema;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;

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

    $roles = DB::table('roles')->get();
    if ($roles->isEmpty()) {
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Administrator']);
        DB::table('roles')->updateOrInsert(['id' => 2], ['name' => 'Normal User']);
    }

    if (User::where('role', User::ROLE_ADMINISTRATOR)->exists()) {
        $this->error('An administrator already exists.');
        return 1;
    }

    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'role' => User::ROLE_ADMINISTRATOR,
        'force_change_password' => false,
    ]);

    $this->info('Administrator created successfully.');
    $this->line('Name: ' . $user->name);
    $this->line('Email: ' . $user->email);

    return 0;
})->purpose('Create the first administrator account')
    ->addOption('name', null, null, 'Administrator name')
    ->addOption('email', null, null, 'Administrator email')
    ->addOption('password', null, null, 'Administrator password')
    ->addOption('password_confirmation', null, null, 'Administrator password confirmation');
