<?php

return [
    /*
     * The table name to use for storing retryable requests.
     */
    'table_name' => 'request_retries',

    /*
     * The database connection to use for storing retryable requests.
     */
    'connection' => null,

    /*
     * The storage disk to use for temporary file storage during retries.
     */
    'storage_disk' => 'local',

    /*
     * The default maximum number of retries for a request.
     */
    'max_retries' => 3,

    /*
     * The default delay between retries in seconds.
     */
    'delay' => 0,
];
