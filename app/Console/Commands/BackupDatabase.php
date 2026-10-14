<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Dumps the database to a gzip file on the backup disk and removes copies older than --keep days. */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : days of backups to retain}';
    protected $description = 'Back up the database (mysqldump / pg_dump) and prune old backups';

    public function handle(): int
    {
        $conn = config('database.default');
        $c = config("database.connections.$conn");
        $disk = Storage::disk(config('backup.disk', 'local'));
        $name = sprintf('backups/%s-%s.sql.gz', $conn, now()->format('Ymd-His'));

        $cmd = match ($c['driver']) {
            'mysql', 'mariadb' => ['mysqldump', '--single-transaction', '--quick', '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $c['database']],
            'pgsql' => ['pg_dump', '--no-owner', '-h', $c['host'], '-p', (string) $c['port'], '-U', $c['username'], $c['database']],
            default => null,
        };
        if (! $cmd) {
            $this->error("Backups are not supported for the {$c['driver']} driver.");

            return self::FAILURE;
        }

        $env = ($c['driver'] === 'pgsql') ? ['PGPASSWORD' => $c['password']] : ['MYSQL_PWD' => $c['password']]; // never on the command line
        $process = new Process($cmd, null, $env, null, 600);
        $gz = gzopen($tmp = tempnam(sys_get_temp_dir(), 'bk'), 'wb6');
        $process->run(function ($type, $buffer) use ($gz) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
            }
        });
        gzclose($gz);

        if (! $process->isSuccessful() || filesize($tmp) < 100) {
            @unlink($tmp);
            $this->error('Backup failed: '.trim(substr($process->getErrorOutput(), 0, 300)));

            return self::FAILURE;
        }
        $disk->put($name, fopen($tmp, 'rb'));
        @unlink($tmp);

        $cutoff = now()->subDays((int) $this->option('keep'))->getTimestamp();
        $pruned = 0;
        foreach ($disk->files('backups') as $f) {
            if ($disk->lastModified($f) < $cutoff) {
                $disk->delete($f);
                $pruned++;
            }
        }
        $this->info("Backup written to $name ($pruned old removed).");

        return self::SUCCESS;
    }
}
