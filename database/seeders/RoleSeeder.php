<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * System role templates (society_id = null). Each new society gets its own
     * copy of every template, which its admin can then edit independently.
     */
    public function run(): void
    {
        // Holder of the first review step. Society admins are given this role.
        $this->template('society-admin', 'Society Admin', [
            'loans.view', 'loans.recommend', 'members.view', 'ledger.view', 'comments.create',
        ]);

        $this->template('auditor', 'Auditor', [
            'ledger.view', 'members.view', 'loans.view',
            'deductions.view', 'payments.view', 'reports.view', 'comments.create',
        ]);

        $this->template('loan-officer', 'Loan Officer', [
            'loans.view', 'loans.recommend', 'members.view', 'comments.create',
        ]);

        $this->template('bookkeeper', 'Bookkeeper', [
            'ledger.view', 'ledger.edit', 'members.view',
            'deductions.view', 'deductions.manage', 'payments.view', 'payments.manage',
            'reports.view', 'comments.create',
        ]);

        $this->template('treasurer', 'Treasurer', [
            'loans.view', 'loans.recommend', 'loans.disburse',
            'members.view', 'ledger.view', 'comments.create',
        ]);

        $this->template('approver', 'Approver', [
            'loans.view', 'loans.approve', 'members.view', 'comments.create',
        ]);
    }

    private function template(string $slug, string $name, array $permissionKeys): void
    {
        $role = Role::updateOrCreate(
            ['society_id' => null, 'slug' => $slug],
            ['name' => $name, 'is_system' => true]
        );

        $role->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));
    }
}