<?php
/**
 * Pure-PHP smoke test for filesystem containment and backup restoration.
 * Run with: php tests/smoke.php
 */

$test_root = sys_get_temp_dir() . '/shopagg-ai-deployer-test-' . bin2hex(random_bytes(5));
$content_root = $test_root . '/wp-content';
$backup_root = $test_root . '/private/backups';
mkdir($content_root . '/plugins/example', 0700, true);
mkdir($backup_root, 0700, true);

define('SHOPAGG_AI_DEPLOYER_BACKUP_DIR', $backup_root);
require_once dirname(__DIR__) . '/includes/class-file-ops.php';
require_once dirname(__DIR__) . '/includes/class-backup.php';

$cleanup = static function () use ($test_root): void {
    $root = realpath($test_root);
    $temp = realpath(sys_get_temp_dir());
    if ($root === false || $temp === false || !str_starts_with($root, rtrim($temp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopagg-ai-deployer-test-')) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
};

try {
    $files = new WB_Deployer_File_Ops($content_root);
    $backups = new WB_Deployer_Backup($files, $backup_root, 5);
    $path = 'plugins/example/example.php';

    $write = $files->write_file($path, "<?php\nreturn 'before';\n");
    if (empty($write['success'])) {
        throw new RuntimeException('Initial write failed.');
    }
    $snapshot = $backups->create_snapshot([$path], 'smoke-test');
    if ($snapshot === null) {
        throw new RuntimeException('Snapshot creation failed.');
    }
    $files->write_file($path, "<?php\nreturn 'after';\n");
    $restore = $backups->restore_snapshot($snapshot);
    if (empty($restore['success']) || $files->read_file($path) !== "<?php\nreturn 'before';\n") {
        throw new RuntimeException('Snapshot restoration failed.');
    }
    if (!empty($files->write_file('../outside.php', 'unsafe')['success'])) {
        throw new RuntimeException('Path traversal was not rejected.');
    }
    echo "SHOPAGG smoke test passed.\n";
} finally {
    $cleanup();
}
