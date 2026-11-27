@extends('layouts.admin')

@section('content')
    <div class="card shell-card mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1">Notification Management</h1>
                <p class="text-secondary mb-0">These emails are sent automatically. Their wording is fixed.</p>
            </div>
            <div class="d-flex gap-2">
                @canRoute('admin.notifications.logs')
                    <a href="{{ route('admin.notifications.logs') }}" class="btn btn-outline-secondary">View Logs</a>
                @endcanRoute
            </div>
        </div>
    </div>

    <div class="card shell-card mb-4">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Notification</th>
                            <th>Sent to</th>
                            <th>Channel</th>
                            <th>Subject</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $template)
                            <tr>
                                <td class="fw-semibold">{{ $template['name'] }}</td>
                                <td>{{ $template['recipient'] }}</td>
                                <td>Email</td>
                                <td class="text-secondary">{{ $template['subject'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
