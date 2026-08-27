<?php
namespace BlitzPHP\Queue;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Security\EncrypterInterface;
use BlitzPHP\Queue\Exceptions\MaxAttemptsExceededException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use Exception;
use RuntimeException;
use Throwable;

class CallQueuedHandler
{
    /**
     * Constructeur
     */
    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * Handle the queued job.
     * C'est la méthode appelée par le worker via JobName::parse()
     */
    public function call(Job $job, array $data): void
    {
        try {
            // Récupérer la commande (le job utilisateur)
            $command = $this->getCommand($data);
            
            // Vérifier si c'est une classe incomplète
            if ($command instanceof \__PHP_Incomplete_Class) {
                throw new Exception('Job is incomplete class: ' . json_encode($command));
            }

            // Injecter les dépendances
            $command = $this->setJobInstanceIfNecessary($job, $command);

            // Si le job a déjà été supprimé, on arrête
            if ($job->isDeleted()) {
                return;
            }

            // Exécuter le job
            $this->executeCommand($command);

            // Si le job n'a pas été supprimé ou relâché, on le supprime
            if (!$job->isDeletedOrReleased()) {
                $job->delete();
            }

        } catch (ModelNotFoundException $e) {
            // Gérer le cas où un modèle n'est pas trouvé
            $this->handleModelNotFound($job, $e);
        } catch (Throwable $e) {
            // Gérer les autres exceptions
            $this->handleException($job, $data, $e);
            throw $e;
        }
    }

    /**
     * Récupère la commande (le job utilisateur) depuis les données
     */
    protected function getCommand(array $data): mixed
    {
        if (!isset($data['command'])) {
            throw new RuntimeException('Job data missing "command" key.');
        }

        // Si c'est déjà un objet (pour les jobs sync)
        if (is_object($data['command']) && !is_string($data['command'])) {
            return $data['command'];
        }

        // Si c'est une chaîne sérialisée
        if (is_string($data['command'])) {
            // Vérifier si c'est du sérialisé PHP
            if (str_starts_with($data['command'], 'O:')) {
                $command = unserialize($data['command']);
                if ($command !== false) {
                    return $command;
                }
            }

            // Essayer de décrypter si c'est encrypté
            if ($this->container->bound(EncrypterInterface::class)) {
                try {
                    $decrypted = $this->container->get(EncrypterInterface::class)->decrypt($data['command']);
                    $command = unserialize($decrypted);
                    if ($command !== false) {
                        return $command;
                    }
                } catch (Throwable $e) {
                    // Ignorer l'erreur de décryptage
                }
            }
        }

        throw new RuntimeException('Unable to extract job payload.');
    }

    /**
     * Set the job instance of the given class if necessary.
     */
    protected function setJobInstanceIfNecessary(Job $job, mixed $instance): mixed
    {
        // Vérifier si la classe utilise le trait InteractsWithQueue
        if (is_object($instance) && $this->usesInteractsWithQueue($instance)) {
            if (method_exists($instance, 'setJob')) {
                $instance->setJob($job);
            }
        }

        // Si c'est un CallQueuedClosure, on lui passe le container
        if ($instance instanceof CallQueuedClosure) {
            // Déjà géré dans executeCommand
        }

        return $instance;
    }

    /**
     * Vérifie si la classe utilise le trait InteractsWithQueue
     */
    protected function usesInteractsWithQueue(object $instance): bool
    {
        $traits = Helpers::classUsesRecursive($instance);
        
        return isset($traits[Traits\InteractsWithQueue::class]) ||
               isset($traits['BlitzPHP\\Queue\\Traits\\InteractsWithQueue']);
    }

    /**
     * Exécute la commande (le job)
     */
    protected function executeCommand(object $command): void
    {
        // Si c'est un CallQueuedClosure
        if ($command instanceof CallQueuedClosure) {
            $command->handle($this->container);
            return;
        }

        // Si le job a une méthode handle() (cas standard)
        if (method_exists($command, 'handle')) {
            $this->container->call([$command, 'handle']);
            return;
        }

        // Si c'est callable (__invoke)
        if (is_callable($command)) {
            $this->container->call($command);
            return;
        }

        throw new RuntimeException(
            'Job does not have a handle() method and is not callable: ' . get_class($command)
        );
    }

