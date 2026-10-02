<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Rules\TenantExists;
use App\Tables\TaskTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $table = new TaskTable($request);
        $view = in_array($request->query('view'), ['board', 'list', 'calendar', 'gantt'], true) ? $request->query('view') : 'board';

        $data = ['table' => $table, 'view' => $view];
        if ($view === 'list') {
            $data['items'] = $table->paginate();
        } elseif ($view === 'board') {
            $data['columns'] = $table->query()->reorder()->orderBy('position')->orderBy('due_date')->limit(400)->get()->groupBy('status');
        } elseif ($view === 'calendar') {
            $month = $this->month($request);
            $data['month'] = $month;
            $data['byDay'] = $table->query()->reorder()
                ->whereBetween('due_date', [$month->copy()->startOfMonth()->startOfWeek()->toDateString(), $month->copy()->endOfMonth()->endOfWeek()->toDateString()])
                ->orderBy('due_date')->get()->groupBy(fn ($t) => $t->due_date->format('Y-m-d'));
        } else {
            $data['gantt'] = $table->query()->reorder()->whereNotNull('due_date')->orderBy('start_date')->orderBy('due_date')->limit(80)->get();
        }

        return view('tasks.index', $data);
    }

    public function export(Request $request): Response
    {
        return (new TaskTable($request))->export((string) $request->query('format', 'xlsx'));
    }

    public function create(Request $request): View
    {
        $this->authorize('projects.create');
        $task = new Task([
            'status' => in_array($request->query('status'), array_keys(config('glaust.statuses.task')), true) ? $request->query('status') : 'todo',
            'priority' => 'medium',
            'project_id' => Project::find($request->integer('project_id'))?->id,
            'assignee_id' => $request->integer('assignee_id') ?: null,
            'start_date' => today(),
        ]);

        return view('tasks.form', ['task' => $task->load('project')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('projects.create');
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['position'] = (int) Task::where('status', $data['status'])->max('position') + 1;
        $task = Task::create($data);

        return redirect()->to($request->input('redirect') === 'project' && $task->project_id
            ? route('projects.show', [$task->project_id, 'tab' => 'board'])
            : route('tasks.show', $task))->with('success', 'Tapşırıq yaradıldı.');
    }

    public function show(Task $task): View
    {
        $task->load(['project', 'milestone', 'assignee', 'creator', 'checklist', 'comments.user', 'timeEntries.user', 'attachments.uploader']);
        $history = AuditLog::with('user')->where('auditable_type', 'task')->where('auditable_id', $task->id)->latest('created_at')->limit(15)->get();

        return view('tasks.show', ['task' => $task, 'history' => $history, 'canEdit' => $this->canTouch($task)]);
    }

    public function edit(Task $task): View
    {
        $this->authorize('projects.update');

        return view('tasks.form', ['task' => $task->load('project')]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('projects.update');
        $task->update($this->validated($request));

        return redirect()->route('tasks.show', $task)->with('success', 'Tapşırıq yeniləndi.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('projects.delete');
        $projectId = $task->project_id;
        $task->delete();

        return redirect()->to($projectId ? route('projects.show', [$projectId, 'tab' => 'board']) : route('tasks.index'))->with('success', 'Tapşırıq silindi.');
    }

    /** Kanban drop: new status and the order of the target column. */
    public function move(Request $request, Task $task): JsonResponse
    {
        if (! $this->canTouch($task)) {
            return response()->json(['ok' => false, 'message' => 'Bu tapşırığı dəyişməyə icazəniz yoxdur.']);
        }
        $status = (string) $request->input('status');
        if (! array_key_exists($status, config('glaust.statuses.task'))) {
            return response()->json(['ok' => false, 'message' => 'Naməlum status.']);
        }

        DB::transaction(function () use ($request, $task, $status) {
            $task->update(['status' => $status]);
            $order = array_map('intval', (array) $request->input('order', []));
            if ($order) {
                // Only this tenant's tasks can be reordered (the scope applies to the query).
                $tasks = Task::whereIn('id', $order)->get()->keyBy('id');
                foreach ($order as $pos => $id) {
                    if (isset($tasks[$id]) && $tasks[$id]->position !== $pos) {
                        $tasks[$id]->forceFill(['position' => $pos])->saveQuietly();
                    }
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    public function addChecklist(Request $request, Task $task): RedirectResponse
    {
        abort_unless($this->canTouch($task), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:190']], [], ['title' => 'Bənd']);
        $task->checklist()->create($data + ['position' => (int) $task->checklist()->max('position') + 1]);

        return back();
    }

    public function toggleChecklist(Task $task, TaskChecklistItem $item): RedirectResponse
    {
        abort_unless($item->task_id === $task->id && $this->canTouch($task), 404);
        $item->update(['is_done' => ! $item->is_done]);

        return back();
    }

    public function destroyChecklist(Task $task, TaskChecklistItem $item): RedirectResponse
    {
        abort_unless($item->task_id === $task->id && $this->canTouch($task), 404);
        $item->delete();

        return back();
    }

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], [], ['body' => 'Şərh']);
        $task->comments()->create($data + ['user_id' => $request->user()->id]);

        // Let the assignee know someone commented on their task.
        if ($task->assignee_id && $task->assignee_id !== $request->user()->id) {
            \App\Models\Reminder::create([
                'user_id' => $task->assignee_id, 'source' => 'task_due',
                'title' => $request->user()->name.' şərh yazdı: '.$task->title,
                'body' => \Illuminate\Support\Str::limit($data['body'], 140),
                'url' => route('tasks.show', $task, false), 'remind_at' => now(),
                'remindable_type' => 'task', 'remindable_id' => $task->id,
            ]);
        }

        return back()->with('success', 'Şərh əlavə edildi.');
    }

    public function logTime(Request $request, Task $task): RedirectResponse
    {
        abort_unless($this->canTouch($task), 403);
        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'min:0.1', 'max:24'],
            'note' => ['nullable', 'string', 'max:190'],
        ], [], ['work_date' => 'Tarix', 'hours' => 'Saat']);
        $task->timeEntries()->create([
            'user_id' => $request->user()->id, 'work_date' => $data['work_date'],
            'minutes' => (int) round($data['hours'] * 60), 'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', num($data['hours'], 1).' saat qeyd edildi.');
    }

    private function canTouch(Task $task): bool
    {
        $user = auth()->user();

        return $user->can('projects.update') || $task->assignee_id === $user->id;
    }

    private function month(Request $request): \Carbon\Carbon
    {
        try {
            return \Carbon\Carbon::createFromFormat('!Y-m', (string) $request->query('month', now()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'project_id' => ['nullable', 'integer', TenantExists::in('projects')],
            'milestone_id' => ['nullable', 'integer', TenantExists::plain('milestones')],
            'status' => ['required', Rule::in(array_keys(config('glaust.statuses.task')))],
            'priority' => ['required', Rule::in(array_keys(config('glaust.statuses.priority')))],
            'assignee_id' => ['nullable', 'integer', TenantExists::plain('users')],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ], [], ['milestone_id' => 'Mərhələ']);

        if (! empty($data['milestone_id']) && \App\Models\Milestone::find($data['milestone_id'])?->project_id !== (int) ($data['project_id'] ?? 0)) {
            $data['milestone_id'] = null;
        }

        return $data;
    }

    public static function users(): array
    {
        return User::forTenant()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
