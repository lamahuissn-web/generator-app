@if (\Illuminate\Support\Facades\Route::has('admin.readings-entry'))
    {{-- The way in for the office: a subscriber's number read off a bill, a collector phoning one in,
         a paper slip that arrived late. One street, one screen, one save. --}}
    <a href="{{ route('admin.readings-entry') }}" class="kt-btn kt-btn-sm">
        <i class="ki-filled ki-notepad-edit"></i>
        {{ __('rdgl.bulk') }}
    </a>
@endif
