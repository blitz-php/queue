BlitzPHP - Queue
==============
### Gestionnaire de file d'attente pour BlitzPHP

[![Tests](https://github.com/blitz-php/queue/actions/workflows/run-tests.yml/badge.svg)](https://github.com/blitz-php/queue/actions/workflows/run-tests.yml)
[![Code Coverage](https://scrutinizer-ci.com/g/blitz-php/queue/badges/coverage.png?b=main)](https://scrutinizer-ci.com/g/blitz-php/queue/?branch=main)
[![Coding Standards](https://github.com/blitz-php/queue/actions/workflows/test-coding-standards.yml/badge.svg)](https://github.com/blitz-php/queue/actions/workflows/test-coding-standards.yml)
[![Build Status](https://scrutinizer-ci.com/g/blitz-php/queue/badges/build.png?b=main)](https://scrutinizer-ci.com/g/blitz-php/queue/build-status/main)
[![Code Intelligence Status](https://scrutinizer-ci.com/g/blitz-php/queue/badges/code-intelligence.svg?b=main)](https://scrutinizer-ci.com/code-intelligence)
[![Quality Score](https://img.shields.io/scrutinizer/g/blitz-php/queue.svg?style=flat-square)](https://scrutinizer-ci.com/g/blitz-php/queue)
[![PHPStan](https://github.com/blitz-php/queue/actions/workflows/test-phpstan.yml/badge.svg)](https://github.com/blitz-php/queue/actions/workflows/test-phpstan.yml)
[![PHPStan level](https://img.shields.io/badge/PHPStan-level%206-brightgreen)](phpstan.neon.dist)

[![Total Downloads](https://poser.pugx.org/blitz-php/queue/downloads)](https://packagist.org/packages/blitz-php/queue)
[![Latest Version](https://img.shields.io/packagist/v/blitz-php/queue.svg?style=flat-square)](https://packagist.org/packages/blitz-php/queue)
![PHP](https://img.shields.io/badge/PHP-%5E8.1-blue)
![BlitzPHP](https://img.shields.io/badge/BlitzPHP-%5E0.11.3-yellow)
[![Software License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

<br>

Introduction
------------

## Installation

Vous pouvez installer le package via composer :

```bash
composer require blitz-php/queue
```

Une fois l'installation terminée, vous devez migrer votre base de données:

```bash
php klinge migrate --all
```
    
## Configuration

Publier le fichier de configuration : 

```bash 
php klinge queue:publish
``` 

Créez votre premier Job via la commande:

```bash
php klinge queue:job Example
```

Et ajoutez-le au tableau des gestionnaires (`jobs`) dans le fichier `app\Config\queue.php`:

```php
// ...

use App\Jobs\Example;

// ...

return [
	// ---
	
	'jobs' => [
		'my-example' => Example::class
	],

	// ---
];

// ...
```

## Utilisation de base

Ajoutez le job à la file d'attente:

```php
service('queue')->push('queueName', 'my-example', ['data' => 'array']);
```

Exécutez la file d'attente:

```bash
php spark queue:work queueName
```

## Documentation 

Lire la documentation complète : http://blitz-php.byethost14.com 

## Contribuer 

Nous acceptons et encourageons les contributions de la communauté sous n'importe quelle forme. Peu importe que vous sachiez coder, écrire de la documentation ou aider à trouver des bogues, toutes les contributions sont les bienvenues. 

Veuillez consulter [CONTRIBUTING.md](CONTRIBUTING.md) pour plus de détails.

## Credits

Ce package est une réadaptation du package <a href="https://github.com/codeigniter4/queue" target="_blank">CodeIgniter/Queue</a> pour pouvoir avoir le même fonctionnement avec BlitzPHP. De ce fait tout le mérite revient à <a href="https://github.com/codeigniter4/queue/graphs/contributors" target="_blank">tous les contributeurs de ce projet</a> que nous remercions sincerement pour ce qu'ils font pour l'évolution du développement web

Pour la réadaptation, nous disons merci à : 
- [Dimitri Sitchet Tomkeu](http://github.com/dimtrovich)
- [Tous les Contributeurs](../../contributors)

## Licence

**BlitzPHP - Queue** est un package open source publié sous licence MIT. Veuillez consulter [le fichier de licence](LICENSE.md) pour plus d'informations.
