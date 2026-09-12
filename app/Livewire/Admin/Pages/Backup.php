<?php

namespace App\Livewire\Admin\Pages;

use App\Admin\Tables\BackupAttemptsTable;
use App\Models\BackupAttempt;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\BackupStatus;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\TelegramBackupSender;
use Livewire\Component;

/**
 * The backup screen: whether the office is covered, where a copy goes, and a way to send one now.
 *
 * The screen OWNS NOTHING it shows. "Is the office covered" comes from BackupStatus, the figures from
 * those the model stores, and the destination from BackupSettings — so the sentence in the chat and
 * the alert on this page are the same sentence. What lives here is the two interactions the office
 * has with the feature: describing the destination, and asking for a copy.
 *
 * The bot credential is write-only by design: it is saved encrypted, never rendered back, and the
 * field says a token is already stored and accepts a replacement. A screen that can display the
 * secret that can read the office's chat is a screen worth stealing a password for.
 */
class Backup extends Component
{
    public static function subheading(): string
    {
        return __('bk.sub');
    }

    /** @var array<string, mixed> field key => value, bound through <x-admin.field> */
    public array $destination = [];

    /** The outcome of the last test, shown next to the button that ran it. */
    public ?string $testResult = null;

    public bool $testSucceeded = false;

    public function mount(): void
    {
        $this->destination = [
            'bot_token' => '',
            'chat_id' => BackupSettings::chatId() ?? '',
            'retain' => BackupSettings::retain(),
            'delivery_limit_mb' => (int) round(BackupSettings::deliveryLimit() / 1024 / 1024),
        ];
    }

    /** The destination form, described once — the view renders exactly these fields. */
    public function fields(): array
    {
        return [
            'bot_token' => [
                'key' => 'bot_token',
                'label' => __('bk.field_token'),
                'type' => 'password',
                'span' => 'full',
                'helper' => BackupSettings::hasBotToken() ? __('bk.field_token_saved') : __('bk.field_token_hint'),
                'rules' => ['nullable', 'string', 'max:200'],
            ],
            'chat_id' => [
                'key' => 'chat_id',
                'label' => __('bk.field_chat'),
                'helper' => __('bk.field_chat_hint'),
                'rules' => ['nullable', 'string', 'max:60'],
            ],
            'retain' => [
                'key' => 'retain',
                'label' => __('bk.field_retain'),
                'type' => 'number',
                'min' => 1,
                'max' => 365,
                'helper' => __('bk.field_retain_hint'),
                'rules' => ['required', 'integer', 'min:1', 'max:365'],
            ],
            'delivery_limit_mb' => [
                'key' => 'delivery_limit_mb',
                'label' => __('bk.field_limit'),
                'type' => 'number',
                'min' => 1,
                'max' => 49,
                'helper' => __('bk.field_limit_hint'),
                'rules' => ['required', 'integer', 'min:1', 'max:49'],
            ],
        ];
    }

    /** Saving the destination is deliberate: an empty field never erases what is stored. */
    public function saveDestination(): void
    {
        $this->resetErrorBag();

        $this->validate([
            'destination.bot_token' => ['nullable', 'string', 'max:200'],
            'destination.chat_id' => ['nullable', 'string', 'max:60'],
            'destination.retain' => ['required', 'integer', 'min:1', 'max:365'],
            'destination.delivery_limit_mb' => ['required', 'integer', 'min:1', 'max:49'],
        ]);

        $token = trim((string) $this->destination['bot_token']);

        if ($token !== '') {
            BackupSettings::saveBotToken($token);
        }

        BackupSettings::saveChatId((string) $this->destination['chat_id']);
        BackupSettings::saveRetain((int) $this->destination['retain']);
        BackupSettings::saveDeliveryLimit((int) $this->destination['delivery_limit_mb'] * 1024 * 1024);

        // Cleared immediately: the field is not a place a saved credential sits.
        $this->destination['bot_token'] = '';
        $this->testResult = null;

        session()->flash('status', __('bk.saved'));
    }

    /** Ask the chat whether it is listening. */
    public function testDestination(): void
    {
        $result = TelegramBackupSender::make()->test();

        $this->testSucceeded = (bool) $result['ok'];
        $this->testResult = $result['ok']
            ? __('bk.test_ok', ['chat' => ltr_isolate((string) BackupSettings::chatId())])
            : __('bk.test_failed', ['reason' => (string) $result['reason']]);
    }

    /** Send today's copy now — the same run the scheduler makes, recorded the same way. */
    public function sendNow(): void
    {
        $attempt = BackupRunner::make()->sendNow((int) auth()->id());

        session()->flash('status', $attempt->delivered()
            ? __('bk.sent_now', ['size' => ltr_isolate($attempt->sizeForHumans())])
            : __('bk.sent_now_failed', ['reason' => (string) $attempt->error]));
    }

    public function lastDelivered(): ?BackupAttempt
    {
        return BackupStatus::lastDelivered();
    }

    /** @return array<int, array{tone: string, text: string}> */
    public function warnings(): array
    {
        return BackupStatus::warnings();
    }

    public function filesOnDisk(): int
    {
        return count(DatabaseDumper::make()->files());
    }

    public function hoursSinceScheduledRun(): ?float
    {
        return BackupSettings::hoursSinceScheduledRun();
    }

    public function render()
    {
        return view('livewire.admin.pages.backup', [
            'fields' => $this->fields(),
            'attemptsTable' => BackupAttemptsTable::class,
        ]);
    }
}
