<?php

namespace App\Exceptions;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use RuntimeException;
use Throwable;

/**
 * A refusal the phone can act on: an HTTP status, a stable machine code, and the office's own
 * sentence in the language the request asked for.
 *
 * The engine refuses in prose (`refuse.reading_lower`, «القراءة الجديدة لا يمكن أن تكون أقل…»), and
 * an app cannot branch on Arabic. So every refusal that comes out of the engine is given back its
 * `refuse.*` KEY here, by matching the translated sentence against the templates in
 * `lang/{locale}.json` — an exact comparison would fail on the half of them that carry a value, so
 * the match runs on the part before the first placeholder and the longest prefix wins.
 */
class ApiRefusal extends RuntimeException
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public readonly string $machineCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Run engine code and give its refusals a name.
     *
     * A QueryException is deliberately NOT swallowed: a refusal is a decision, a failed insert is a
     * bug, and dressing the second up as the first is how a broken phone build ships.
     */
    public static function business(Closure $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw static::fromBusiness($e);
        }
    }

    public static function fromBusiness(Throwable $e): self
    {
        return new self(static::codeFor($e->getMessage()), $e->getMessage());
    }

    public function toResponse(Request $request): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $this->machineCode,
                'message' => $this->getMessage(),
                'fields' => $this->fields !== [] ? $this->fields : null,
            ], fn (mixed $value): bool => $value !== null),
        ], $this->status);
    }

    private static function codeFor(string $message): string
    {
        /** @var array<string, array<string, string>> $cache */
        static $cache = [];

        $locale = App::getLocale();
        $templates = $cache[$locale] ??= static::refuseTemplates($locale);

        $best = null;
        $bestLength = 0;

        foreach ($templates as $key => $prefix) {
            if ($prefix !== '' && strlen($prefix) > $bestLength && str_starts_with($message, $prefix)) {
                $best = $key;
                $bestLength = strlen($prefix);
            }
        }

        return $best ?? 'refused';
    }

    /** @return array<string, string> */
    private static function refuseTemplates(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            return [];
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows)) {
            return [];
        }

        $templates = [];

        foreach ($rows as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, 'refuse.')) {
                $templates[$key] = trim(substr($value, 0, strcspn($value, ':')));
            }
        }

        return $templates;
    }
}
