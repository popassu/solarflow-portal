<?php
// /portal → staff portal (fallback when .htaccess rewrites are off)
header('Location: ../admin/', true, 301);
exit;
