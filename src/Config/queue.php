<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

use BlitzPHP\Queue\Drivers\DatabaseDriver;

/**
 * Configuration du composant de files d'attente (queue).
 *
 * Ce fichier définit la connexion utilisée par défaut, les paramètres de chaque
 * backend (base de données, Redis, Predis, RabbitMQ), le mapping des pilotes,
 * ainsi que le stockage des jobs échoués et des lots (batching).
 *
 * Les valeurs peuvent être surchargées via les variables d'environnement
 * correspondantes (préfixe `queue.`, `redis.`, `rabbitmq.`, `db.`).
 */
return [
    /**
     * Nom de la connexion utilisée par défaut lorsque aucune n'est précisée
     * à l'envoi ou au traitement d'un job (`queue:work`, `dispatch`, etc.).
     *
     * Doit correspondre à une clé de `connections` (ex. `database`, `redis`).
     * Variable d'environnement : `queue.connection`.
     */
    'default' => env('queue.connection', 'database'),

    /**
     * Définitions des connexions disponibles.
     *
     * Chaque entrée est identifiée par un nom (utilisé comme `driver` si la
     * clé `driver` n'est pas fournie) et contient les options propres au backend.
     */
    'connections' => [
        /**
         * File d'attente persistée en base de données.
         *
         * Les jobs sont stockés dans une table SQL, réservés par le worker
         * (verrouillage de lignes) puis supprimés ou relâchés selon le résultat.
         */
        'database' => [
            /**
             * Groupe / nom de connexion base de données BlitzPHP à utiliser
             * pour lire et écrire les jobs. Variable : `queue.database.group`.
             */
            'connection' => env('queue.database.group', 'default'),

            /**
             * Si `true`, réutilise une connexion partagée du gestionnaire de
             * connexions plutôt que d'en ouvrir une dédiée au worker.
             */
            'shared' => true,

            /**
             * Si `true`, tente d'utiliser un verrouillage de type
             * `SKIP LOCKED` / `READPAST` (selon le moteur) afin que plusieurs
             * workers ne récupèrent pas le même job.
             */
            'skip_locked' => true,

            /**
             * Nom de la table contenant les jobs en attente, retardés ou
             * réservés. Variable : `queue.database.table`.
             */
            'table' => env('queue.database.table', 'queue_jobs'),

        	/**
         	 * Nom de la file logique par défaut pour cette connexion
         	 * (colonne `queue` en base). Utilisé si `queue:work` n'en précise pas.
         	 */
            'queue' => env('queue.defaultQueue', 'default'),

        	/**
         	 * Délai en secondes au-delà duquel un job réservé est considéré
         	 * comme expiré et peut être repris par un autre worker.
         	 */
            'retry_after' => (int) env('queue.retryAfter', 90),

        	/**
         	 * Si `true`, n'envoie le job qu'après le commit des transactions
         	 * de base de données en cours.
         	 */
            'after_commit' => false,
        ],

        /**
         * Connexion Redis (extension PHP `redis` / PhpRedis).
         *
         * Les jobs sont poussés dans des listes Redis. Le pilote correspondant
         * doit être enregistré dans `drivers` pour être utilisable.
         */
        'redis' => [
            /**
             * Identifiant du pilote à instancier (`drivers.redis`).
             */
            'driver' => 'redis',

            /**
             * Hôte du serveur Redis. Variable : `redis.host`.
             */
            'host' => env('redis.host', '127.0.0.1'),

            /**
             * Mot de passe d'authentification Redis, ou `null` si aucun.
             * Variable : `redis.password`.
             */
            'password' => env('redis.password', null),

            /**
             * Port TCP du serveur Redis. Variable : `redis.port`.
             */
            'port' => env('redis.port', 6379),

            /**
             * Index de la base Redis (0–15 en configuration par défaut).
             * Variable : `redis.database`.
             */
            'database' => env('redis.database', 0),
        ],

        /**
         * Connexion Redis via Predis (client PHP pur, sans extension).
         *
         * Utile lorsque l'extension `redis` n'est pas disponible.
         */
        'predis' => [
            /**
             * Identifiant du pilote à instancier (`drivers.predis`).
             */
            'driver' => 'predis',

            /**
             * Schéma de connexion (`tcp`, `tls`, `unix`).
             */
            'scheme' => 'tcp',

            /**
             * Hôte du serveur Redis. Variable : `redis.host`.
             */
            'host' => env('redis.host', '127.0.0.1'),

            /**
             * Mot de passe d'authentification Redis, ou `null` si aucun.
             * Variable : `redis.password`.
             */
            'password' => env('redis.password', null),

            /**
             * Port TCP du serveur Redis. Variable : `redis.port`.
             */
            'port' => env('redis.port', 6379),

            /**
             * Index de la base Redis. Variable : `redis.database`.
             */
            'database' => env('redis.database', 0),
        ],

        /**
         * Connexion RabbitMQ (AMQP).
         *
         * Les jobs sont publiés dans des files AMQP. Le pilote correspondant
         * doit être enregistré dans `drivers` pour être utilisable.
         */
        'rabbitmq' => [
            /**
             * Identifiant du pilote à instancier (`drivers.rabbitmq`).
             */
            'driver' => 'rabbitmq',

            /**
             * Hôte du courtier RabbitMQ. Variable : `rabbitmq.host`.
             */
            'host' => env('rabbitmq.host', '127.0.0.1'),

            /**
             * Port AMQP (5672 en clair, 5671 en TLS en général).
             * Variable : `rabbitmq.port`.
             */
            'port' => env('rabbitmq.port', 5672),

            /**
             * Nom d'utilisateur AMQP. Variable : `rabbitmq.user`.
             */
            'user' => env('rabbitmq.user', 'guest'),

            /**
             * Mot de passe AMQP. Variable : `rabbitmq.password`.
             */
            'password' => env('rabbitmq.password', 'guest'),

            /**
             * Hôte virtuel (vhost) isolant les files et échanges.
             * Variable : `rabbitmq.vhost`.
             */
            'vhost' => env('rabbitmq.vhost', '/'),
        ],
    ],

    /**
     * Correspondance entre le nom d'un pilote et sa classe PHP.
     *
     * La classe doit implémenter `ConnectorInterface` et exposer
     * `connect(ContainerInterface $container, array $config)`.
     * Les connexions dont le pilote n'est pas listé ici ne peuvent pas être résolues.
     */
    'drivers' => [
        /**
         * Pilote SQL : table `queue_jobs` (ou celle configurée).
         */
        'database' => DatabaseDriver::class,
        // 'redis'    => \BlitzPHP\Queue\Drivers\RedisDriver::class,
        // 'predis'   => \BlitzPHP\Queue\Drivers\PredisDriver::class,
        // 'rabbitmq' => \BlitzPHP\Queue\Drivers\RabbitMQDriver::class,
        'sync'     => \BlitzPHP\Queue\Drivers\SyncDriver::class,
        'null'     => \BlitzPHP\Queue\Drivers\NullDriver::class,
        'failover' => \BlitzPHP\Queue\Drivers\FailoverDriver::class,
    ],

    /**
     * Si `true`, les jobs définitivement en échec sont conservés via le
     * fournisseur configuré dans `failed` (base, fichier, etc.).
     * Si `false`, l'échec est uniquement journalisé / ignoré selon le fournisseur.
     */
    'keep_failed_jobs' => true,

    /**
     * Stockage des jobs échoués (après épuisement des tentatives ou échec manuel).
     */
    'failed' => [
        /**
         * Fournisseur de persistance :
         * - `database` : identifiants numériques auto-incrémentés
         * - `database-uuids` : UUID du payload comme identifiant (recommandé)
         * - `file` : fichier JSON (voir `path` / `limit` côté service)
         * - `null` : aucun stockage
         *
         * Variable : `queue.failed_driver`.
         */
        'driver' => env('queue.failed_driver', 'database-uuids'),

        /**
         * Nom de la connexion base de données utilisée pour la table des échecs
         * (pilotes `database` et `database-uuids`). Variable : `db.connection`.
         */
        'database' => env('db.connection', 'default'),

        /**
         * Table SQL des jobs échoués (`uuid`, `connection`, `queue`, `payload`, `exception`, `failed_at`).
         */
        'table' => 'queue_failed_jobs',

        // Pour le driver 'file'
        // 'path'  => storage_path('logs/failed_jobs.json'),
        // 'limit' => 100,
    ],

    /**
     * Stockage des lots de jobs (batching) : suivi d'un groupe de jobs
     * dispatchés ensemble (progression, annulation, callbacks de fin).
     */
    'batching' => [
        /**
         * Connexion base de données pour la table des lots.
         * Variable : `db.connection`.
         */
        'database' => env('db.connection', 'default'),

        /**
         * Nom de la table (ou identifiant de stockage) des lots de jobs.
         */
        'table' => 'queue_job_batches',
    ],
];
