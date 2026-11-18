<?php

namespace Webpatser\ResonateRoster\Tests\Support;

use Predis\Command\CommandInterface;
use Predis\Command\RawCommand;
use Predis\Connection\StreamConnection;

/**
 * A real predis stream connection that records the id of every command it
 * writes, so a test can assert how many Redis commands a read sent (and that
 * none of them was a SCAN) against a live server.
 */
class CountingConnection extends StreamConnection
{
    /**
     * The command ids written since the last reset, in order.
     *
     * @var list<string>
     */
    public array $commands = [];

    /**
     * How many times the socket was opened and closed.
     */
    public int $connects = 0;

    public int $disconnects = 0;

    /**
     * When true, every EVAL is sent to the server as a command it does not
     * know, so the real server answers it with an error reply, the way a
     * read-only replica or an ACL user without scripting refuses a script.
     */
    public bool $rejectScripts = false;

    /**
     * Record the command, then write it to the socket as usual.
     */
    public function writeRequest(CommandInterface $command)
    {
        $id = strtoupper($command->getId());

        $this->commands[] = $id;

        if ($this->rejectScripts && $id === 'EVAL') {
            $command = new RawCommand('ROSTER-TEST-SCRIPT-REFUSED', []);
        }

        parent::writeRequest($command);
    }

    /**
     * Count the socket being opened.
     */
    public function connect()
    {
        if (! $this->isConnected()) {
            $this->connects++;
        }

        parent::connect();
    }

    /**
     * Count the socket being closed.
     */
    public function disconnect()
    {
        if ($this->isConnected()) {
            $this->disconnects++;
        }

        parent::disconnect();
    }

    /**
     * Forget every command recorded so far.
     */
    public function reset(): void
    {
        $this->commands = [];
    }

    /**
     * How many times a command was written since the last reset.
     */
    public function count(string $id): int
    {
        return count(array_keys($this->commands, strtoupper($id), true));
    }
}
