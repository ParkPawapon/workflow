<?php
require_once __DIR__ . '/src/Services/auth/auth-guard.php';
?>
<!DOCTYPE html>
<html lang="th">
<?php require_once __DIR__ . '/public/components/x-head.php'; ?>

<body>
    <?php require_once __DIR__ . '/public/components/layout/preloader.php'; ?>
    <?php require_once __DIR__ . '/public/components/partials/x-sidebar.php'; ?>

    <section class="main-section">
        <?php require_once __DIR__ . '/public/components/partials/x-navigation.php'; ?>
        <main class="content-wrapper">
            <div class="content-header">
                <h1>กำลังพัฒนา</h1>
                <p>โมดูลนี้จะเปิดให้ใช้งานเร็ว ๆ นี้</p>
            </div>
            <section class="enterprise-card">
                <div class="enterprise-card-header">
                    <div class="enterprise-card-title-group">
                        <h2 class="enterprise-card-title">ระบบการลา</h2>
                        <p class="enterprise-card-subtitle">อยู่ระหว่างพัฒนา</p>
                    </div>
                </div>
                <p>ระบบการลาอยู่ระหว่างพัฒนาเพื่อรองรับการยื่นและอนุมัติใบลา</p>
            </section>
        </main>
        <?php require_once __DIR__ . '/public/components/partials/x-footer.php'; ?>
    </section>

    <?php require_once __DIR__ . '/public/components/x-scripts.php'; ?>
</body>

</html>