<?php

namespace App\Livewire\Admin\Pages;

use App\Models\Route;
use App\Services\ReadingImport;
use App\Services\SubscriberImport as Importer;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * استيراد الزبائن — the fast way in when the real data arrives.
 *
 * Three steps and never fewer: pick the file, LOOK at what it would do, then do it. The middle step
 * is the feature — the preview writes nothing, so a wrong column or a neighbourhood spelt differently
 * costs a re-upload instead of a cleanup. Rows that would touch a subscriber already in the panel are
 * refused with their reason and can be downloaded as a file to fix.
 *
 * The importer itself lives in the service: this component arranges, it decides nothing about money
 * or about who is allowed in.
 */
class SubscriberImport extends Component
{
    use WithFileUploads;

    public static function subheading(): string
    {
        return __('imp.sub');
    }

    public $file = null;

    /**
     * What the file holds: whole subscribers, or a month of readings that become bills.
     *
     * A `<select wire:model.live>` rather than buttons on purpose: the office's browser delivers change
     * events reliably and has been unreliable about click handlers (see the controller for the apply).
     */
    public string $type = 'subscribers';

    /** upload | preview | done */
    public string $step = 'upload';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?string $error = null;

    /** @var array<string, mixed>|null */
    public ?array $outcome = null;

    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['file' => __('imp.file_label')];
    }

    public function mount(): void
    {
        // What the apply POST left behind: the outcome it reported, or the judged rows it did not use
        // yet. Either way the screen comes back where the operator left it.
        $outcome = session('import_outcome');

        if (is_array($outcome)) {
            $this->outcome = $outcome;
            $this->error = $outcome['error'] ?? null;
            $this->step = $outcome['error'] ?? null ? 'upload' : 'done';

            return;
        }

        $this->restore();
    }

    public function updatedFile(): void
    {
        $this->readFile();
    }

    /** Switching the kind throws away a preview of the other kind rather than mixing the two. */
    public function updatedType(): void
    {
        $this->reset(['file', 'rows', 'error', 'outcome']);
        session()->forget(self::STATE_KEY);
        $this->step = 'upload';
    }

    public function isReadings(): bool
    {
        return $this->type === 'readings';
    }

    /**
     /**
      * Where this screen keeps the file it has already read.
      *
      * Registry screens are mounted through `<livewire:dynamic-component>`, so a request can arrive with
      * a fresh component: its properties — the judged rows, the step, the upload — are gone, the screen
      * falls back to «اختار ملف», and the apply button has nothing to write. That is exactly what the
      * office saw: a click, and silence.
      *
      * The state therefore lives in the SESSION, keyed by nothing but the person using it, and `mount()`
      * puts the screen back where it was. The upload is only needed to read the file once; after that
      * the judged rows are what both the preview and the write come from.
      */
     private const STATE_KEY = 'subscriber-import.state';

     /** Keep the judged rows where no component id can lose them. */
     private function park(): void
     {
         session([self::STATE_KEY => ['rows' => $this->rows, 'type' => $this->type]]);
     }

     /** Put the screen back where the operator left it. */
     private function restore(): bool
     {
         $state = session(self::STATE_KEY);

         if (is_array($state) && ($state['rows'] ?? []) !== []) {
             $this->rows = $state['rows'];
             $this->type = $state['type'] ?? 'subscribers';
             $this->step = 'preview';

             return true;
         }

         return false;
     }

    /** Read the file and judge every row — no writes, by construction. */
    public function readFile(): void
    {
        $this->validate();

        $parsed = $this->isReadings()
            ? ReadingImport::parse($this->file->getRealPath())
            : Importer::parse($this->file->getRealPath());

        if ($parsed['error'] !== null) {
            $this->error = $parsed['error'];
            $this->rows = [];
            $this->step = 'upload';

            return;
        }

        if ($parsed['rows'] === []) {
            $this->error = __('imp.no_rows');
            $this->rows = [];
            $this->step = 'upload';

            return;
        }

        $this->rows = $this->isReadings()
            ? ReadingImport::preview($parsed['rows'])
            : Importer::preview($parsed['rows']);
        $this->park();
        $this->error = null;
        $this->outcome = null;
        $this->step = 'preview';
    }

    /**
     * Write the file.
     *
     * The rows are re-derived HERE from the uploaded file rather than trusted from the previous
     * request: the file is the one source both the preview and the write come from, so a lost or
     * stale snapshot cannot make the button do nothing while the screen still shows a preview. If the
     * upload itself is gone the operator is told so, in words they can act on — never silence.
     */
    public function startOver(): void
    {

        session()->forget(self::STATE_KEY);
        $this->reset(['file', 'rows', 'error', 'outcome']);
        $this->step = 'upload';
    }

    public function downloadTemplate()
    {
        $readings = $this->isReadings();

        return response()->streamDownload(function () use ($readings): void {
            echo $readings ? ReadingImport::template() : Importer::template();
        }, $readings ? 'readings-template.csv' : 'subscribers-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function downloadErrors()
    {
        $readings = $this->isReadings();

        return response()->streamDownload(function () use ($readings): void {
            echo $readings ? ReadingImport::errorsCsv($this->rows) : Importer::errorsCsv($this->rows);
        }, $readings ? 'readings-errors.csv' : 'subscribers-errors.csv', ['Content-Type' => 'text/csv']);
    }

    public function readyCount(): int
    {
        return collect($this->rows)->where('status', 'ok')->count();
    }

    public function problemCount(): int
    {
        return collect($this->rows)->where('status', 'problem')->count();
    }

    /** The names the file may use for a route — spelled out so a refusal can be acted on. */
    public function knownRoutes(): Collection
    {
        return Route::query()->orderBy('name')->pluck('name');
    }

    public function render()
    {
        return view('livewire.admin.pages.subscriber-import');
    }
}
