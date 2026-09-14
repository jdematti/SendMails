<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

Auth::logout();
session_start();
flash('success', 'Sesion cerrada correctamente.');
redirect('login.php');
