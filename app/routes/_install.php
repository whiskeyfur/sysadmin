<?php

// The install wizard: only reachable until a database is set up (see RequireDatabase).
app()->get('/install/database', 'InstallController@show');
app()->post('/install/database', 'InstallController@store');
