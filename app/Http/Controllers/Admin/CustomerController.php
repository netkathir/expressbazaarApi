<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = User::query()
            ->where('role', 'customer')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->string('search'));
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
                $this->prioritizePrefixSearch($query, ['name', 'email', 'phone'], $search);
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(\App\Support\PerPage::size(10))
            ->withQueryString();

        return view('admin.customers.index', [
            'title' => 'Customer Management',
            'activeMenu' => 'customers',
            'customers' => $customers,
        ]);
    }

    public function show(User $customer)
    {
        abort_if($customer->role !== 'customer', 404);

        return view('admin.customers.show', [
            'title' => 'Customer Details',
            'activeMenu' => 'customers',
            'customer' => $customer,
        ]);
    }

    public function edit(User $customer)
    {
        abort_if($customer->role !== 'customer', 404);

        return view('admin.customers.form', [
            'title' => 'Edit Customer',
            'activeMenu' => 'customers',
            'customer' => $customer,
        ]);
    }

    public function update(Request $request, User $customer)
    {
        abort_if($customer->role !== 'customer', 404);

        $data = $this->validateCustomer($request, $customer);

        $customer->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
        ]);

        return $this->redirectToIndex($request, 'admin.customers.index', 'Customer updated successfully.');
    }

    public function destroy(Request $request, User $customer)
    {
        abort_if($customer->role !== 'customer', 404);

        $customer->delete();

        return $this->redirectToIndex($request, 'admin.customers.index', 'Customer deleted successfully.');
    }

    public function toggleStatus(User $customer)
    {
        abort_if($customer->role !== 'customer', 404);

        $customer->update([
            'status' => $customer->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Customer status updated.');
    }

    private function validateCustomer(Request $request, User $customer): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => trim((string) $request->input('email')),
            'phone' => trim((string) $request->input('phone')),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^(?=.*[A-Za-z])[A-Za-z .\'-]+$/'],
            'email' => ['required', 'email:rfc', 'regex:/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', 'max:255', Rule::unique('users', 'email')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [
            'name.regex' => 'Name may contain only letters, spaces, apostrophes, dots, and hyphens.',
            'email.regex' => 'Enter a valid email address with a valid domain extension.',
        ]);
    }
}
