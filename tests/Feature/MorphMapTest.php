<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class MorphMapTest extends TestCase
{
    public function test_morph_types_are_stored_as_aliases_not_class_names(): void
    {
        $this->assertSame('user', (new User)->getMorphClass());
    }
}
