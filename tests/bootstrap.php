<?php

require __DIR__ . '/../vendor/autoload.php';

/*
 * The package's controller extends App\Http\Controllers\Controller. Every real Laravel app
 * has that class, but the fake app Testbench boots for us does not, so we provide it here
 * the way a host application would.
 */
if (! class_exists('App\Http\Controllers\Controller')) {
    class_alias(\Illuminate\Routing\Controller::class, 'App\Http\Controllers\Controller');
}