<?php

return [
    'uri' => getenv('MONGODB_URI') ?:'mongodb+srv://solracsajor22_db_user:YhXxAxUbIHckjWFz@iotweatherstation.65uimgh.mongodb.net/',
    'database' => 'IoTWheatherStation',
    'collection' => 'IoTWheatherStation',
    'apiToken' => getenv('TOKEN') ?: 'Aa1234567890BbCcDdEeFfGgHhIiJjK2',
];
