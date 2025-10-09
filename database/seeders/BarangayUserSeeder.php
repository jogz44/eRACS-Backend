<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BarangayUser;
use App\Models\Barangay;
use App\Models\BarangayPosition;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class BarangayUserSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();

        $barangays = Barangay::all();
        $positions = BarangayPosition::all();

        if ($positions->isEmpty()) {
            throw new \Exception('No positions found. Please run BarangayPositionSeeder first.');
        }

        // 6 fixed people names (rotating across barangays)
        $people = [
            ['first_name' => 'Reidh', 'middle_name' => 'D.', 'last_name' => 'Maze'],
            ['first_name' => 'Dan Steve', 'middle_name' => 'P.', 'last_name' => 'Bermejo'],
            ['first_name' => 'Kristine Cielo', 'middle_name' => 'B.', 'last_name' => 'Garcia'],
            ['first_name' => 'Michael June', 'middle_name' => 'M.', 'last_name' => 'Llano'],
            ['first_name' => 'Jan Paul', 'middle_name' => 'M.', 'last_name' => 'Sansano'],
            ['first_name' => 'Kobe Bryant', 'middle_name' => 'T.', 'last_name' => 'Tubo'],
        ];

        $map = [
            'Barangay Captain'      => 'captain',
            'SK Chairperson'  => 'skc',
            'SK Treasurer' => 'skt',
            'Barangay Treasurer'    => 'treasurer',
        ];

        foreach ($barangays as $barangay) {
            // Pick 6 random positions for this barangay (no duplicate positions)
            $randomPositions = $positions->random(4);
            shuffle($people);
            foreach ($randomPositions as $index => $position) {
                $person = $people[$index];

                BarangayUser::firstOrCreate([
                    'username' => strtolower(str_replace(' ', '', $barangay->name)) . '_' . $map[$position->name] ?? strtolower(str_replace(' ', '', $position->name)),
                ], [
                    'first_name' => $person['first_name'],
                    'middle_name' => $person['middle_name'],
                    'last_name' => $person['last_name'],
                    'barangay_id' => $barangay->id,
                    'position_id' => $position->id,
                    'suffix' => $faker->optional()->suffix,
                    'photo_path' => 'profile-photos/default.png',
                    'email' => strtolower(str_replace(' ', '', $barangay->name)) . '_' . strtolower(str_replace(' ', '', $position->name)) . '@example.com',
                    'password' => Hash::make('password123'),
                    'role' => 'barangay_user',
                    'is_approved' => true,
                    'permissions' =>  [
                        "view" => true,
                        "add" => true,
                        "edit" => true,
                        "delete" => true,
                        "print" => true,
                    ],
                ]);
            }
        }
    }
}