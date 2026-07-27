<?php

namespace ShopAgg\AI_Deployer\Application;

use ShopAgg\AI_Deployer\Domain\DeploymentService;
use ShopAgg\AI_Deployer\Domain\ExtensionService;
use ShopAgg\AI_Deployer\Domain\HealthVerifier;
use ShopAgg\AI_Deployer\Domain\WordPressGateway;
use ShopAgg\AI_Deployer\Domain\WorkspaceService;
use ShopAgg\AI_Deployer\Infrastructure\AuditLog;
use ShopAgg\AI_Deployer\Infrastructure\OperationLock;

final class ServiceContainer {

    private static ?self $instance = null;
    private ?\WB_Deployer_File_Ops $files = null;
    private ?\WB_Deployer_Backup $backups = null;
    private ?AuditLog $audit = null;
    private ?HealthVerifier $health = null;
    private ?WorkspaceService $workspace = null;
    private ?DeploymentService $deployment = null;
    private ?WordPressGateway $wordpress = null;
    private ?ExtensionService $extensions = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function files(): \WB_Deployer_File_Ops {
        $this->loadFileComponents();
        return $this->files ??= new \WB_Deployer_File_Ops();
    }

    public function backups(): \WB_Deployer_Backup {
        $this->loadFileComponents();
        return $this->backups ??= new \WB_Deployer_Backup($this->files());
    }

    public function audit(): AuditLog {
        return $this->audit ??= new AuditLog();
    }

    public function health(): HealthVerifier {
        return $this->health ??= new HealthVerifier();
    }

    public function workspace(): WorkspaceService {
        return $this->workspace ??= new WorkspaceService($this->files());
    }

    public function deployment(): DeploymentService {
        return $this->deployment ??= new DeploymentService(
            $this->files(),
            $this->backups(),
            $this->workspace(),
            $this->health(),
            $this->audit(),
            new OperationLock()
        );
    }

    public function wordpress(): WordPressGateway {
        return $this->wordpress ??= new WordPressGateway();
    }

    public function extensions(): ExtensionService {
        return $this->extensions ??= new ExtensionService(
            $this->files(),
            $this->backups(),
            $this->health(),
            $this->audit(),
            new OperationLock()
        );
    }

    private function loadFileComponents(): void {
        if (!class_exists('WB_Deployer_File_Ops', false)) {
            require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-file-ops.php';
        }
        if (!class_exists('WB_Deployer_Backup', false)) {
            require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-backup.php';
        }
    }
}
