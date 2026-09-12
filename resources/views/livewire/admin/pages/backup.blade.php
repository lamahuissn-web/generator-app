<div class="grid gap-5">
    {{-- The verdict first: an operator opens this screen to answer one question — is the office
         covered? — and the warnings are the answer, in the same words the chat uses. --}}
    @forelse ($this->warnings() as $warning)
        <div @class([
            'kt-alert kt-alert-light',
            'kt-alert-destructive' => $warning['tone'] === 'danger',
            'kt-alert-warning' => $warning['tone'] === 'warning',
        ]) role="alert">
            <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ $warning['text'] }}</div>
            </div>
        </div>
    @empty
        <div class="kt-alert kt-alert-light kt-alert-success" role="alert">
            <div class="kt-alert-icon"><i class="ki-filled ki-check-circle text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ __('bk.covered') }}</div>
            </div>
        </div>
    @endforelse

    {{-- The figures an operator checks before anything else. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php $last = $this->lastDelivered(); @endphp

        <x-admin.stat
            :label="__('bk.stat_last')"
            :value="$last?->started_at?->translatedFormat('Y-m-d H:i') ?? '—'"
            :hint="$last ? ltr_isolate($last->sizeForHumans()) : __('bk.stat_never')"
            :icon="$last ? 'ki-cloud-change' : 'ki-information-2'"
            :tone="$last ? 'success' : 'danger'" />

        <x-admin.stat
            :label="__('bk.stat_on_disk')"
            :value="(string) $this->filesOnDisk()"
            :hint="__('bk.stat_on_disk_hint', ['retain' => \App\Services\Backup\BackupSettings::retain()])"
            icon="ki-archive"
            tone="info" />

        <x-admin.stat
            :label="__('bk.stat_scheduler')"
            :value="$this->hoursSinceScheduledRun() === null
                ? __('bk.stat_scheduler_never_short')
                : ltr_isolate(round($this->hoursSinceScheduledRun()).' '.__('bk.hours_short'))"
            :hint="__('bk.stat_scheduler_hint')"
            icon="ki-time"
            :tone="$this->hoursSinceScheduledRun() === null || $this->hoursSinceScheduledRun() > \App\Services\Backup\BackupSettings::staleAfterHours()
                ? 'danger'
                : 'success'" />

        <x-admin.stat
            :label="__('bk.stat_attempts')"
            :value="(string) \App\Models\BackupAttempt::query()->count()"
            :hint="__('bk.stat_attempts_hint')"
            icon="ki-element-11"
            tone="default" />
    </div>

    <form wire:submit="saveDestination" class="grid gap-5">
        <x-admin.card :title="__('bk.destination')" :description="__('bk.destination_hint')" content-class="sm:grid-cols-2">
            @foreach ($fields as $field)
                <x-admin.field :field="$field" model="destination" :values="$destination" id-prefix="bk" />
            @endforeach

            <div class="flex flex-wrap items-center gap-2.5 sm:col-span-2">
                <button type="submit" class="kt-btn kt-btn-lg" wire:loading.attr="disabled" wire:target="saveDestination">
                    <i class="ki-filled ki-check"></i>
                    {{ __('bk.save') }}
                </button>

                {{-- The test is a separate act from saving: an operator wants to know the chat answers
                     BEFORE a night passes without a copy. --}}
                <button type="button" class="kt-btn kt-btn-lg kt-btn-outline"
                        wire:click="testDestination"
                        wire:loading.attr="disabled" wire:target="testDestination">
                    <i class="ki-filled ki-arrow-circle-right"></i>
                    {{ __('bk.test') }}
                </button>

                @if ($testResult !== null)
                    <span @class([
                        'text-xs font-medium',
                        'text-green-700 dark:text-green-500' => $testSucceeded,
                        'text-destructive' => ! $testSucceeded,
                    ])>{{ $testResult }}</span>
                @endif
            </div>
        </x-admin.card>
    </form>

    <x-admin.card :title="__('bk.attempts')" :description="__('bk.attempts_hint')">
        <x-slot:actions>
            <button type="button" class="kt-btn kt-btn-sm" wire:click="sendNow"
                    wire:loading.attr="disabled" wire:target="sendNow">
                <i class="ki-filled ki-cloud-change"></i>
                {{ __('bk.send_now') }}
            </button>
        </x-slot:actions>

        <livewire:admin.data-table :table="$attemptsTable" :key="'backup-attempts'" />
    </x-admin.card>

    {{-- What the machine is doing, said out loud, because the alternative is an operator who assumes
         the panel handles something it does not. --}}
    <x-admin.card :title="__('bk.how')" :description="__('bk.how_hint')">
        <div class="grid gap-3 text-sm text-muted-foreground">
            <p>{{ __('bk.how_nightly', ['hour' => config('backup.hour')]) }}</p>
            <p>{{ __('bk.how_cron') }}</p>
            <p class="font-mono text-xs" dir="ltr">* * * * * cd /path/to/panel &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2>&amp;1</p>
            <p>{{ __('bk.how_files') }}</p>
            <p>{{ __('bk.how_scope') }}</p>
        </div>
    </x-admin.card>
</div>
