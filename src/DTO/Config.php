<?php
namespace BlitzPHP\Queue\DTO;

use BlitzPHP\Queue\Drivers\ConnectorInterface;
use InvalidArgumentException;

/**
 * Représentation objet de la configuration `queue.php`.
 *
 * Sert au gestionnaire pour résoudre la connexion par défaut, les options
 * de chaque backend, les classes de pilotes et le stockage des échecs.
 */
class Config
{
    /**
     * @param string $default Le nom de la connexion par défaut
     * @param array<string, array<string, mixed>> $connections Les configurations des connexions
     * @param array<string, class-string<ConnectorInterface>> $drivers Les drivers disponibles
     * @param bool $keep_failed_jobs Garder les jobs échoués
     * @param array{driver: string, database: string, table: string} $failed Configuration des jobs échoués
     * @param array{database: string, table: string} $batching Configuration du batching
     * @param array<string, mixed> $raw Données brutes supplémentaires
     */
    public function __construct(
        public string $default,
        public  array $connections     = [],
        public  array $drivers         = [],
        public  bool $keep_failed_jobs = true,
        public  array $failed          = [],
        public  array $batching        = [],
        private array $raw             = [],
    ) {
    }

    /**
     * Crée une instance depuis la configuration automatique
     */
    public static function auto(): self
    {
        return self::fromArray(config('queue', []));
    }

    /**
     * Crée une instance depuis un tableau de configuration
     */
    public static function fromArray(array $config): self
    {
        return new self(
            default         : $config['default'] ?? 'database',
            connections     : $config['connections'] ?? [],
            drivers         : $config['drivers'] ?? [],
            keep_failed_jobs: $config['keep_failed_jobs'] ?? true,
            failed          : $config['failed'] ?? [],
            batching        : $config['batching'] ?? [],
            raw             : $config,
        );
    }

    /**
     * Convertit l'objet en tableau
     */
    public function toArray(): array
    {
        return array_merge(
            [
                'default'          => $this->default,
                'connections'      => $this->connections,
                'drivers'          => $this->drivers,
                'keep_failed_jobs' => $this->keep_failed_jobs,
                'failed'           => $this->failed,
                'batching'         => $this->batching,
            ],
            $this->raw
        );
    }

    /**
     * Retourne la configuration d'une connexion, ou un pilote `null` si le nom est vide.
     *
     * @param string|null $name Nom de la connexion (`connections.{name}`).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException Si la connexion n'est pas définie.
     */
    public function connection(?string $name): array
    {
        if ($name === null || $name === 'null') {
			return ['driver' => 'null'];
        }

        if (!isset($this->connections[$name])) {
            throw new InvalidArgumentException("The [{$name}] queue connection has not been configured.");
        }

        return $this->connections[$name] + ['driver' => $name];
    }

    /**
     * Retourne le nom de classe du pilote enregistré pour le nom donné.
     *
     * @param string $name Nom du pilote (ex. `database`).
     *
     * @return class-string<ConnectorInterface>
     *
     * @throws InvalidArgumentException Si le pilote n'est pas enregistré ou n'implémente pas le contrat.
     */
    public function driver(string $name): string 
    {
        $driver = $this->drivers[$name] ?? null;

        if ($driver === null) {
            throw new InvalidArgumentException("Driver [{$name}] not registered.");
        }

        if (! is_a($driver, ConnectorInterface::class, true)) {
            throw new InvalidArgumentException();
        }

        return $driver;
    }

    /**
     * Définit le nom de la connexion de file d'attente par défaut.
     *
     * Met aussi à jour la configuration runtime `queue.default`.
     */
    public function setDefaultDriver(string $name): void
    {
		$this->default = $name;

        config()->set('queue.default', $name);
    }
}
