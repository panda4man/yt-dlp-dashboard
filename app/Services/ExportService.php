<?php

namespace App\Services;

use App\Models\Download;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ExportService
{
    public function export(Download $download): void
    {
        $host    = config('export.rsync_host');
        $user    = config('export.rsync_user');
        $dest    = config('export.rsync_dest');
        $keyPath = config('export.rsync_key_path');

        $localDir = storage_path('app/private/downloads/' . $download->id) . '/';

        $result = Process::run([
            'rsync', '-avz',
            '-e', "ssh -i {$keyPath} -o StrictHostKeyChecking=no",
            $localDir,
            "{$user}@{$host}:{$dest}",
        ]);

        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'rsync failed');
        }

        $download->update(['exported_at' => now()]);
    }
}
