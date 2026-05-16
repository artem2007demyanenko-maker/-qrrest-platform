<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo "Gone\n";
