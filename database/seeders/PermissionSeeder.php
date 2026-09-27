<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * The full permission catalog. New capabilities get added here first,
     * then granted to roles — nothing is implicitly permitted except the
     * platform_admin/society_admin bypass in User::hasPermission().
     */
    public function run(): void
    {
        $permissions = [
            ['key' => 'ledger.view',        'group' => 'ledger',      'description' => 'View ledger entries'],
            ['key' => 'ledger.edit',        'group' => 'ledger',      'description' => 'Create or amend ledger entries'],
            ['key' => 'members.view',       'group' => 'members',     'description' => 'View member records'],
            ['key' => 'members.manage',     'group' => 'members',     'description' => 'Create, suspend, restore members'],
            ['key' => 'loans.view',         'group' => 'loans',       'description' => 'View loan applications and balances'],
            ['key' => 'loans.recommend',    'group' => 'loans',       'description' => 'Review and recommend/reject loan applications'],
            ['key' => 'loans.approve',      'group' => 'loans',       'description' => 'Give final approval/rejection on loan applications'],
            ['key' => 'loans.disburse',     'group' => 'loans',       'description' => 'Disburse approved loans'],
            ['key' => 'deductions.view',    'group' => 'deductions',  'description' => 'View deduction batches'],
            ['key' => 'deductions.manage',  'group' => 'deductions',  'description' => 'Upload and distribute deductions'],
            ['key' => 'payments.view',      'group' => 'payments',    'description' => 'View payment history'],
            ['key' => 'payments.manage',    'group' => 'payments',    'description' => 'Allocate payments'],
                        ['key' => 'billing.view',       'group' => 'billing',     'description' => 'View own society subscription and invoices'],
            ['key' => 'reports.view',       'group' => 'reports',     'description' => 'View trial balance and audit reports'],
            ['key' => 'comments.create',    'group' => 'comments',    'description' => 'Add comments/notes to records'],
            ['key' => 'branding.manage',    'group' => 'branding',    'description' => 'Edit society branding colors'],
            ['key' => 'staff.manage',       'group' => 'staff',       'description' => 'Create staff users and assign roles'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['key' => $permission['key']], $permission);
        }
    }
}
