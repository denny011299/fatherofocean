<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Marking;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(MasterDataSeeder::class);
        $this->call(LocationSeeder::class);

        $users = [
            ['name' => 'Super Admin', 'username' => 'admin', 'email' => 'admin@pelayaran.test', 'role' => 'super_admin'],
            ['name' => 'Supervisor', 'username' => 'supervisor', 'email' => 'supervisor@pelayaran.test', 'role' => 'supervisor'],
            ['name' => 'Admin CS', 'username' => 'admincs', 'email' => 'cs@pelayaran.test', 'role' => 'admin_cs'],
            ['name' => 'Sales Demo', 'username' => 'sales', 'email' => 'sales@pelayaran.test', 'role' => 'sales'],
            ['name' => 'Finance', 'username' => 'finance', 'email' => 'finance@pelayaran.test', 'role' => 'finance'],
            ['name' => 'Gudang China', 'username' => 'china', 'email' => 'china@pelayaran.test', 'role' => 'admin_gudang_china'],
            ['name' => 'Gudang Indo', 'username' => 'indo', 'email' => 'indo@pelayaran.test', 'role' => 'admin_gudang_indo'],
            ['name' => 'Customer Demo', 'username' => 'customer', 'email' => 'customer@pelayaran.test', 'role' => 'customer', 'marking_code' => 'CUST-001'],
        ];

        foreach ($users as $data) {
            $role = $data['role'];
            unset($data['role']);

            $user = User::query()->firstOrCreate(
                ['username' => $data['username']],
                array_merge($data, ['password' => 'password'])
            );

            $user->syncRoles($role);
        }

        // Seed markings for demo customer
        $customer = User::where('username', 'customer')->first();
        if ($customer) {
            $demoMarkings = ['CUST-001', 'CUST-002', 'BUDI-JKT'];
            foreach ($demoMarkings as $code) {
                Marking::firstOrCreate(['code' => $code], [
                    'user_id' => $customer->id,
                    'description' => "Marking {$code} untuk {$customer->name}",
                ]);
            }
        }
    }
}
