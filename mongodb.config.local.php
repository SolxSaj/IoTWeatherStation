<?php

return [
    'uri' => getenv('MONGODB_URI') ?:'',
    'database' => 'IoTWheatherStation',
    'collection' => 'IoTWheatherStation',
    'apiToken' => getenv('TOKEN') ?: '',
];
