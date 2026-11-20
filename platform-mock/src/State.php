<?php

declare(strict_types=1);

/**
 * Tiny file-backed state store for the carrier mock.
 *
 * A lock is held only while reading/writing JSON. HTTP delays are applied by
 * the caller after the state is persisted, so /mock/state stays readable while
 * a charge response is deliberately delayed.
 */
final class State
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: (getenv('MOCK_STATE_FILE') ?: __DIR__ . '/../storage/state.json');
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open mock state file: ' . $this->path);
        }

        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $this->decode($raw);
    }

    /**
     * Atomically mutate the state. The callback receives the state by reference.
     *
     * @param callable(array<string, mixed>&): mixed $callback
     */
    public function mutate(callable $callback): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open mock state file: ' . $this->path);
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $state = $this->decode($raw);

        $result = $callback($state);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $result;
    }

    /** @return array<string, mixed> */
    private function decode(string|false $raw): array
    {
        if ($raw === false || trim($raw) === '') {
            return self::defaults();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }

        return array_replace_recursive(self::defaults(), $decoded);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'config' => [
                'duplicateDetails' => true,
                'optinStatus' => 'S1000',
                'optinDelayMs' => 0,
                'optoutStatus' => 'S1000',
                'optoutDelayMs' => 0,
                'smsStatus' => 'S1000',
            ],
            'debit_queue' => [],
            'debit_requests' => [],
            'charges' => [],
            'subscription_requests' => [],
            'sms_requests' => [],
            'sms' => [],
            'callbacks' => [],
        ];
    }
}
