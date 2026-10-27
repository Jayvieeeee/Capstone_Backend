<?php

// Address of your teammate's FastAPI endpoint.
// Update the IP whenever their PC's address changes.
return [
    'url' => 'http://192.168.1.20:8000/api/chat',
    'connect_timeout' => 5,   // seconds to wait for a connection
    'timeout' => 20,          // seconds to wait for the model's answer
];