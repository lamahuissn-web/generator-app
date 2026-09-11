<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Every read-only and CRUD screen in the panel, driven by App\Admin\ResourceRegistry.
 *
 * One controller rather than one per model: the screens differ only by which table/form
 * definition they load, and that is data. The resource key arrives as a route DEFAULT
 * (see routes/admin.php), which is what lets the routes keep readable names like
 * admin.routes.index instead of a generic admin.resource.index?resource=routes.
 */
class ResourceController extends Controller
{
    private function key(): string
    {
        $key = (string) request()->route('resource');

        abort_unless($key !== '' && array_key_exists($key, \App\Admin\ResourceRegistry::all()), 404);

        return $key;
    }

    /** Panels only the admin owns (users, audit log) are closed to any other role. */
    private function authorize(array $resource): void
    {
        if (! empty($resource['admin_only'])) {
            abort_unless(User::find(auth()->id())?->isAdmin(), 403);
        }
    }

    public function index(Request $request): View
    {
        $key = $this->key();
        $resource = \App\Admin\ResourceRegistry::get($key);
        $this->authorize($resource);

        return view('admin.resource.index', [
            'resourceKey' => $key,
            'resource' => $resource,
        ]);
    }

    public function create(Request $request): View
    {
        $key = $this->key();
        $resource = \App\Admin\ResourceRegistry::get($key);
        $this->authorize($resource);

        abort_if(empty($resource['form']), 404);

        return view('admin.resource.form', [
            'resourceKey' => $key,
            'resource' => $resource,
            'record' => null,
        ]);
    }

    public function edit(Request $request, int $record): View
    {
        $key = $this->key();
        $resource = \App\Admin\ResourceRegistry::get($key);
        $this->authorize($resource);

        abort_if(empty($resource['form']) || ! empty($resource['create_only']), 404);

        $model = $resource['form']::make()->model();

        return view('admin.resource.form', [
            'resourceKey' => $key,
            'resource' => $resource,
            // findOrFail, not find: an id that does not exist is a 404, not a form that
            // silently creates a second record on save.
            'record' => $model::query()->findOrFail($record),
        ]);
    }
}
