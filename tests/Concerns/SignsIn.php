<?php

namespace Tests\Concerns;

use App\Models\User;

trait SignsIn
{
    protected function signIn(): User
    {
        $user = User::factory()->withAppAuthentication()->create();
        $this->actingAs($user);

        return $user;
    }
}
