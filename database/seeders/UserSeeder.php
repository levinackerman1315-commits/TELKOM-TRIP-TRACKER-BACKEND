<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password123');

        DB::table('users')->updateOrInsert(
            ['email' => 'budi@telkomakses.co.id'],
            ['password' => $password]
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'ahmad@telkomakses.co.id'],
            ['password' => $password]
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'hendra@telkomakses.co.id'],
            ['password' => $password]
        );
    }
}