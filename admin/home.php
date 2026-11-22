<?php
/* Sends each staff member to the first section their account can open. */
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
redirect(user_home());
