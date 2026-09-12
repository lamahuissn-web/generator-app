@props([
    // The CREATE ROUTE NAME ('admin.routes.create'), not a URL: a screen already knows which
    // records it makes, and the check for whether that route exists belongs in one place.
    'route',
    'label' => null,
])

{{-- The way to add a record, described once for the whole panel.
     It belongs to the SCREEN rather than to the table definition, because the same table
     component renders inside a subscriber's profile tabs, where "add new" is meaningless. --}}
@if ($route && \Illuminate\Support\Facades\Route::has($route))
    <a href="{{ route($route) }}" class="kt-btn kt-btn-sm">
        <i class="ki-filled ki-plus"></i>
        {{ $label ?? __('t.new_record') }}
    </a>
@endif
