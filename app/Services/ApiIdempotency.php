<?php

namespace App\Services;

use App\Exceptions\ApiRefusal;
use App\Models\ApiOperation;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One attempt, one receipt.
 *
 * The phone repeats itself on purpose — a queue that is flushed twice, a request answered by a
 * timeout and sent again — so the SERVER has to be the thing that remembers. A key is required on
 * every money-writing endpoint, it is bound to one canonical payload, and it is spent once:
 *
 *  - the same key with the same body returns the FIRST answer and charges nothing more;
 *  - the same key with a different body is a conflict (409) — never a second receipt;
 *  - a refusal by the engine does not spend the key: the collector corrects the field and the same
 *    queued attempt goes through;
 *  - the key is the client's, the money is the server's: no amount in the body is trusted.
 */
final class ApiIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /** Long enough not to collide by accident, and narrow enough to be a key rather than a payload. */
    private const SHAPE = '/^[A-Za-z0-9._:-]{16,128}$/';

    /**
     * @param  array<string, mixed>  $payload  the canonical request, as it signed the attempt
     * @return array{payload: array<string, mixed>, replay: bool}
     */
    public static function run(Request $request, User $user, string $action, array $payload, Closure $work): array
    {
        $key = self::key($request);
        $hash = self::hash($action, $payload);

        try {
            return [
                'payload' => DB::transaction(function () use ($user, $action, $key, $hash, $payload, $work): array {
                    $operation = ApiOperation::create([
                        'user_id' => $user->id,
                        'action' => $action,
                        'key' => $key,
                        'request_hash' => $hash,
                        'request' => $payload,
                        'status' => ApiOperation::STATUS_IN_PROGRESS,
                    ]);

                    $result = $work();

                    $operation->forceFill([
                        'status' => ApiOperation::STATUS_COMMITTED,
                        'response' => $result,
                    ])->save();

                    return $result;
                }),
                'replay' => false,
            ];
        } catch (QueryException $e) {
            if (! self::isDuplicateKey($e)) {
                throw $e;
            }
        }

        $existing = ApiOperation::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($existing === null || ! hash_equals((string) $existing->request_hash, $hash)) {
            // Either the row vanished between the failure and this read, or the same key signed a
            // different attempt. Both answers are the same one: this key is already spoken for.
            throw new ApiRefusal('mobile.idempotency_conflict', __('mobile.idempotency_conflict'), 409);
        }

        if ($existing->status !== ApiOperation::STATUS_COMMITTED || $existing->response === null) {
            // The other request holds the key and has not committed yet. Answering with the first
            // request's result before it exists would be inventing a receipt.
            throw new ApiRefusal('mobile.operation_in_progress', __('mobile.operation_in_progress'), 409);
        }

        return ['payload' => $existing->response, 'replay' => true];
    }

    private static function key(Request $request): string
    {
        // Header-only contract: a key that ALSO arrives in the body means two sources that can
        // disagree, and no defensible way to choose between them.
        foreach (['idempotency_key', 'idempotencyKey', 'idempotency-key', 'Idempotency-Key'] as $name) {
            if ($request->request->has($name) || $request->query->has($name) || array_key_exists($name, (array) $request->json()->all())) {
                throw new ApiRefusal('mobile.idempotency_key_in_body', __('mobile.idempotency_key_in_body'));
            }
        }

        $key = (string) $request->header(self::HEADER, '');

        if (trim($key) === '') {
            throw new ApiRefusal('mobile.idempotency_key_missing', __('mobile.idempotency_key_missing'));
        }

        if (! preg_match(self::SHAPE, $key)) {
            throw new ApiRefusal('mobile.idempotency_key_malformed', __('mobile.idempotency_key_malformed'));
        }

        return $key;
    }

    private static function hash(string $action, array $payload): string
    {
        return hash('sha256', (string) json_encode(
            ['action' => $action, 'payload' => self::canonical($payload)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** Key order is not a difference: the same fields must sign the same attempt whichever way round. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::canonical($item);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) === '23000') {
            return true;
        }

        return str_contains(strtoupper((string) $e->getMessage()), 'UNIQUE');
    }
}
