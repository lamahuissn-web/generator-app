<div class="grid gap-4">
    @if (session('status'))
        <div class="kt-alert kt-alert-light kt-alert-success" role="status">
            <div class="kt-alert-icon"><i class="ki-filled ki-check text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ session('status') }}</div>
            </div>
        </div>
    @endif

    {{-- 1) The file. The template is the same twelve columns the export writes and the importer
         accepts: the operator fills it in Excel, saves CSV, and uploads it back. --}}
    <x-admin.card :title="__('imp.step1')" :subtitle="__('imp.step1_hint')">
        {{-- A <select wire:model.live> and not buttons: the office's browser delivers change events
             reliably and has been unreliable about click handlers (see the apply controller). --}}
        <div class="mt-4 flex max-w-md flex-col gap-1.5">
            <label for="imp-type" class="kt-form-label font-normal text-mono">{{ __('imp.type') }}</label>
            <select id="imp-type" wire:model.live="type" class="kt-select w-full">
                <option value="subscribers">{{ __('imp.type_subscribers') }}</option>
                <option value="readings">{{ __('imp.type_readings') }}</option>
            </select>
            <span class="text-xs text-muted-foreground">
                {{ $this->isReadings() ? __('imp.type_readings_hint') : __('imp.step1_hint') }}
            </span>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" wire:click="downloadTemplate" class="kt-btn kt-btn-outline">
                <i class="ki-filled ki-file-down"></i>
                {{ __('imp.template') }}
            </button>

            <label class="kt-btn cursor-pointer">
                <i class="ki-filled ki-file-up"></i>
                {{ __('imp.choose_file') }}
                <input type="file" wire:model="file" accept=".csv,.txt,text/csv" class="hidden">
            </label>

            <span wire:loading wire:target="file" class="text-sm text-muted-foreground">{{ __('imp.reading') }}</span>

            @if ($file)
                <span class="text-sm text-muted-foreground">{{ $file->getClientOriginalName() }}</span>
            @endif

            @if ($step !== 'upload')
                <button type="button" wire:click="startOver" class="kt-btn kt-btn-outline ms-auto">
                    <i class="ki-filled ki-arrows-circle"></i>
                    {{ __('imp.another_file') }}
                </button>
            @endif
        </div>

        @error('file')
            <p class="mt-3 text-sm text-destructive">{{ $message }}</p>
        @enderror

        @if ($error)
            <div class="kt-alert kt-alert-light kt-alert-destructive mt-4" role="alert">
                <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                <div class="kt-alert-content">
                    <div class="kt-alert-title">{{ $error }}</div>
                </div>
            </div>
        @endif

        <div class="mt-4 text-xs text-muted-foreground">
            <span class="font-semibold">{{ __('imp.known_routes') }}</span>
            {{ $this->knownRoutes()->implode(' · ') }}
        </div>
    </x-admin.card>

    {{-- 2) The preview. Nothing has been written at this point — that is the whole point of the step. --}}
    @if ($step === 'preview')
        <x-admin.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold">{{ __('imp.preview_title') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ __('imp.preview_hint') }}</p>
                    <p class="text-xs font-semibold text-amber-700 dark:text-amber-400">{{ __('imp.apply_needed') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="kt-badge kt-badge-lg kt-badge-outline kt-badge-success">
                        {{ __('imp.ready_count', ['count' => number_format($this->readyCount())]) }}
                    </span>
                    {{-- A NATIVE submit, not a click handler. This is the one step that has to work in
                         every browser, and the `wire:click` button did not: the click never reached
                         Livewire and the screen answered with silence. A form submit is the same
                         mechanism as the routes screen the office already uses. --}}
                    <form method="POST" action="{{ route('admin.subscribers-import.apply') }}">
                        @csrf
                        <button type="submit" class="kt-btn">
                            <i class="ki-filled ki-check"></i>
                            {{ __('imp.commit', ['count' => number_format($this->readyCount())]) }}
                        </button>
                    </form>
                    @if ($this->problemCount() > 0)
                        <span class="kt-badge kt-badge-lg kt-badge-outline kt-badge-destructive">
                            {{ __('imp.problem_count', ['count' => number_format($this->problemCount())]) }}
                        </span>
                        <button type="button" wire:click="downloadErrors" class="kt-btn kt-btn-outline kt-btn-sm">
                            <i class="ki-filled ki-file-down"></i>
                            {{ __('imp.download_errors') }}
                        </button>
                    @endif
                </div>
            </div>

            @if ($this->isReadings())
                <x-admin.table class="mt-4" :columns="[
                        ['label' => __('imp.row_number'), 'on_mobile' => false],
                        ['label' => __('t.name')],
                        ['label' => __('t.meter'), 'on_mobile' => false],
                        ['label' => __('t.period')],
                        ['label' => __('t.prev_reading'), 'align' => 'end', 'on_mobile' => false],
                        ['label' => __('t.current_reading'), 'align' => 'end'],
                    ]" :row-count="count($rows)">
                    @foreach ($rows as $row)
                        <tr wire:key="imp-{{ $row['row'] }}"
                            @class(['border-b border-border last:border-b-0', 'bg-destructive/5' => $row['status'] === 'problem'])>
                            <td class="px-4 py-3 text-sm align-middle tabular-nums text-muted-foreground">{{ $row['row'] }}</td>
                            <td class="px-4 py-3 text-sm align-middle">
                                <span class="font-medium">{{ $row['name'] ?: '—' }}</span>
                                @if ($row['status'] === 'problem')
                                    <span class="block text-xs text-destructive">{{ implode(' · ', $row['errors']) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm align-middle text-secondary-foreground" dir="ltr">{{ $row['meter_no'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm align-middle text-secondary-foreground" dir="ltr">{{ $row['period'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm align-middle text-end tabular-nums">{{ $row['previous'] !== '' ? $row['previous'] : '—' }}</td>
                            <td class="px-4 py-3 text-sm align-middle text-end tabular-nums">{{ $row['current'] }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @else
                <x-admin.table class="mt-4" :columns="[
                        ['label' => __('imp.row_number'), 'on_mobile' => false],
                        ['label' => __('t.name')],
                        ['label' => __('t.route')],
                        ['label' => __('t.type'), 'on_mobile' => false],
                        ['label' => __('t.status')],
                    ]" :row-count="count($rows)">
                    @foreach ($rows as $row)
                        <tr wire:key="imp-{{ $row['row'] }}"
                            @class(['border-b border-border last:border-b-0', 'bg-destructive/5' => $row['status'] === 'problem'])>
                            <td class="px-4 py-3 text-sm align-middle tabular-nums text-muted-foreground">{{ $row['row'] }}</td>
                            <td class="px-4 py-3 text-sm align-middle">
                                <span class="font-medium">{{ $row['name'] }}</span>
                                @if ($row['status'] === 'problem')
                                    <span class="block text-xs text-destructive">{{ implode(' · ', $row['errors']) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm align-middle text-secondary-foreground">{{ $row['route'] }}</td>
                            <td class="px-4 py-3 text-sm align-middle text-secondary-foreground">
                                {{ $row['type'] ? \App\Services\SubscriberImport::typeLabel($row['type']) : '—' }}
                            </td>
                            <td class="px-4 py-3 align-middle">
                                @if ($row['status'] === 'ok')
                                    <span class="kt-badge kt-badge-outline kt-badge-success">{{ __('imp.row_ok') }}</span>
                                @else
                                    <span class="kt-badge kt-badge-outline kt-badge-destructive">{{ __('imp.row_problem') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-3">
                {{-- The same native submit as the one in the header. --}}
                <form method="POST" action="{{ route('admin.subscribers-import.apply') }}">
                    @csrf
                    <button type="submit" class="kt-btn">
                        <i class="ki-filled ki-check"></i>
                        {{ __('imp.commit', ['count' => number_format($this->readyCount())]) }}
                    </button>
                </form>
                <span class="text-xs text-muted-foreground">{{ __('imp.commit_hint') }}</span>
            </div>
        </x-admin.card>
    @endif

    {{-- 3) What happened. --}}
    @if ($step === 'done' && $outcome)
        <x-admin.card :title="__('imp.result_title')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.stat :label="__('imp.created')" :value="number_format($outcome['created'])"
                              icon="ki-users" tone="success" />
                <x-admin.stat :label="__('imp.refused')"
                              :value="number_format(($outcome['refused'] ?? $this->problemCount()) + count($outcome['failed']))"
                              icon="ki-information"
                              :tone="(($outcome['refused'] ?? $this->problemCount()) + count($outcome['failed'])) > 0 ? 'warning' : 'success'" />
            </div>

            @if ($outcome['failed'] !== [])
                <div class="kt-alert kt-alert-light kt-alert-warning mt-4" role="alert">
                    <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                    <div class="kt-alert-content">
                        <div class="kt-alert-title">{{ __('imp.failed_title') }}</div>
                        <div class="kt-alert-description">
                            @foreach ($outcome['failed'] as $failure)
                                <span class="block">{{ __('imp.row_number') }} {{ $failure['row'] }} — {{ $failure['reason'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('admin.subscribers.index') }}" class="kt-btn">
                    <i class="ki-filled ki-users"></i>
                    {{ __('imp.go_to_list') }}
                </a>

                <button type="button" wire:click="startOver" class="kt-btn kt-btn-outline">
                    <i class="ki-filled ki-arrows-circle"></i>
                    {{ __('imp.another_file') }}
                </button>
            </div>
        </x-admin.card>
    @endif
</div>
