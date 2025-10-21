<?php
/**
 * Enhanced backup progress tracking with real-time updates
 */
class BackupProgress {
    private $progressFile;
    private $startTime;
    private $steps = [];
    private $currentStep = 0;
    private $totalSteps = 0;

    public function __construct($backupType = 'full') {
        $this->progressFile = Config::get('temp_path') . '/backup_progress.json';
        $this->startTime = time();

        // Define steps based on backup type
        $this->defineSteps($backupType);
    }

    /**
     * Define progress steps for different backup types
     */
    private function defineSteps($backupType) {
        switch($backupType) {
            case 'full':
                $this->steps = [
                    ['weight' => 5, 'name' => 'initialization', 'message' => 'Inicializando backup...'],
                    ['weight' => 10, 'name' => 'db_size_calc', 'message' => 'Calculando tamaño de base de datos...'],
                    ['weight' => 35, 'name' => 'db_export', 'message' => 'Exportando base de datos...'],
                    ['weight' => 10, 'name' => 'db_compress', 'message' => 'Comprimiendo base de datos...'],
                    ['weight' => 5, 'name' => 'storage_calc', 'message' => 'Calculando archivos de storage...'],
                    ['weight' => 25, 'name' => 'storage_copy', 'message' => 'Copiando archivos de storage...'],
                    ['weight' => 5, 'name' => 'storage_compress', 'message' => 'Comprimiendo storage...'],
                    ['weight' => 3, 'name' => 'cleanup', 'message' => 'Limpiando archivos temporales...'],
                    ['weight' => 2, 'name' => 'finalize', 'message' => 'Finalizando backup...']
                ];
                break;

            case 'database':
                $this->steps = [
                    ['weight' => 10, 'name' => 'initialization', 'message' => 'Inicializando backup de BD...'],
                    ['weight' => 15, 'name' => 'db_size_calc', 'message' => 'Calculando tamaño de base de datos...'],
                    ['weight' => 50, 'name' => 'db_export', 'message' => 'Exportando base de datos...'],
                    ['weight' => 20, 'name' => 'db_compress', 'message' => 'Comprimiendo base de datos...'],
                    ['weight' => 5, 'name' => 'finalize', 'message' => 'Finalizando backup...']
                ];
                break;

            case 'storage':
                $this->steps = [
                    ['weight' => 10, 'name' => 'initialization', 'message' => 'Inicializando backup de storage...'],
                    ['weight' => 15, 'name' => 'storage_calc', 'message' => 'Calculando archivos...'],
                    ['weight' => 60, 'name' => 'storage_copy', 'message' => 'Copiando archivos...'],
                    ['weight' => 10, 'name' => 'storage_compress', 'message' => 'Comprimiendo...'],
                    ['weight' => 5, 'name' => 'finalize', 'message' => 'Finalizando backup...']
                ];
                break;

            default:
                $this->steps = [
                    ['weight' => 100, 'name' => 'processing', 'message' => 'Procesando...']
                ];
        }

        $this->totalSteps = count($this->steps);
    }

    /**
     * Start a new step
     */
    public function startStep($stepName) {
        foreach($this->steps as $index => $step) {
            if ($step['name'] === $stepName) {
                $this->currentStep = $index;
                $percentage = $this->calculatePercentage();
                $this->update($percentage, $step['message']);
                return;
            }
        }
    }

    /**
     * Update progress within current step
     */
    public function updateStep($stepProgress, $message = null) {
        if ($this->currentStep >= $this->totalSteps) return;

        $currentStepWeight = $this->steps[$this->currentStep]['weight'];
        $basePercentage = $this->calculatePercentage();
        $stepPercentage = min(100, max(0, $stepProgress));
        $additionalProgress = ($currentStepWeight * $stepPercentage / 100);

        $totalPercentage = min(100, $basePercentage + $additionalProgress);

        $displayMessage = $message ?: $this->steps[$this->currentStep]['message'];
        $this->update($totalPercentage, $displayMessage);
    }

    /**
     * Calculate base percentage from completed steps
     */
    private function calculatePercentage() {
        $percentage = 0;
        for($i = 0; $i < $this->currentStep; $i++) {
            $percentage += $this->steps[$i]['weight'];
        }
        return $percentage;
    }

    /**
     * Update progress file
     */
    public function update($percentage, $message, $details = []) {
        $progress = [
            'percentage' => round($percentage, 1),
            'message' => $message,
            'timestamp' => time(),
            'elapsed' => time() - $this->startTime,
            'step' => $this->currentStep + 1,
            'total_steps' => $this->totalSteps,
            'current_step_name' => $this->steps[$this->currentStep]['name'] ?? 'unknown'
        ];

        // Add any additional details
        if (!empty($details)) {
            $progress['details'] = $details;
        }

        // Calculate ETA if possible
        if ($percentage > 0 && $percentage < 100) {
            $elapsed = time() - $this->startTime;
            $estimatedTotal = $elapsed / ($percentage / 100);
            $progress['eta'] = round($estimatedTotal - $elapsed);
            $progress['eta_formatted'] = $this->formatTime($progress['eta']);
        }

        file_put_contents($this->progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    /**
     * Format time in seconds to human readable
     */
    private function formatTime($seconds) {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }

    /**
     * Mark as completed
     */
    public function complete($success = true, $message = null) {
        $finalMessage = $message ?: ($success ? 'Backup completado exitosamente' : 'Backup completado con errores');
        $this->update($success ? 100 : -1, $finalMessage);
    }

    /**
     * Clean up progress file
     */
    public function cleanup() {
        if (file_exists($this->progressFile)) {
            unlink($this->progressFile);
        }
    }

    /**
     * Get current progress
     */
    public static function getCurrent() {
        $progressFile = Config::get('temp_path') . '/backup_progress.json';
        if (file_exists($progressFile)) {
            return json_decode(file_get_contents($progressFile), true);
        }
        return null;
    }
}
?>