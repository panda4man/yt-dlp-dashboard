<?php

namespace App\Jobs;

use App\Models\Download;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDownload implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Download $download)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
    }
}
