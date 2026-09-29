<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Reparent Test', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    // Pick a non-main account we can reparent, and a sibling to move under.
    $this->rawMaterialParent = Account::where('account_code', '11')->sole();
    $this->rawMaterial = Account::where('account_code', '111')->sole();
    $this->containerItem = Account::where('account_code', '12')->sole();

    // Create an extra account of type asset under 12 so we have two
    // siblings to reparent between.
    $this->altParent = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => $this->containerItem->id,
        'account_code' => '1250',
        'name' => 'بطاقات مدفوعة مقدماً',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $this->leaf = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => $this->rawMaterial->id,
        'account_code' => '1115',
        'name' => 'مواد خام بديلة',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    // Authenticated user for the PATCH /accounts/{id}/parent routes.
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    $this->user = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => null,
    ]);
});

test('reparent: happy path moves the account under the new parent', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $this->altParent->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.parent_account_id', $this->altParent->id)
        ->assertJsonPath('data.is_main', false)
        ->assertJsonPath('data.parent.id', $this->altParent->id);

    expect($this->leaf->fresh()->parent_account_id)->toBe($this->altParent->id);
});

test('reparent: existing children of the reparented account stay attached', function () {
    $child = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => $this->leaf->id,
        'account_code' => '11151',
        'name' => 'child of leaf',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $this->altParent->id,
        ])->assertOk();

    expect($child->fresh()->parent_account_id)->toBe($this->leaf->id);
});

test('reparent: account with journal lines (movement) is allowed', function () {
    $entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'source_document_type' => 'TestEntry',
        'source_document_id' => $this->leaf->id,
        'description' => 'opening',
        'reference' => 'JE-OPENING',
        'entry_date' => now(),
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $this->leaf->id,
        'operating_unit_id' => null,
        'debit' => 100.0,
        'credit' => 0.0,
        'memo' => 'opening',
    ]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $this->altParent->id,
        ])->assertOk();

    expect($this->leaf->fresh()->parent_account_id)->toBe($this->altParent->id);
});

test('reparent: self-parent is blocked (PARENT_SAME_ACCOUNT)', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $this->leaf->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_account_id');

    $errors = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $this->leaf->id,
        ])->json('errors.parent_account_id');
    expect($errors[0])->toContain('PARENT_SAME_ACCOUNT');
});

test('reparent: different type is blocked (PARENT_WRONG_TYPE)', function () {
    $revenue = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => Account::where('account_code', '41')->sole()->id,
        'account_code' => '4150',
        'name' => 'إيرادات أخرى',
        'type' => 'revenue',
        'section' => 'sales',
        'currency' => 'LYD',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $revenue->id,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('parent_account_id');
    expect($response->json('errors.parent_account_id.0'))->toContain('PARENT_WRONG_TYPE');
});

test('reparent: parent in a different chart is blocked (PARENT_WRONG_CHART)', function () {
    $otherChart = ChartOfAccounts::create([
        'company_id' => $this->company->id,
        'name' => 'دليل آخر',
    ]);
    $orphan = Account::create([
        'chart_of_accounts_id' => $otherChart->id,
        'parent_account_id' => null,
        'account_code' => '9000',
        'name' => 'حساب في دليل آخر',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $orphan->id,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('parent_account_id');
    expect($response->json('errors.parent_account_id.0'))->toContain('PARENT_WRONG_CHART');
});

test('reparent: a child of the account is blocked as the new parent (PARENT_CYCLE direct)', function () {
    $child = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => $this->leaf->id,
        'account_code' => '11151',
        'name' => 'child of leaf',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $child->id,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('parent_account_id');
    expect($response->json('errors.parent_account_id.0'))->toContain('PARENT_CYCLE');
});

test('reparent: a grandchild of the account is blocked as the new parent (PARENT_CYCLE deep)', function () {
    $child = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => $this->leaf->id,
        'account_code' => '11151',
        'name' => 'child of leaf',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);
    $grandchild = Account::create([
        'chart_of_accounts_id' => $this->rawMaterial->chart_of_accounts_id,
        'parent_account_id' => $child->id,
        'account_code' => '111511',
        'name' => 'grandchild of leaf',
        'type' => 'asset',
        'section' => 'current_assets',
        'currency' => 'LYD',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $grandchild->id,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('parent_account_id');
    expect($response->json('errors.parent_account_id.0'))->toContain('PARENT_CYCLE');
});

test('reparent: main account is blocked (MAIN_ACCOUNT_NOT_REPARENTABLE)', function () {
    $main = Account::where('parent_account_id', null)
        ->where('account_code', '1')
        ->sole();

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$main->id}/parent", [
            'parent_account_id' => $this->rawMaterial->id,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('parent_account_id');
    expect($response->json('errors.parent_account_id.0'))->toContain('MAIN_ACCOUNT_NOT_REPARENTABLE');
});

test('reparent: non-existent parent is rejected by the exists rule', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => '00000000-0000-0000-0000-000000000000',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_account_id');
});

test('reparent: empty branch is allowed (old parent may end up with no children)', function () {
    // Move `leaf` away from its current parent; the old parent (rawMaterial)
    // should not be blocked from becoming empty.
    $oldParent = $this->leaf->parent_account_id;
    expect($oldParent)->toBe($this->rawMaterial->id);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/accounts/{$this->leaf->id}/parent", [
            'parent_account_id' => $this->altParent->id,
        ])->assertOk();

    // Old parent no longer has `leaf` as a child.
    expect($this->rawMaterial->fresh()->children()->where('id', $this->leaf->id)->exists())->toBeFalse();
});
