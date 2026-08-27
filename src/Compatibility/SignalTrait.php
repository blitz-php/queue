<?php

namespace BlitzPHP\Queue\Compatibility;

use Closure;

if (trait_exists('BlitzPHP\CLI\SignalTrait')) {
    trait SignalTrait
    {
        use \BlitzPHP\CLI\SignalTrait;
    }
} else {
    /**
     * Trait de gestion des signaux.
     *
     * Fournit la gestion des signaux PCNTL pour les commandes CLI.
     * Nécessite l'extension PCNTL (Unix uniquement).
     *
     * Version de compatibilité fournie pour BlitzPHP < 1.2.
     */
    trait SignalTrait
    {
        /**
         * Indique si le processus doit continuer (false = arrêt demandé).
         */
        private bool $running = true;

        /**
         * Indique si les signaux sont actuellement bloqués.
         */
        private bool $signalsBlocked = false;

        /**
         * Liste des signaux enregistrés.
         *
         * @var list<int>
         */
        private array $registeredSignals = [];

        /**
         * Correspondance signal → méthode.
         *
         * @var array<int, string>
         */
        private array $signalMethodMap = [];

        /**
         * Résultat mis en cache de la disponibilité de l'extension PCNTL.
         */
        private static ?bool $isPcntlAvailable = null;

        /**
         * Résultat mis en cache de la disponibilité de l'extension POSIX.
         */
        private static ?bool $isPosixAvailable = null;

        /**
         * Indique si l'extension PCNTL est disponible (valeur mise en cache).
         */
        protected function isPcntlAvailable(): bool
        {
            if (self::$isPcntlAvailable === null) {
                if (is_windows()) {
                    self::$isPcntlAvailable = false;
                } else {
                    self::$isPcntlAvailable = extension_loaded('pcntl');
                    if (! self::$isPcntlAvailable) {
                        // CLI::write('PCNTL extension is not available. Signal handling will be disabled.', 'yellow');
                    }
                }
            }

            return self::$isPcntlAvailable;
        }

        /**
         * Indique si l'extension POSIX est disponible (valeur mise en cache).
         */
        protected function isPosixAvailable(): bool
        {
            if (self::$isPosixAvailable === null) {
                self::$isPosixAvailable = is_windows() ? false : extension_loaded('posix');
            }

            return self::$isPosixAvailable;
        }

        /**
         * Enregistre les gestionnaires de signaux.
         *
         * @param list<int>          $signals   Liste des signaux à traiter.
         * @param array<int, string> $methodMap Correspondance optionnelle signal → méthode.
         */
        protected function registerSignals(
            array $signals = [],
            array $methodMap = [],
        ): void {
            if (! $this->isPcntlAvailable()) {
                return;
            }

            if ($signals === []) {
                $signals = [SIGTERM, SIGINT, SIGHUP, SIGQUIT];
            }

            if (! $this->isPosixAvailable() && (in_array(SIGTSTP, $signals, true) || in_array(SIGCONT, $signals, true))) {
                // CLI::write('POSIX extension is not available. SIGTSTP and SIGCONT signals will be disabled.', 'yellow');
                $signals = array_diff($signals, [SIGTSTP, SIGCONT]);

                // Retire aussi les associations de méthodes
                unset($methodMap[SIGTSTP], $methodMap[SIGCONT]);

                if ($signals === []) {
                    return;
                }
            }

            // Active les signaux asynchrones pour une réaction immédiate
            pcntl_async_signals(true);

            $this->signalMethodMap = $methodMap;

            foreach ($signals as $signal) {
                if (pcntl_signal($signal, [$this, 'handleSignal'])) {
                    $this->registeredSignals[] = $signal;
                } else {
                    $signal = $this->getSignalName($signal);
                    // CLI::write("Failed to register signal handler for {$signal}.", 'red');
                }
            }
        }

        /**
         * Traite les signaux reçus.
         */
        protected function handleSignal(int $signal): void
        {
            $this->callCustomHandler($signal);

            // Applique le comportement Unix standard pour les signaux enregistrés
            switch ($signal) {
                case SIGTERM:
                case SIGINT:
                case SIGQUIT:
                case SIGHUP:
                    $this->running = false;
                    break;

                case SIGTSTP:
                    // Restaure le handler par défaut et renvoie le signal pour suspendre vraiment
                    pcntl_signal(SIGTSTP, SIG_DFL);
                    posix_kill(posix_getpid(), SIGTSTP);
                    break;

                case SIGCONT:
                    // Réenregistre le handler SIGTSTP après reprise
                    pcntl_signal(SIGTSTP, [$this, 'handleSignal']);
                    break;
            }
        }

        /**
         * Appelle le gestionnaire personnalisé s'il est associé à ce signal.
         * Se rabat sur onInterruption() si aucune association explicite n'existe.
         */
        private function callCustomHandler(int $signal): void
        {
            // Association explicite en priorité
            $method = $this->signalMethodMap[$signal] ?? null;

            if ($method !== null && method_exists($this, $method)) {
                $this->{$method}($signal);

                return;
            }

            // Si aucune association, tente la méthode générique onInterruption()
            if (method_exists($this, 'onInterruption')) { // @phpstan-ignore-line
                $this->onInterruption($signal);
            }
        }

        /**
         * Indique si la commande doit s'arrêter.
         */
        protected function shouldTerminate(): bool
        {
            return ! $this->running;
        }

        /**
         * Indique si le processus est encore en cours d'exécution.
         */
        protected function isRunning(): bool
        {
            return $this->running;
        }

        /**
         * Demande l'arrêt immédiat.
         */
        protected function requestTermination(): void
        {
            $this->running = false;
        }

        /**
         * Réinitialise tous les états (tests ou redémarrage).
         */
        protected function resetState(): void
        {
            $this->running = true;

            // Débloque les signaux s'ils l'étaient
            if ($this->signalsBlocked) {
                $this->unblockSignals();
            }
        }

        /**
         * Exécute un callable en bloquant tous les signaux pour éviter toute interruption.
         *
         * Bloque tous les signaux interruptibles, notamment :
         * - signaux de terminaison (SIGTERM, SIGINT, etc.)
         * - pause / reprise (SIGTSTP, SIGCONT)
         * - signaux personnalisés (SIGUSR1, SIGUSR2)
         *
         * Seul SIGKILL (non bloquable) peut encore terminer le processus.
         * À utiliser pour les transactions SQL, les I/O fichiers ou toute opération atomique critique.
         *
         * @template TReturn
         *
         * @param Closure():TReturn $operation
         *
         * @return TReturn
         */
        protected function withSignalsBlocked(Closure $operation)
        {
            $this->blockSignals();

            try {
                return $operation();
            } finally {
                $this->unblockSignals();
            }
        }

        /**
         * Bloque tous les signaux interruptibles pendant une section critique.
         * Seul SIGKILL (non bloquable) peut encore terminer le processus.
         */
        protected function blockSignals(): void
        {
            if (! $this->signalsBlocked && $this->isPcntlAvailable()) {
                // Bloque tous les signaux susceptibles d'interrompre une section critique
                pcntl_sigprocmask(SIG_BLOCK, [
                    SIGTERM, SIGINT, SIGHUP, SIGQUIT, // Signaux de terminaison
                    SIGTSTP, SIGCONT,                 // Pause / reprise
                    SIGUSR1, SIGUSR2,                 // Signaux personnalisés
                    SIGPIPE, SIGALRM,                 // Autres signaux courants
                ]);
                $this->signalsBlocked = true;
            }
        }

        /**
         * Débloque les signaux précédemment bloqués.
         */
        protected function unblockSignals(): void
        {
            if ($this->signalsBlocked && $this->isPcntlAvailable()) {
                // Débloque les mêmes signaux qu'on a bloqués
                pcntl_sigprocmask(SIG_UNBLOCK, [
                    SIGTERM, SIGINT, SIGHUP, SIGQUIT, // Signaux de terminaison
                    SIGTSTP, SIGCONT,                 // Pause / reprise
                    SIGUSR1, SIGUSR2,                 // Signaux personnalisés
                    SIGPIPE, SIGALRM,                 // Autres signaux courants
                ]);
                $this->signalsBlocked = false;
            }
        }

        /**
         * Indique si les signaux sont actuellement bloqués.
         */
        protected function signalsBlocked(): bool
        {
            return $this->signalsBlocked;
        }

        /**
         * Ajoute ou met à jour une association signal → méthode à l'exécution.
         */
        protected function mapSignal(int $signal, string $method): void
        {
            $this->signalMethodMap[$signal] = $method;
        }

        /**
         * Retourne le nom lisible du signal.
         */
        protected function getSignalName(int $signal): string
        {
            return match ($signal) {
                SIGTERM => 'SIGTERM',
                SIGINT  => 'SIGINT',
                SIGHUP  => 'SIGHUP',
                SIGQUIT => 'SIGQUIT',
                SIGUSR1 => 'SIGUSR1',
                SIGUSR2 => 'SIGUSR2',
                SIGPIPE => 'SIGPIPE',
                SIGALRM => 'SIGALRM',
                SIGTSTP => 'SIGTSTP',
                SIGCONT => 'SIGCONT',
                default => "Signal {$signal}",
            };
        }

        /**
         * Désenregistre tous les signaux (nettoyage).
         */
        protected function unregisterSignals(): void
        {
            if (! $this->isPcntlAvailable()) {
                return;
            }

            foreach ($this->registeredSignals as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }

            $this->registeredSignals = [];
            $this->signalMethodMap   = [];
        }

        /**
         * Indique si des signaux sont enregistrés.
         */
        protected function hasSignals(): bool
        {
            return $this->registeredSignals !== [];
        }

        /**
         * Retourne la liste des signaux enregistrés.
         *
         * @return list<int>
         */
        protected function getSignals(): array
        {
            return $this->registeredSignals;
        }

        /**
         * Retourne un état complet du processus.
         *
         * @return array{
         *      pid: int,
         *      running: bool,
         *      pcntl_available: bool,
         *      registered_signals: int,
         *      registered_signals_names: array<int, string>,
         *      signals_blocked: bool,
         *      explicit_mappings: int,
         *      memory_usage_mb: float,
         *      memory_peak_mb: float,
         *      session_id?: false|int,
         *      process_group?: false|int,
         *      has_controlling_terminal?: bool
         *  }
         */
        protected function getProcessState(): array
        {
            $pid   = getmypid();
            $state = [
                // Identification du processus
                'pid'     => $pid,
                'running' => $this->running,

                // État de la gestion des signaux
                'pcntl_available'          => $this->isPcntlAvailable(),
                'registered_signals'       => count($this->registeredSignals),
                'registered_signals_names' => array_map([$this, 'getSignalName'], $this->registeredSignals),
                'signals_blocked'          => $this->signalsBlocked,
                'explicit_mappings'        => count($this->signalMethodMap),

                // Ressources système
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ];

            // Infos de contrôle de terminal si l'extension POSIX est disponible
            if ($this->isPosixAvailable()) {
                $state['session_id']               = posix_getsid($pid);
                $state['process_group']            = posix_getpgid($pid);
                $state['has_controlling_terminal'] = posix_isatty(STDIN);
            }

            return $state;
        }
    }
}
