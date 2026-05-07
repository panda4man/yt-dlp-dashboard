<?php

return [
    'rsync_host'     => env('RSYNC_HOST', ''),
    'rsync_user'     => env('RSYNC_USER', ''),
    'rsync_dest'     => env('RSYNC_DEST_PATH', ''),
    'rsync_key_path' => env('RSYNC_SSH_KEY_PATH', ''),
];
