<?php

namespace ShopAgg\AI_Deployer\Infrastructure;

final class AuditLog {

    private string $file;

    public function __construct(?string $file = null) {
        $this->file = $file ?: SHOPAGG_AI_DEPLOYER_DATA_DIR . '/logs/activity.jsonl';
    }

    public function record(string $action, array $details, bool $success): void {
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }
        $entry = [
            'time' => gmdate('c'),
            'action' => $action,
            'success' => $success,
            'actor' => is_user_logged_in() ? get_current_user_id() : null,
            'details' => $details,
            'ip_hash' => isset($_SERVER['REMOTE_ADDR'])
                ? substr(hash_hmac('sha256', (string) $_SERVER['REMOTE_ADDR'], wp_salt('auth')), 0, 12)
                : null,
        ];
        if (is_file($this->file) && (int) @filesize($this->file) > 5 * 1024 * 1024) {
            @rename($this->file, $this->file . '.1');
        }
        @file_put_contents(
            $this->file,
            wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
        @chmod($this->file, 0600);
    }

    public function recent(int $limit = 30): array {
        $limit = max(1, min(100, $limit));
        if (!is_file($this->file) || !is_readable($this->file)) {
            return [];
        }
        $lines = @file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $items = [];
        foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
            $item = json_decode($line, true);
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        return $items;
    }
}
