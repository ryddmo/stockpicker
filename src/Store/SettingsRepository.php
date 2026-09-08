<?php

declare(strict_types=1);

namespace Stockpicker\Store;

use PDO;

/**
 * Read/write access to the `settings` table (AD-8: operational parameters,
 * read every run). Values are opaque strings — callers parse and default.
 */
final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT `key`, `value` FROM settings') as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }

        return $out;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}
