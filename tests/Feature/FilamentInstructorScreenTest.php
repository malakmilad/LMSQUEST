<?php

use App\Filament\Resources\InstructorResource;
use App\Models\Instructor;
use App\Models\User;

it('lets an admin view instructor balances and payout history', function () {
    $admin = User::factory()->admin()->create();
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    $this->actingAs($admin)
        ->get(InstructorResource::getUrl('index'))
        ->assertOk()
        ->assertSee($instructor->user->name)
        ->assertSee('Available');

    $this->actingAs($admin)
        ->get(InstructorResource::getUrl('view', ['record' => $instructor]))
        ->assertOk()
        ->assertSee('Payout history')
        ->assertSee('Ledger');
});

it('blocks non-admins from the panel', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($student)
        ->get(InstructorResource::getUrl('index'))
        ->assertForbidden();
});
