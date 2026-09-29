<?php

app()->get('/login', 'AuthController@showLogin');
app()->post('/login', 'AuthController@login');
app()->post('/setup', 'AuthController@setup');
app()->post('/key/replace', 'AuthController@replaceKey');
app()->post('/logout', 'AuthController@logout');

app()->get('/register', 'RegisterController@show');
app()->post('/register', 'RegisterController@store');
