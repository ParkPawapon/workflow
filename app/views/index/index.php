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

<!DOCTYPE html>
<html lang="th">
<?php require_once __DIR__ . '/../../../public/components/x-head.php'; ?>

<style>
    .modal-title,
    .close-modal-btn {
        color: var(--color-secondary) !important
    }

    .modal-header {
        margin: 0 40px;
    }

    .file-banner {
        max-width: 400px;
        min-width: 400px;
    }

    .tox-tinymce {
        width: 100%;
    }

    .file-list {
        margin: 10px 0 40px;
    }

    @media screen and (max-width: 1024px) {
        .file-list {
            margin: 5px 0 20px;
        }
    }

    @media screen and (max-width: 768px) {
        .file-list {
            margin: 5px 0;
        }

        .file-banner {
            max-width: 250px;
            min-width: 250px;
        }
    }
</style>

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
                        <!-- <? //php if (empty($announcement_items)) : 
                                ?>
                            <li>
                                <p>ยังไม่มีข่าวประชาสัมพันธ์</p>
                            </li>
                        <? //php else : 
                        ?>
                            <? //php foreach ($announcement_items as $announcement) : 
                            ?>
                                <li>
                                    <p><?= htmlspecialchars((string) ($announcement['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                </li>
                            <? //php endforeach; 
                            ?>
                        <? //php endif; 
                        ?> -->

                        <li>
                            <p class="js-open-order-view-modal">ASD</p>
                        </li>

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

    <!-- <div class="content-circular-notice-index circular-track-modal-host">
        <div class="modal-overlay-circular-notice-index outside-person js-modal-overlay">
            <div class="modal-content">
                <div class="header-modal">
                    <div class="first-header">
                        <p id="modalOutgoingViewTitle">รายละเอียดประชาสัมพันธ์</p>
                    </div>
                    <div class="sec-header">
                        <i class="fa-solid fa-xmark js-modal-close-btn"></i>
                    </div>
                </div>

                <div class="content-modal">

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>เรื่อง</strong></p>
                            <textarea rows="4" disabled>Lorem ipsum dolor sit amet consectetur adipisicing elit. Molestias dolore, eligendi minus error numquam provident rerum dolorum voluptate est neque aliquid eos? Sapiente ullam aperiam facilis iure corrupti at est alias, nesciunt nostrum nisi commodi assumenda sit repudiandae quibusdam illo doloribus veniam doloremque laudantium esse asperiores. Veniam animi harum temporibus.</textarea>
                        </div>
                    </div>

                    <div class="file-section" id="sectionViewCover">
                        <p><strong>ไฟล์หนังสือนำ</strong></p>
                        <div class="file-list" id="containerViewCover" aria-live="polite">
                            <div class="file-banner">
                                <div class="file-info">
                                    <div class="file-icon"><i class="fa-solid fa-file-image" aria-hidden="true"></i></div>
                                    <div class="file-text">
                                        <span class="file-name">timeTable1-2.png</span>
                                        <span class="file-type">image/png</span>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="public/api/file-download.php?module=outgoing&amp;entity_id=2&amp;file_id=181" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="file-section" id="sectionViewAttachments">
                        <p><strong>ไฟล์เอกสารเพิ่มเติม</strong></p>
                        <div class="file-list" id="containerViewAttachments" aria-live="polite">
                            <div class="file-banner">
                                <div class="file-info">
                                    <div class="file-icon"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></div>
                                    <div class="file-text">
                                        <span class="file-name">Getting started with OneDrive.pdf</span>
                                        <span class="file-type">application/pdf</span>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="public/api/file-download.php?module=outgoing&amp;entity_id=2&amp;file_id=182" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>แนบลิ้งก์</strong></p>
                            <input type="url" id="" class="order-no-display" value="ASDASDASDASDASDSDASDASDASSDAS"
                                disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>ความคิดเห็นของผู้อำนวยการ</strong></p>
                            <textarea name="detail" id="memo_editor_compose"></textarea>
                        </div>
                    </div>


                </div>

            </div>
        </div>
    </div> -->

    <div class="content-circular-notice-index circular-track-modal-host">
        <div class="modal-overlay-circular-notice-index outside-person js-modal-overlay">
            <div class="modal-content">
                <div class="header-modal">
                    <div class="first-header">
                        <p id="modalOutgoingViewTitle">รายละเอียดประชาสัมพันธ์</p>
                    </div>
                    <div class="sec-header">
                        <i class="fa-solid fa-xmark js-modal-close-btn"></i>
                    </div>
                </div>

                <div class="content-modal">

                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>เรื่อง</strong></p>
                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Atque, officia quos? Fugit, magnam. Blanditiis quaerat recusandae eos expedita earum sint cupiditate minus consequuntur natus iure nobis praesentium odio, at fuga repudiandae porro est quas accusantium non doloribus magnam. Quis repellat aut distinctio blanditiis, vel praesentium rem est in ipsam reiciendis.</p>
                        </div>
                    </div>

                    <div class="file-section" id="sectionViewCover">
                        <p><strong>ไฟล์หนังสือนำ</strong></p>
                        <div class="file-list" id="containerViewCover" aria-live="polite">
                            <div class="file-banner">
                                <div class="file-info">
                                    <div class="file-icon"><i class="fa-solid fa-file-image" aria-hidden="true"></i></div>
                                    <div class="file-text">
                                        <span class="file-name">timeTable1-2.png</span>
                                        <span class="file-type">image/png</span>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="public/api/file-download.php?module=outgoing&amp;entity_id=2&amp;file_id=181" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="file-section" id="sectionViewAttachments">
                        <p><strong>ไฟล์เอกสารเพิ่มเติม</strong></p>
                        <div class="file-list" id="containerViewAttachments" aria-live="polite">
                            <div class="file-banner">
                                <div class="file-info">
                                    <div class="file-icon"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></div>
                                    <div class="file-text">
                                        <span class="file-name">Getting started with OneDrive.pdf</span>
                                        <span class="file-type">application/pdf</span>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="public/api/file-download.php?module=outgoing&amp;entity_id=2&amp;file_id=182" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="content-topic-sec">
                        <div class="more-details column-format">
                            <p><strong>แนบลิ้งก์</strong></p>
                            <a href="https://www.youtube.com/watch?v=D9xB_SNQSzA&t=3391s" target="_blank">https://www.youtube.com/watch?v=D9xB_SNQSzA&t=3391s</a>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details column-format">
                            <p><strong>ความคิดเห็นของผู้อำนวยการ</strong></p>
                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Aperiam quibusdam optio beatae minima incidunt laborum recusandae eaque. Molestias ab tenetur iste eveniet neque ducimus et a natus blanditiis aliquam mollitia ipsum iusto perferendis quae eaque, aliquid error fuga veniam laudantium placeat? Repudiandae odio mollitia nostrum quidem nihil officiis quas adipisci?</p>
                        </div>
                    </div>


                </div>

                <!-- <div class="footer-modal"> -->
                <!-- <button type="button" id="modalOrderViewCloseBtn">
                    <p>ปิดหน้าต่าง</p>
                </button> -->
                <!-- </div> -->

            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>

    <script>
        if (window.tinymce && typeof window.tinymce.init === 'function') {
            tinymce.init({
                selector: '#memo_editor_compose',
                height: 500,
                menubar: false,
                language: 'th_TH',
                plugins: 'searchreplace autolink directionality visualblocks visualchars image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists wordcount help charmap emoticons',
                toolbar: 'undo redo | fontfamily | fontsize | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | outdent indent |  numlist bullist | forecolor backcolor removeformat | pagebreak | charmap emoticons',
                font_family_formats: 'TH Sarabun New=Sarabun, sans-serif;',
                font_size_formats: '8pt 9pt 10pt 12pt 14pt 16pt 18pt 20pt 22pt 24pt 26pt 36pt 48pt 72pt',
                content_style: `
            @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap');
            body {
                font-family: 'Sarabun', sans-serif;
                font-size: 16pt;
                line-height: 1.5;
                color: #000;
                background-color: #fff;
                padding: 0 20px;
                margin: 0 auto;
            }
            p {
                margin-bottom: 0px;
            }
        `,
                nonbreaking_force_tab: true,
                promotion: false,
                branding: false
            });
        }
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('click', (event) => {
                const viewButton = event.target.closest('.js-open-order-view-modal');
                if (viewButton) {
                    event.preventDefault();
                    const modal = document.querySelector('.js-modal-overlay');
                    if (modal) {
                        modal.style.display = 'flex';
                    }
                }

                const closeBtn = event.target.closest('.js-modal-close-btn');
                if (closeBtn) {
                    const modal = closeBtn.closest('.js-modal-overlay');
                    if (modal) {
                        modal.style.display = 'none';
                    }
                }

                if (event.target.classList.contains('js-modal-overlay')) {
                    event.target.style.display = 'none';
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    const openModals = document.querySelectorAll('.js-modal-overlay[style*="display: flex"]');
                    openModals.forEach(modal => {
                        modal.style.display = 'none';
                    });
                }
            });
        });
    </script>

    <script>
        window.roomBookingEvents = <?= json_encode($room_booking_events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script>
        window.roomBookingEvents = <?= json_encode($calendar_events ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php require_once __DIR__ . '/../../../public/components/x-scripts.php'; ?>
</body>

</html>