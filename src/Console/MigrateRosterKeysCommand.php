<?php

namespace Webpatser\ResonateRoster\Console;

use Illuminate\Console\Command;
use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\ResonateRoster\RosterConnection;
use Webpatser\ResonateRoster\RosterKeys;

/**
 * Moves pre-0.3.0 roster keys into the app-scoped key schema.
 *
 * Roster keys used to be "{prefix}:{channel}:{node}", with no application
 * segment. This command renames each of those to
 * "{prefix}:{appId}:{channel}:{node}" (preserving the TTL), so an operator can
 * close the dual-read window without waiting for the old keys to expire.
 *
 * A pre-0.3.0 key carries no application, so it can only be attributed on a
 * server where that attribution is unambiguous: pass --app, or run on a
 * single-app server. A server whose applications shared channel names has
 * merged, unattributable keys; use --prune there and let the live nodes
 * rewrite the truth on their next heartbeat.
 */
class MigrateRosterKeysCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'resonate-roster:migrate-keys
        {--app= : The application id to attribute the legacy keys to}
        {--prune : Delete the legacy keys instead of renaming them}
        {--dry-run : Report what would change without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate pre-0.3.0 roster keys into the app-scoped key schema';

    /**
     * Execute the console command.
     */
    public function handle(ApplicationProvider $applications): int
    {
        /** @var array<string, mixed> $config */
        $config = config('resonate-roster', []);

        $keys = RosterKeys::fromConfig($config);
        $client = new Client(RosterConnection::parameters($config));
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');

        $legacy = $this->legacyKeys($client, $keys);

        if ($legacy === []) {
            $this->components->info('No pre-0.3.0 roster keys found. The dual-read window can be closed.');

            return self::SUCCESS;
        }

        $appId = $prune ? null : $this->resolveAppId($applications);

        if (! $prune && $appId === null) {
            return self::FAILURE;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($legacy as $key) {
            $channel = $keys->legacyChannelFromKey($key);

            if ($channel === null) {
                continue;
            }

            if ($prune) {
                $dryRun || $client->del($key);
                $this->line(sprintf('  delete %s', $key));
                $migrated++;

                continue;
            }

            $target = $keys->hashKey((string) $appId, $channel, $keys->nodeFromKey($key));

            // RENAMENX, so a key the upgraded nodes are already writing is
            // never overwritten by the stale copy this is cleaning up.
            if (! $dryRun && (int) $client->renamenx($key, $target) === 0) {
                $this->components->warn(sprintf('%s already exists, leaving %s in place', $target, $key));
                $skipped++;

                continue;
            }

            $this->line(sprintf('  %s -> %s', $key, $target));
            $migrated++;
        }

        $this->components->info(sprintf(
            '%s %d key(s)%s.',
            $dryRun ? 'Would have migrated' : 'Migrated',
            $migrated,
            $skipped > 0 ? sprintf(', skipped %d', $skipped) : '',
        ));

        return self::SUCCESS;
    }

    /**
     * The application id to attribute the legacy keys to.
     */
    protected function resolveAppId(ApplicationProvider $applications): ?string
    {
        $option = $this->option('app');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $all = $applications->all();

        $application = $all->count() === 1 ? $all->first() : null;

        if ($application !== null) {
            return $application->id();
        }

        $this->components->error(
            'This server has several applications, so a pre-0.3.0 key cannot be attributed on its own. Re-run with --app=<id>, or with --prune to drop the keys and let the heartbeat rebuild them.',
        );

        return null;
    }

    /**
     * Every pre-0.3.0 roster key currently in Redis.
     *
     * @return list<string>
     */
    protected function legacyKeys(Client $client, RosterKeys $keys): array
    {
        $cursor = '0';
        $found = [];

        do {
            [$cursor, $batch] = $client->scan($cursor, ['MATCH' => $keys->legacyAllPattern(), 'COUNT' => 100]);

            foreach ($batch as $key) {
                if ($keys->legacyChannelFromKey($key) !== null) {
                    $found[] = $key;
                }
            }
        } while ((string) $cursor !== '0');

        return $found;
    }
}
