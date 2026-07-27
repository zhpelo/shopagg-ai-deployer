<?php

namespace ShopAgg\AI_Deployer\Infrastructure;

final class OperationLock {

    /** @var resource|null */
    private $handle = null;

    public function acquire(string $name = 'deployment'): bool {
        if (is_resource($this->handle)) {
            return true;
        }
        $path = SHOPAGG_AI_DEPLOYER_DATA_DIR . '/' . sanitize_key($name) . '.lock';
        $this->handle = @fopen($path, 'c+');
        if (!is_resource($this->handle)) {
            $this->handle = null;
            return false;
        }
        @chmod($path, 0600);
        if (!@flock($this->handle, LOCK_EX | LOCK_NB)) {
            @fclose($this->handle);
            $this->handle = null;
            return false;
        }
        @ftruncate($this->handle, 0);
        @fwrite($this->handle, wp_json_encode([
            'pid' => getmypid(),
            'time' => gmdate('c'),
        ]));
        @fflush($this->handle);
        return true;
    }

    public function release(): void {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct() {
        $this->release();
    }
}
