<?php
$login_alert = $login_alert ?? null;
$exec_duty_announcement = (string) ($exec_duty_announcement ?? '');
$announcement_items = (array) ($announcement_items ?? []);
$room_booking_events = (array) ($room_booking_events ?? []);
$calendar_events = (array) ($calendar_events ?? []);
$dh_year_value = (int) ($dh_year_value ?? 0);
$dh_version_value = trim((string) ($dh_version_value ?? '1.0.0'));

if ($dh_year_value <= 0) {
    $dh_year_value = (int) date('Y') + 543;
}

if ($dh_version_value === '') {
    $dh_version_value = '1.0.0';
}
?>

<style>
    .modal-title,
    .close-modal-btn {
        color:var(--color-secondary) !important
    }

    .modal-header {
        margin: 0 40px;
    }
</style>

<!DOCTYPE html>
<html lang="th">
<?php require_once __DIR__ . '/../../../public/components/x-head.php'; ?>

<body>
    <?php require_once __DIR__ . '/../../../public/components/layout/preloader.php'; ?>
    <?php if (!empty($login_alert)) : ?>
        <?php $alert = $login_alert; ?>
        <?php require __DIR__ . '/../../../public/components/x-alert.php'; ?>
    <?php endif; ?>

    <div class="container-login-page">

        <div class="container-login-section">

            <header class="header-login">
                <div class="logo-login">
                    <img src="assets/img/DBsarabun-logo1.png" alt="DB-logo">
                </div>
                <div class="text-header-login">
                    <h3>ระบบงานสารบรรณออนไลน์</h3>
                    <h3>โรงเรียนดีบุกพังงาวิทยายน</h3>
                </div>
            </header>

            <section class="form-login">
                <form action="<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'index.php', ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="application/x-www-form-urlencoded">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="input-id-login-group">
                        <label for="pID">เลขบัตรประชาชน</label>
                        <input type="text" name="pID" id="pID" placeholder="เลขบัตรประชาชน" inputmode="numeric" maxlength="13" pattern="\d{13}" required>
                    </div>

                    <div class="input-password-login-group">
                        <label for="password">รหัสผ่าน</label>
                        <input type="password" name="password" id="password-toggle" placeholder="รหัสผ่าน" required>
                    </div>

                    <label class="remember-me-group">
                        <input type="checkbox" name="remember-me" id="remember">
                        <span class="checkmark"></span>
                        <p>จดจำฉัน</p>
                    </label>

                    <div class="button-login-group">
                        <button type="submit" name="submit">เข้าสู่ระบบ</button>
                    </div>
                </form>
            </section>

            <footer class="footer-login">
                <p>ระบบงานสารบรรณออนไลน์ โรงเรียนดีบุกพังงาวิทยายน</p>
                <p>DB SARABUN <?= htmlspecialchars($dh_version_value, ENT_QUOTES, 'UTF-8') ?> Copyright © 2026 TPH. All rights reserved</p>
                <p>Paperless office พ.ศ.<?= htmlspecialchars((string) $dh_year_value, ENT_QUOTES, 'UTF-8') ?></p>
            </footer>

            <div class="slide-down">
                <span>เลื่อนลงเพื่อดูข่าวประชาสัมพันธ์</span>
                <i class="fa-solid fa-angle-down"></i>
            </div>

        </div>

        <aside class="container-notification-section" id="notificationSection">

            <header class="announcement-bar">
                <img src="public/assets/img/icon/demostration.png" alt="">
                <p><?= htmlspecialchars($exec_duty_announcement !== '' ? $exec_duty_announcement : 'วันนี้ยังไม่มีข้อมูลการปฏิบัติราชการ', ENT_QUOTES, 'UTF-8') ?></p>
                <div class="close-news-section">
                    <i class="fa-solid fa-xmark" id="closeNewsBtn"></i>
                </div>
            </header>

            <div class="news-bar">
                <div class="header-news-bar">
                    <div>
                        <img src="public/assets/img/icon/news-paper.png" alt="">
                        <p>ข่าวประชาสัมพันธ์</p>
                    </div>
                    <a href="#">ดูข่าวทั้งหมด</a>
                </div>

                <div class="details-news-bar">
                    <ul>
                        <?php if (empty($announcement_items)) : ?>
                            <li>
                                <p>ยังไม่มีข่าวประชาสัมพันธ์</p>
                            </li>
                        <?php else : ?>
                            <?php foreach ($announcement_items as $announcement) : ?>
                                <li>
                                    <p><?= htmlspecialchars((string) ($announcement['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="container-calendar">
                <div class="calendar">
                    <div class="header-calendar">
                        <div class="month-year" id="month-year"></div>
                        <div class="interact-button-calendar">
                            <button id="prev-btn">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                            <button id="next-btn">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>

                    <div class="days-calendar">
                        <div class="day">อา</div>
                        <div class="day">จ</div>
                        <div class="day">อ</div>
                        <div class="day">พ</div>
                        <div class="day">พฤ</div>
                        <div class="day">ศ</div>
                        <div class="day">ส</div>
                    </div>

                    <div class="dates-calendar" id="dates-calendar"></div>

                </div>
            </div>

        </aside>

    </div>

    <div id="event-modal-overlay" class="modal-overlay hidden">
        <div class="modal-content">
            <header class="modal-header">
                <div class="modal-title">
                    <!-- <i class="fa-regular fa-calendar-days"></i> -->
                    <span id="modal-date-title">วันที่ ...</span>
                </div>
                <div class="close-modal-btn">
                    <i class="fa-solid fa-xmark" id="close-modal-btn"></i>
                </div>
            </header>

            <div class="modal-body">
                <div id="room-booking-section" class="booking-section">
                    <h4 class="section-title">ตารางการจองห้องประชุม</h4>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>ห้อง</th>
                                    <th>เวลา</th>
                                    <th>รายการประชุม</th>
                                    <th>จำนวน</th>
                                    <th>ผู้จองห้อง</th>
                                </tr>
                            </thead>
                            <tbody id="room-table-body">
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="car-booking-section" class="booking-section">
                    <h4 class="section-title">ตารางการจองรถยนต์</h4>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>ทะเบียนรถ</th>
                                    <th>เวลา</th>
                                    <th>รายละเอียด</th>
                                    <th>ผู้จองรถ</th>
                                </tr>
                            </thead>
                            <tbody id="car-table-body">
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="no-event-message" class="hidden">
                    ไม่มีรายการจองในวันนี้
                </div>
            </div>
        </div>
    </div>

    <script>
        window.roomBookingEvents = <?= json_encode($room_booking_events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script>
        window.roomBookingEvents = <?= json_encode($calendar_events ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php require_once __DIR__ . '/../../../public/components/x-scripts.php'; ?>
</body>

</html>
