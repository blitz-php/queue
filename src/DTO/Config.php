<?php
namespace BlitzPHP\Queue\DTO;

use BlitzPHP\Queue\Drivers\ConnectorInterface;
use InvalidArgumentException;

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
     * Récupère une connexion spécifique
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
     * @return class-string<ConnectorInterface::class>
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
     * Set the name of the default queue connection.
     */
    public function setDefaultDriver(string $name): void
    {
		$this->default = $name;

        config()->set('queue.default', $name);
    }
}