    /**
     * Gère une exception pendant l'exécution du job
     */
    protected function handleException(Job $job, array $data, Throwable $e): void
    {
        // Si le job a déjà été marqué comme échoué, on arrête
        if ($job->hasFailed()) {
            return;
        }

        // Récupérer la commande pour les métadonnées
        $command = null;
        try {
            $command = $this->getCommand($data);
        } catch (Throwable $parseError) {
            // Ignorer l'erreur de parsing
        }

        // Vérifier si le job a dépassé le nombre max de tentatives
        $maxTries = $this->getMaxTries($command);
        $attempts = $job->attempts();

        if ($attempts >= $maxTries) {
            // Marquer comme échoué
            $job->markAsFailed();
            
            // Appeler la méthode failed du job si elle existe
            if ($command && method_exists($command, 'failed')) {
                try {
                    $command->failed($e);
                } catch (Throwable $failedError) {
                    // Ignorer les erreurs dans failed()
                }
            }

            // Logger l'échec
            logger()->error('Job failed after max attempts', [
                'job' => $this->getJobName($command, $data),
                'attempts' => $attempts,
                'max_tries' => $maxTries,
                'error' => $e->getMessage(),
                'job_id' => $job->getJobId(),
                'queue' => $job->getQueue(),
            ]);

            // Enregistrer dans le provider de jobs échoués
            $this->logFailedJob($job, $e);

            // Supprimer le job
            $job->delete();

            throw new MaxAttemptsExceededException(
                'Job failed after ' . $maxTries . ' attempts: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Calculer le backoff
        $backoff = $this->calculateBackoff($command, $attempts);
        
        // Relâcher le job avec backoff
        $job->release($backoff);

        logger()->warning('Job released for retry', [
            'job' => $this->getJobName($command, $data),
            'attempts' => $attempts,
            'backoff' => $backoff,
            'error' => $e->getMessage(),
            'job_id' => $job->getJobId(),
            'queue' => $job->getQueue(),
        ]);
    }

    /**
     * Récupère le nombre max de tentatives
     */
    protected function getMaxTries(?object $command): int
    {
        if ($command === null) {
            return config('queue.max_tries', 3);
        }

        if (method_exists($command, 'maxTries')) {
            $maxTries = $command->maxTries();
            if ($maxTries !== null) {
                return (int) $maxTries;
            }
        }

        if (property_exists($command, 'maxTries')) {
            return (int) $command->maxTries;
        }

        return config('queue.max_tries', 3);
    }

    /**
     * Calcule le backoff pour le retry
     */
    protected function calculateBackoff(?object $command, int $attempts): int
    {
        $backoff = 60; // Valeur par défaut

        if ($command !== null) {
            if (method_exists($command, 'backoff')) {
                $backoffValue = $command->backoff();
                if (is_array($backoffValue)) {
                    $backoff = $backoffValue[$attempts - 1] ?? $backoffValue[0] ?? 60;
                } else {
                    $backoff = (int) $backoffValue;
                }
            } elseif (property_exists($command, 'backoff')) {
                $backoffValue = $command->backoff;
                if (is_array($backoffValue)) {
                    $backoff = $backoffValue[$attempts - 1] ?? $backoffValue[0] ?? 60;
                } else {
                    $backoff = (int) $backoffValue;
                }
            }
        }

        // Si le backoff est 0, on utilise un backoff exponentiel
        if ($backoff === 0) {
            $backoff = 60 * pow(2, $attempts - 1);
        }

        return $backoff;
    }

    /**
     * Récupère le nom du job pour les logs
     */
    protected function getJobName(?object $command, array $data): string
    {
        if ($command !== null) {
            return get_class($command);
        }

        return $data['commandName'] ?? $data['displayName'] ?? 'Unknown';
    }

    /**
     * Enregistre le job comme échoué
     */
    protected function logFailedJob(Job $job, Throwable $e): void
    {
        try {
            $failedProvider = $this->container->get(\BlitzPHP\Queue\Failed\FailedJobProviderInterface::class);
            
            $failedProvider->log(
                $job->getConnectionName(),
                $job->getQueue(),
                $job->getRawBody(),
                $e
            );
        } catch (Throwable $logError) {
            // Ignorer les erreurs de logging
            logger()->error('Failed to log failed job', [
                'error' => $logError->getMessage(),
                'job_id' => $job->getJobId()
            ]);
        }
    }

    /**
     * Gère le cas où un modèle n'est pas trouvé
     */
    protected function handleModelNotFound(Job $job, ModelNotFoundException $e): void
    {
        $payload = $job->payload();
        
        // Vérifier si on doit supprimer le job quand les modèles sont manquants
        if (isset($payload['deleteWhenMissingModels']) && $payload['deleteWhenMissingModels']) {
            $job->delete();
            logger()->warning('Job deleted because model was not found', [
                'job_id' => $job->getJobId(),
                'queue' => $job->getQueue(),
                'model' => $e->getModel(),
            ]);
            return;
        }

        // Sinon, on marque comme échoué
        $job->fail($e);
    }

    /**
     * Méthode appelée quand le job échoue définitivement
     * (appelée par le worker après max attempts)
     */
    public function failed(array $data, Throwable $e, string $uuid, ?Job $job = null): void
    {
        try {
            $command = $this->getCommand($data);
            
            if ($command instanceof \__PHP_Incomplete_Class) {
                return;
            }

            if ($job !== null) {
                $command = $this->setJobInstanceIfNecessary($job, $command);
            }

            // Appeler la méthode failed du job si elle existe
            if (is_object($command) && method_exists($command, 'failed')) {
                $command->failed($e);
            }

            logger()->critical('Job permanently failed', [
                'job' => $this->getJobName($command ?? null, $data),
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

        } catch (Throwable $handledError) {
            // Ignorer les erreurs dans failed()
            logger()->error('Error in CallQueuedHandler::failed', [
                'error' => $handledError->getMessage(),
            ]);
        }
    }
}
