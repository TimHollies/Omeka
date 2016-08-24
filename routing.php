<?php

putenv("APPLICATION_ENV=development");

// www/routing.php
if (preg_match('/\.(?:png|jpg|jpeg|gif|css|ico|js|woff|ttf)$/', $_SERVER["REQUEST_URI"])) {
    return false;
} else {
	if(preg_match('/^\/install\/.*$/', $_SERVER["REQUEST_URI"])) {
		include __DIR__ . '/install/install.php';
	} else {
		if(preg_match('/^\/install\/.*$/', $_SERVER["REQUEST_URI"])) {
			include __DIR__ . '/admin/admin.php';
		} else {
			include __DIR__ . '/index.php';
		}
	}
}
