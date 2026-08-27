<?php

namespace BlitzPHP\Queue\Database\Migrations;

use BlitzPHP\Database\Migration\Migration;
use BlitzPHP\Database\Migration\Structure;

/**
 * Crée les tables des jobs en file et des jobs échoués.
 */
class CreateQueueTables extends Migration
{
    /**
     * Crée `queue_jobs` (ou table configurée) et `queue_failed_jobs`.
     */
    public function up()
    {
        $this->create(config('queue.connections.database.table', 'queue_jobs'), function(Structure $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');

            return $table;
        });

        $this->create(config('queue.failed.table', 'queue_failed_jobs'), function(Structure $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            return $table;
        });
    }

    /**
     * Supprime les tables de file et de jobs échoués.
     */
    public function down()
    {
        $this->dropIfExists(config('queue.connections.database.table', 'queue_jobs'));
        $this->dropIfExists(config('queue.failed.table', 'queue_failed_jobs'));
    }
}
