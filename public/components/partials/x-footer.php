<?php
require_once __DIR__ . '/../../../app/modules/system/system.php';

$dh_footer_version = system_get_dh_version();
?>
<footer class="footer">
    <p>ระบบงานสารบรรณออนไลน์ โรงเรียนดีบุกพังงาวิทยายน DB SARABUN <?= htmlspecialchars($dh_footer_version, ENT_QUOTES, 'UTF-8') ?> Copyright © 2026 TPH. All rights reserved</p>
</footer>
