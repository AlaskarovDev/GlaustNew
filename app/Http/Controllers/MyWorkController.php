<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyWorkController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $today = CarbonImmutable::today();

        $open = Task::with('project:id,name,code')->open()->where('assignee_id', $user->id);
        $groups = [
            'overdue' => [__('Gecikmiş'), (clone $open)->whereNotNull('due_date')->where('due_date', '<', $today->toDateString())->orderBy('due_date')->get()],
            'today' => [__('Bu gün'), (clone $open)->whereDate('due_date', $today->toDateString())->get()],
            'week' => [__('Bu həftə'), (clone $open)->whereBetween('due_date', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()])->orderBy('due_date')->get()],
            'later' => [__('Sonra və tarixsiz'), (clone $open)->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>', $today->addDays(7)->toDateString()))->orderByRaw('due_date IS NULL, due_date')->limit(30)->get()],
        ];
        $doneToday = Task::where('assignee_id', $user->id)->where('status', 'done')->where('completed_at', '>=', $today)->count();

        $reminders = Reminder::where('user_id', $user->id)->whereNull('read_at')->orderBy('remind_at')->limit(50)->get();
        $history = Reminder::where('user_id', $user->id)->whereNotNull('read_at')->latest('read_at')->limit(10)->get();

        return view('my-work', compact('groups', 'doneToday', 'reminders', 'history', 'today'));
    }

    public function storeReminder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:1000'],
            'remind_at' => ['required', 'date', 'after:now'],
        ], [], ['remind_at' => __('Xatırlatma vaxtı')]);

        Reminder::create($data + ['user_id' => $request->user()->id, 'source' => 'personal']);

        return back()->with('success', __('Xatırlatma əlavə edildi: ').azdate($data['remind_at'], true));
    }

    public function doneReminder(Request $request, Reminder $reminder): RedirectResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
        $reminder->update(['read_at' => now()]);

        return back()->with('success', __('Xatırlatma bağlandı.'));
    }

    public function destroyReminder(Request $request, Reminder $reminder): RedirectResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
        $reminder->delete();

        return back()->with('success', __('Xatırlatma silindi.'));
    }
}
