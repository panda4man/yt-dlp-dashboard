<?php

namespace App\Models;

use App\Enums\DownloadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_url',
        'title',
        'channel',
        'duration_seconds',
        'thumbnail_url',
        'youtube_video_id',
        'uploaded_at',
        'description',
        'status',
        'file_path',
        'thumbnail_path',
        'file_size_bytes',
        'download_speed_bps',
        'started_at',
        'completed_at',
        'exported_at',
        'error_message',
    ];

    protected $casts = [
        'status'       => DownloadStatus::class,
        'uploaded_at'  => 'date',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'exported_at'  => 'datetime',
    ];
}
