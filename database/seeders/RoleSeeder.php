<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * System role templates (society_id = null). When a new society is
     * created, SocietyService::provision() (see next build step) copies
     * these into society-scoped rows so each society admin can then edit
     * their own copy independently without affecting other societies.
     */
    public function run(): void
    {
        $auditor = Role::updateOrCreate(
            ['society_id' => null, 'slug' => 'auditor'],
            ['name' => 'Auditor', 'is_system' => true]
        );
        $auditor->permissions()->sync(
            Permission::whereIn('key', [
                'ledger.view', 'members.view', 'loans.view',
                'deductions.view', 'payments.view', 'reports.view', 'comments.create',
            ])->pluck('id')
        );

        $loanOfficer = Role::updateOrCreate(
            ['society_id' => null, 'slug' => 'loan-officer'],
            ['name' => 'Loan Officer', 'is_system' => true]
        );
        $loanOfficer->permissions()->sync(
            Permission::whereIn('key', [
                'loans.view', 'loans.recommend', 'members.view', 'comments.create',
            ])->pluck('id')
        );

        // Default template roles for the approval chain (blueprint §7.1).
        // Society Admin already has an implicit permission bypass and needs
        // no explicit role here — these two cover the roles it doesn't.
        $treasurer = Role::updateOrCreate(
            ['society_id' => null, 'slug' => 'treasurer'],
            ['name' => 'Treasurer', 'is_system' => true]
        );
        $treasurer->permissions()->sync(
            Permission::whereIn('key', [
                'loans.view', 'loans.recommend', 'loans.disburse',
                'members.view', 'ledger.view', 'comments.create',
            ])->pluck('id')
        );

        $approver = Role::updateOrCreate(
            ['society_id' => null, 'slug' => 'approver'],
            ['name' => 'Approver', 'is_system' => true]
        );
        $approver->permissions()->sync(
            Permission::whereIn('key', [
                'loans.view', 'loans.approve', 'members.view', 'comments.create',
            ])->pluck('id')
        );

        $bookkeeper = Role::updateOrCreate(
            ['society_id' => null, 'slug' => 'bookkeeper'],
            ['name' => 'Bookkeeper', 'is_system' => true]
        );
        $bookkeeper->permissions()->sync(
            Permission::whereIn('key', [
                'ledger.view', 'ledger.edit', 'members.view',
                'deductions.view', 'deductions.manage', 'payments.view', 'payments.manage',
                'reports.view', 'comments.create',
            ])->pluck('id')
        );
    }
}
