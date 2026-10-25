<?php

namespace Tests\Feature\Inventory;

use App\Models\IncidentAction;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class IncidentActionTest extends TestCase
{
    public function test_an_action_is_overdue_only_after_its_due_date_in_amsterdam(): void
    {
        $action = new IncidentAction(['due_on' => '2026-10-22']);

        // 23:30 UTC on the 21st is already the 22nd in Amsterdam: due today, not overdue
        Date::setTestNow(Date::parse('2026-10-21 23:30:00', 'UTC'));
        $this->assertFalse($action->isOverdue());

        Date::setTestNow(Date::parse('2026-10-22 22:30:00', 'UTC')); // 00:30 on the 23rd in Amsterdam
        $this->assertTrue($action->isOverdue());
    }

    public function test_a_done_or_undated_action_is_never_overdue(): void
    {
        Date::setTestNow('2026-12-01');

        $this->assertFalse((new IncidentAction(['due_on' => '2026-10-22', 'done_at' => '2026-10-23 10:00:00']))->isOverdue());
        $this->assertFalse((new IncidentAction)->isOverdue());
    }
}
