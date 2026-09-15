<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReadingImport;
use App\Services\SubscriberImport;
use Illuminate\Http\RedirectResponse;

/**
 * Applying an imported file.
 *
 * A plain POST, deliberately. The apply step is the one thing on that screen that MUST work in every
 * browser, and it was the one thing that did not: the button carried `wire:click`, the click never
 * reached Livewire in the office's browser, and the screen answered with silence. A native form
 * submit is not hostage to a click handler — it is the same mechanism as the routes screen the office
 * used all morning without trouble.
 *
 * The judged rows are already in the session (see the component's `park()`), so this needs no payload
 * beyond the CSRF token: what the operator approved on screen is what gets written.
 */
final class SubscriberImportController extends Controller
{
    public function apply(): RedirectResponse
    {
        $state = session('subscriber-import.state') ?? [];
        $rows = $state['rows'] ?? [];
        $readings = ($state['type'] ?? 'subscribers') === 'readings';

        if ($rows === []) {
            return redirect()
                ->route('admin.subscribers-import')
                ->with('import_outcome', ['created' => 0, 'refused' => 0, 'failed' => [], 'error' => __('imp.file_gone')]);
        }

        $outcome = $readings
            ? ReadingImport::commit($rows, auth()->id())
            : SubscriberImport::commit($rows, auth()->id());

        session()->forget('subscriber-import.state');

        return redirect()
            ->route('admin.subscribers-import')
            ->with('import_outcome', [
                'created' => $outcome['created'],
                'refused' => count($rows) - $outcome['created'],
                'failed' => $outcome['failed'],
                'error' => null,
            ])
            ->with('status', $readings
                ? __('imp.rd_done', ['count' => number_format($outcome['created'])])
                : __('imp.done', ['count' => number_format($outcome['created'])]));
    }
}
