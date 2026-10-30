<?php

// Override STEVIACHAT_API_URL when the FastAPI service runs on another host.
return [
    'url' => getenv('STEVIACHAT_API_URL') ?: 'http://127.0.0.1:8000/api/chat',
    'connect_timeout' => 5,   // seconds to wait for a connection
    'timeout' => 20,          // seconds to wait for the model's answer
];