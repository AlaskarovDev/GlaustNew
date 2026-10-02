<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\MailLog;
use App\Models\Reminder;
use App\Models\Shipment;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private function scenario()
    {
        $admin = $this->makeCompany();
        $this->inTenant($admin, function () use ($admin) {
            $cp = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Müştəri', 'country' => 'Azərbaycan']);
            $c = Contract::create(['number' => 'MQ-1', 'contract_date' => today()->subMonth(), 'counterparty_id' => $cp->id, 'kind' => 'sale', 'subject' => 'X',
                'amount' => 1000, 'currency' => 'AZN', 'cbar_rate' => 1, 'amount_azn' => 1000, 'status' => 'active', 'end_date' => today()->addDays(5), 'responsible_id' => $admin->id]);
            $c->payments()->create(['due_date' => today()->subDays(2), 'amount' => 300]);
            Task::create(['title' => 'Bu gün bitməli', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $admin->id, 'due_date' => today()]);
            Task::create(['title' => 'Gecikmiş iş', 'status' => 'in_progress', 'priority' => 'medium', 'assignee_id' => $admin->id, 'due_date' => today()->subDays(3)]);
            Shipment::create(['number' => 'Y-1', 'direction' => 'import', 'origin' => 'A', 'destination' => 'B', 'transport_mode' => 'road', 'status' => 'in_transit', 'eta' => today()->subDay(), 'responsible_id' => $admin->id]);
        });

        return $admin;
    }

    public function test_reminders_are_generated_once_and_emailed_once(): void
    {
        Mail::fake();
        $admin = $this->scenario();
        $this->travelTo(today()->setTime(10, 0));

        $this->artisan('glaust:reminders')->assertSuccessful();
        $first = $this->inTenant($admin, fn () => Reminder::pluck('source')->sort()->values()->all());
        $this->assertSame(['contract_end', 'contract_payment', 'shipment_delay', 'task_due'], $first);

        // Running again (cron every 5 minutes) creates and sends nothing new.
        $this->artisan('glaust:reminders')->assertSuccessful();
        $this->artisan('glaust:reminders')->assertSuccessful();
        $this->assertSame(4, $this->inTenant($admin, fn () => Reminder::count()));
        $this->assertSame(1, MailLog::withoutGlobalScopes()->where('kind', 'reminder')->count(), 'one grouped mail per user, once');
        $this->assertSame(4, $this->inTenant($admin, fn () => Reminder::whereNotNull('emailed_at')->count()));
    }

    public function test_users_who_opted_out_get_no_reminder_mail(): void
    {
        Mail::fake();
        $admin = $this->scenario();
        $admin->forceFill(['email_reminders' => false])->save();
        $this->travelTo(today()->setTime(10, 0));
        $this->artisan('glaust:reminders')->assertSuccessful();
        $this->assertSame(0, MailLog::withoutGlobalScopes()->where('kind', 'reminder')->count());
        $this->assertSame(4, $this->inTenant($admin, fn () => Reminder::count()), 'header still shows them');
    }

    public function test_header_my_day_and_reminder_actions(): void
    {
        $admin = $this->scenario();
        $this->travelTo(today()->setTime(10, 0));
        $this->artisan('glaust:reminders');

        $data = $this->actingAs($admin)->getJson(route('ajax.my-day'))->assertOk()->json();
        $this->assertSame(2, $data['counts']['tasks']);
        $this->assertSame(1, $data['counts']['overdue']);
        $this->assertSame(4, $data['counts']['reminders']);
        $this->assertSame('Gecikmiş iş', $data['tasks']['overdue'][0]['title']);

        $id = $data['reminders'][0]['id'];
        $this->postJson(route('ajax.reminders.snooze', $id), ['minutes' => 60])->assertJson(['ok' => true]);
        $this->assertSame(3, $this->getJson(route('ajax.my-day'))->json('counts.reminders'));
        $this->postJson(route('ajax.reminders.read', $data['reminders'][1]['id']))->assertJson(['ok' => true]);
        $this->assertSame(2, $this->getJson(route('ajax.my-day'))->json('counts.reminders'));

        $task = $data['tasks']['today'][0]['id'];
        $this->postJson(route('ajax.tasks.complete', $task))->assertJson(['ok' => true]);
        $this->assertSame('done', $this->inTenant($admin, fn () => Task::find($task)->status));
    }

    public function test_daily_digest_is_sent_once_per_day_after_its_time(): void
    {
        Mail::fake();
        $admin = $this->scenario();

        $this->travelTo(today()->setTime(7, 0));
        $this->artisan('glaust:digest');
        $this->assertSame(0, MailLog::withoutGlobalScopes()->where('kind', 'digest')->count(), 'not before 08:30');

        $this->travelTo(today()->setTime(9, 0));
        $this->artisan('glaust:digest');
        $this->artisan('glaust:digest');
        $this->assertSame(1, MailLog::withoutGlobalScopes()->where('kind', 'digest')->count());

        $this->travelTo(today()->addDay()->setTime(9, 0));
        $this->artisan('glaust:digest');
        $this->assertSame(2, MailLog::withoutGlobalScopes()->where('kind', 'digest')->count());
    }

    public function test_personal_reminder(): void
    {
        $admin = $this->makeCompany();
        $this->actingAs($admin)->post(route('reminders.store'), ['title' => 'Bankla görüş', 'remind_at' => now()->addHour()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame('personal', $this->inTenant($admin, fn () => Reminder::firstOrFail()->source));
        $this->get(route('my-work'))->assertOk()->assertSee('Bankla görüş');
    }
}
