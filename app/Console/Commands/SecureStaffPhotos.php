<?php

namespace App\Console\Commands;

use App\Models\Staff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SecureStaffPhotos extends Command
{
    protected $signature = 'staff:secure-photos {--dry-run : Report changes without moving files}';

    protected $description = 'Move legacy public staff photos into protected private storage';

    public function handle(): int
    {
        $private = Storage::disk('local');
        $public = Storage::disk('public');
        $moved = 0;
        $alreadyPrivate = 0;
        $missing = 0;
        $failed = false;

        Staff::query()
            ->whereNotNull('photo_path')
            ->select(['id', 'photo_path'])
            ->chunkById(200, function ($staffMembers) use (
                $private,
                $public,
                &$moved,
                &$alreadyPrivate,
                &$missing,
                &$failed
            ): void {
                foreach ($staffMembers as $staff) {
                    $path = (string) $staff->photo_path;
                    if (! $this->isSafePath($path)) {
                        $this->error("Staff {$staff->id} has an unsafe photo path; it was not accessed.");
                        $failed = true;

                        continue;
                    }

                    if ($private->exists($path)) {
                        $alreadyPrivate++;
                        if (! $this->option('dry-run')) {
                            $public->delete($path);
                        }

                        continue;
                    }

                    if (! $public->exists($path)) {
                        $this->warn("Staff {$staff->id} references a missing photo.");
                        $missing++;

                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $moved++;

                        continue;
                    }

                    $stream = $public->readStream($path);
                    if (! is_resource($stream) || ! $private->writeStream($path, $stream)) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        $this->error("Staff {$staff->id} photo could not be copied.");
                        $failed = true;

                        continue;
                    }
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if (! $private->exists($path)) {
                        $this->error("Staff {$staff->id} photo copy could not be verified.");
                        $failed = true;

                        continue;
                    }

                    $public->delete($path);
                    $moved++;
                }
            });

        $action = $this->option('dry-run') ? 'would be moved' : 'moved';
        $this->info("{$moved} photo(s) {$action}; {$alreadyPrivate} already private; {$missing} missing.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function isSafePath(string $path): bool
    {
        return str_starts_with($path, 'staff-photos/')
            && ! str_contains($path, '..')
            && preg_match('#^staff-photos/[A-Za-z0-9/_-]+\.(?:jpe?g|png|webp)$#i', $path) === 1;
    }
}
