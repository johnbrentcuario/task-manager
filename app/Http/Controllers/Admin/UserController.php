<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active'])
            ->withCount([
                'assignedTasks as total_tasks_count',
                'assignedTasks as open_tasks_count' => fn ($query) => $query
                    ->where('status', '!=', Task::STATUS_COMPLETED),
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
        ]);

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->role = $data['role'];
        $user->email_verified_at = now();
        $user->save();

        return redirect('/admin/users');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user' => $user->only(['id', 'name', 'email', 'role']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
        ]);

        if ($user->is($request->user()) && $data['role'] !== $user->role) {
            throw ValidationException::withMessages([
                'role' => 'You can not change your own role.',
            ]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        $user->role = $data['role'];
        $user->save();

        return redirect('/admin/users');
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $active = $request->boolean('active');

        abort_if(
            $user->is($request->user()) && ! $active,
            403,
            'You can not deactivate your own account.',
        );

        $user->is_active = $active;
        $user->save();

        return back();
    }
}