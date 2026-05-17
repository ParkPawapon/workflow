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

$index_plain_text = static function ($value): string {
    $text = (string) ($value ?? '');

    if ($text === '') {
        return '';
    }

    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
    $text = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

    return trim($text);
};

$index_announcement_payloads = [];

foreach ($announcement_items as $announcement) {
    $announcement_id = (int) ($announcement['announcementID'] ?? 0);
    $circular_id = (int) ($announcement['circularID'] ?? 0);
    $payload_key = $announcement_id > 0 ? (string) $announcement_id : 'circular-' . (string) $circular_id;
    $files = [];

    foreach ((array) ($announcement['files'] ?? []) as $file) {
        $file_id = (int) ($file['fileID'] ?? 0);

        if ($file_id <= 0 || $circular_id <= 0) {
            continue;
        }

        $files[] = [
            'fileID' => $file_id,
            'fileName' => trim((string) ($file['fileName'] ?? '')),
            'mimeType' => trim((string) ($file['mimeType'] ?? '')),
            'fileNote' => trim((string) ($file['fileNote'] ?? $file['note'] ?? '')),
            'url' => 'public/api/file-download.php?module=circulars&entity_id=' . rawurlencode((string) $circular_id) . '&file_id=' . rawurlencode((string) $file_id),
        ];
    }

    $subject = trim((string) ($announcement['subject'] ?? ''));
    $announcement_position = trim((string) ($announcement['announcementByPositionName'] ?? ''));
    $announcement_comment_label = str_contains($announcement_position, 'รองผู้อำนวยการ')
        ? 'ความคิดเห็นของรองผู้อำนวยการ'
        : 'ความคิดเห็นของผู้ส่งขึ้นข่าวประชาสัมพันธ์';

    $index_announcement_payloads[$payload_key] = [
        'announcementID' => $announcement_id,
        'circularID' => $circular_id,
        'subject' => $subject !== '' ? $subject : 'ข่าวประชาสัมพันธ์',
        'detailText' => $index_plain_text($announcement['detail'] ?? ''),
        'linkURL' => trim((string) ($announcement['linkURL'] ?? '')),
        'announcementCommentText' => $index_plain_text($announcement['announcementComment'] ?? ''),
        'announcementCommentLabel' => $announcement_comment_label,
        'directorCommentText' => $index_plain_text($announcement['announcementComment'] ?? ''),
        'downloadAllURL' => $circular_id > 0 ? 'public/api/file-download.php?module=circulars&entity_id=' . rawurlencode((string) $circular_id) . '&all=1' : '',
        'files' => $files,
    ];
}

$index_announcement_payload_json = json_encode($index_announcement_payloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

if ($index_announcement_payload_json === false) {
    $index_announcement_payload_json = '{}';
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

    .teacher-phone-footer-control {
        padding: 0px 40px 20px;
    }

    .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person {
        padding: 20px;
    }

    .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content {
        width: 90%;
        max-width: 90%;
        height: 90%;
    }

    #newsModalOverlay {
        z-index: 1111;
    }

    #newsModalOverlay .modal-body {
        padding: 0 40px;
    }

    #newsModalOverlay ul {
        padding: 0;
    }

    #newsModalOverlay ul li {
        font-size: var(--font-size-body-1);
        color: var(--color-secondary);
        text-decoration: none;
        list-style: none;
        padding: 10px 0;
        margin: 0;
        width: 100%;
        cursor: pointer;
        /* border-bottom: 1px solid var(--color-secondary); */
    }

    #newsModalOverlay ul li:nth-child(even) {
        background-color: rgba(var(--rgb-secondary-light), 0.05);
    }

    .teacher-phone-footer-control {
        height: 80px;
        padding: 20px 40px;
        box-shadow: 0px -12px 12px -2px rgba(var(--rgb-neutral-dark), 0.05)
    }

    @media screen and (max-width: 1024px) {
        .file-list {
            margin: 5px 0 20px;
        }

        .teacher-phone-footer-control {
            padding: 0px 20px 10px;
        }

        #newsModalOverlay .modal-body {
            padding: 0 20px;
        }

        #newsModalOverlay ul {
            margin: 0;
        }

        #newsModalOverlay modal-body {
            padding: 20px 10px;
        }

        #newsModalOverlay ul li {
            font-size: var(--font-size-desc-1);
            padding: 5px 0;
            margin: 0;
        }

        .teacher-phone-footer-control {
            height: 60px;
            padding: 0 20px;
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

        .teacher-phone-footer-control {
            padding: 0px 10px 10px;
        }

        #newsModalOverlay .modal-body {
            padding: 0 10px;
        }

        #newsModalOverlay ul li {
            font-size: var(--font-size-desc-3);
            padding: 0;
            margin: 0;
        }

        .teacher-phone-footer-control {
            height: 40px;
            padding: 0 10px;
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
                        <input type="text" name="pID" id="pID" placeholder="เลขบัตรประชาชน" required>
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
                    <a href="#" id="newsButton">ดูข่าวทั้งหมด</a>
                </div>

                <div class="details-news-bar">
                    <ul>
                        <?php if (empty($announcement_items)) : ?>
                            <li>
                                <p>ยังไม่มีข่าวประชาสัมพันธ์</p>
                            </li>
                        <?php else : ?>
                            <?php foreach ($announcement_items as $announcement) : ?>
                                <?php
                                $announcement_id = (int) ($announcement['announcementID'] ?? 0);
                                $circular_id = (int) ($announcement['circularID'] ?? 0);
                                $payload_key = $announcement_id > 0 ? (string) $announcement_id : 'circular-' . (string) $circular_id;
                                $announcement_title = trim((string) ($announcement['subject'] ?? ''));

                                if ($announcement_title === '') {
                                    $announcement_title = 'ข่าวประชาสัมพันธ์';
                                }
                                ?>
                                <li>
                                    <p class="js-open-order-view-modal" role="button" tabindex="0" data-announcement-id="<?= h($payload_key) ?>"><?= h($announcement_title) ?></p>
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

    <div id="newsModalOverlay" class="modal-overlay hidden">
        <div class="modal-content">
            <header class="modal-header">
                <div class="modal-title">
                    <!-- <i class="fa-regular fa-calendar-days"></i> -->
                    <span>ข่าวประชาสัมพันธ์ทั้งหมด</span>
                </div>
                <div class="close-modal-btn">
                    <i class="fa-solid fa-xmark" id="newsCloseBtn" aria-hidden="true"></i>
                </div>
            </header>

            <div class="modal-body">
                <div class="booking-section">
                    <ul>
                        <?php for ($x = 1; $x <= 220; $x++) { ?>
                            <li class="js-open-order-view-modal">Lorem ipsum dolor sit amet consectetur adipisicing elit. Velit, suscipit <?= $x ?></li>
                        <?php } ?>
                    </ul>
                </div>


            </div>

            <div class="teacher-phone-footer-control">
                <div class="count-text" id="count-text">
                    <p>จำนวน <? //= h((string) number_format($teacher_directory_total)) 
                                ?> รายชื่อ</p>
                </div>
                <div class="teacher-phone-pagination" id="pagination">

                    <button type="button" data-page="<? //= h((string) $prev_page) 
                                                        ?>" <? //= $current_page <= 1 ? 'disabled' : '' 
                                                            ?> aria-label="Previous page">
                        <i class="fas fa-chevron-left" aria-hidden="true"></i>
                    </button>

                    <button type="button" data-page="1" <? //= $current_page === 1 ? 'class="active"' : '' 
                                                        ?>>1</button>
                    <span class="enterprise-ellipsis">...</span>

                    <button type="button" data-page="<? //= h((string) $i) 
                                                        ?>" <? //= $i === $current_page ? 'class="active"' : '' 
                                                            ?>><? //= h((string) $i) 
                                                                ?></button>


                    <span class="enterprise-ellipsis">...</span>

                    <button type="button" data-page="<? //= h((string) $total_pages) 
                                                        ?>" <? //= $current_page === $total_pages ? 'class="active"' : '' 
                                                            ?>><? //= h((string) $total_pages) 
                                                                ?></button>

                    <button type="button" data-page="<? //= h((string) $next_page) 
                                                        ?>" <? //= $current_page >= $total_pages ? 'disabled' : '' 
                                                            ?> aria-label="Next page">
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>

                </div>
            </div>

        </div>
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
                        <div class="more-details row-format">
                            <p><strong>เรื่อง</strong></p>
                            <p id="indexAnnouncementViewSubject">-</p>
                        </div>
                    </div>

                    <div class="file-section" id="sectionViewCover">
                        <p><strong>ไฟล์หนังสือนำ</strong></p>
                        <div class="file-list" id="indexAnnouncementViewCover" aria-live="polite">
                            <p>-</p>
                        </div>
                    </div>

                    <div class="file-section" id="sectionViewAttachments">
                        <p><strong>ไฟล์เอกสารเพิ่มเติม</strong></p>
                        <div class="file-list" id="indexAnnouncementViewAttachments" aria-live="polite">
                            <p>-</p>
                        </div>
                    </div>


                    <div class="content-topic-sec">
                        <div class="more-details column-format" id="indexAnnouncementViewLink">
                            <p><strong>แนบลิ้งก์</strong></p>
                            <span>-</span>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details column-format">
                            <p><strong id="indexAnnouncementCommentLabel">ความคิดเห็นของรองผู้อำนวยการ</strong></p>
                            <p id="indexAnnouncementDirectorComment">-</p>
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

                <div class="formal-form">

                    <div class="header">
                        <p><span>เรื่อง: </span><span id="indexAnnouncementViewSubject">-</span></p>
                    </div>
                    <div class="formal-row">
                        <div class="group row" id="indexAnnouncementViewLink">
                            <label for="">แนบลิ้งก์</label>
                            <span>-</span>
                        </div>
                    </div>
                    <div class="formal-row">
                        <div class="group row">
                            <label for="" id="indexAnnouncementCommentLabel">ความคิดเห็นของรองผู้อำนวยการ</label>
                            <p id="indexAnnouncementDirectorComment">-</p>
                        </div>
                    </div>

                    <hr>

                    <section class="sharing-table">
                        <div class="header">
                            <p>ไฟล์เอกสารแนบจากระบบ</p>
                            <a href="#" id="indexAnnouncementDownloadAll">ดาวน์โหลดไฟล์ทั้งหมด</a>
                        </div>
                        <div class="table-responsive table-circular-notice-index">
                            <table class="custom-table booking-table">
                                <thead>
                                    <tr>
                                        <th>ชื่อไฟล์</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="indexAnnouncementViewFiles">
                                    <tr>
                                        <td colspan="2" class="enterprise-empty">-</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
    <script type="application/json" id="indexAnnouncementPayloads">
        <?= $index_announcement_payload_json ?>
    </script>

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
            const payloadElement = document.getElementById('indexAnnouncementPayloads');
            const announcementPayloads = (() => {
                try {
                    return JSON.parse(payloadElement?.textContent || '{}') || {};
                } catch (error) {
                    return {};
                }
            })();
            const modal = document.querySelector('.js-modal-overlay');
            const subjectElement = document.getElementById('indexAnnouncementViewSubject');
            const coverList = document.getElementById('indexAnnouncementViewCover');
            const attachmentList = document.getElementById('indexAnnouncementViewAttachments');
            const fileTableBody = document.getElementById('indexAnnouncementViewFiles');
            const downloadAllLink = document.getElementById('indexAnnouncementDownloadAll');
            const linkContainer = document.getElementById('indexAnnouncementViewLink');
            const directorCommentElement = document.getElementById('indexAnnouncementDirectorComment');
            const announcementCommentLabelElement = document.getElementById('indexAnnouncementCommentLabel');

            const escapeHtml = (value) => String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const renderPlainText = (value) => {
                const text = String(value || '').trim();
                return text !== '' ? escapeHtml(text).replace(/\n/g, '<br>') : '-';
            };

            const isCoverFile = (file) => {
                const note = String(file?.fileNote || file?.note || '').trim().toLowerCase();
                return ['cover_file', 'cover_attachments', 'cover', 'lead_file', 'หนังสือนำ'].includes(note);
            };

            const splitFiles = (files) => {
                const normalized = Array.isArray(files) ? files : [];
                const coverFiles = normalized.filter((file) => isCoverFile(file));
                const attachmentFiles = normalized.filter((file) => !isCoverFile(file));

                if (coverFiles.length === 0 && normalized.length > 0) {
                    return {
                        coverFiles: [normalized[0]],
                        attachmentFiles: normalized.slice(1),
                    };
                }

                return {
                    coverFiles,
                    attachmentFiles,
                };
            };

            const fileIconClass = (mimeType) => {
                const mime = String(mimeType || '').toLowerCase();

                if (mime.includes('pdf')) {
                    return 'fa-file-pdf';
                }

                if (mime.startsWith('image/')) {
                    return 'fa-file-image';
                }

                return 'fa-file-lines';
            };

            const fileNoteLabel = (file) => {
                return isCoverFile(file) ? 'ไฟล์หนังสือนำ' : 'ไฟล์เอกสารเพิ่มเติม';
            };

            const renderFiles = (container, files) => {
                if (!container) {
                    return;
                }

                const normalized = Array.isArray(files) ? files : [];

                if (normalized.length === 0) {
                    container.innerHTML = '<p>-</p>';
                    return;
                }

                container.innerHTML = normalized.map((file) => {
                    const fileName = String(file?.fileName || '').trim() || 'ไฟล์แนบ';
                    const mimeType = String(file?.mimeType || '').trim() || '-';
                    const url = String(file?.url || '').trim();
                    const actionHtml = url !== '' ?
                        `<div class="file-actions"><a href="${escapeHtml(url)}" target="_blank" rel="noopener"><i class="fa-solid fa-eye" aria-hidden="true"></i></a></div>` :
                        '';

                    return `
                        <div class="file-banner">
                            <div class="file-info">
                                <div class="file-icon"><i class="fa-solid ${fileIconClass(mimeType)}" aria-hidden="true"></i></div>
                                <div class="file-text">
                                    <span class="file-name">${escapeHtml(fileName)}</span>
                                    <span class="file-type">${escapeHtml(mimeType)}</span>
                                </div>
                            </div>
                            ${actionHtml}
                        </div>
                    `;
                }).join('');
            };

            const renderAnnouncementFiles = (container, files) => {
                if (!container) {
                    return;
                }

                const normalized = Array.isArray(files) ? files : [];

                if (normalized.length === 0) {
                    container.innerHTML = '<tr><td colspan="2" class="enterprise-empty">-</td></tr>';
                    return;
                }

                container.innerHTML = normalized.map((file) => {
                    const fileName = String(file?.fileName || '').trim() || 'ไฟล์แนบ';
                    const mimeType = String(file?.mimeType || '').trim();
                    const url = String(file?.url || '').trim();
                    const downloadUrl = url !== '' ? `${url}${url.includes('?') ? '&' : '?'}download=1` : '';
                    const actions = url !== '' ? `
                        <a class="booking-action-btn secondary" href="${escapeHtml(url)}" target="_blank" rel="noopener">
                            <i class="fa-solid fa-eye"></i>
                            <span class="tooltip">ดูไฟล์</span>
                        </a>
                        <a class="booking-action-btn secondary" href="${escapeHtml(downloadUrl)}">
                            <i class="fa-solid fa-download"></i>
                            <span class="tooltip">ดาวน์โหลด</span>
                        </a>
                    ` : '-';

                    return `
                        <tr>
                            <td>${escapeHtml(fileName)} (${escapeHtml(fileNoteLabel(file))})</td>
                            <td>${actions}</td>
                        </tr>
                    `;
                }).join('');
            };

            const renderDownloadAllLink = (url, files) => {
                if (!downloadAllLink) {
                    return;
                }

                const link = String(url || '').trim();
                const hasFiles = Array.isArray(files) && files.length > 0;

                if (link === '' || !hasFiles) {
                    downloadAllLink.style.display = 'none';
                    downloadAllLink.href = '#';
                    return;
                }

                downloadAllLink.style.display = '';
                downloadAllLink.href = link;
            };

            const renderLink = (url) => {
                if (!linkContainer) {
                    return;
                }

                const link = String(url || '').trim();
                const label = link !== '' ? escapeHtml(link) : '-';
                const linkMarkup = link !== '' ?
                    `<a href="${escapeHtml(link)}" target="_blank" rel="noopener">${label}</a>` :
                    '<span>-</span>';

                linkContainer.innerHTML = `<label for="">แนบลิ้งก์</label>${linkMarkup}`;
            };

            const openAnnouncementModal = (payloadKey) => {
                const payload = announcementPayloads[payloadKey] || {};
                const subject = String(payload.subject || '').trim() || 'ข่าวประชาสัมพันธ์';
                const {
                    coverFiles,
                    attachmentFiles,
                } = splitFiles(payload.files);

                if (subjectElement) {
                    subjectElement.innerHTML = renderPlainText(subject);
                }

                renderFiles(coverList, coverFiles);
                renderFiles(attachmentList, attachmentFiles);
                renderAnnouncementFiles(fileTableBody, Array.isArray(payload.files) ? payload.files : []);
                renderDownloadAllLink(payload.downloadAllURL, Array.isArray(payload.files) ? payload.files : []);
                renderLink(payload.linkURL);

                if (announcementCommentLabelElement) {
                    announcementCommentLabelElement.textContent = String(payload.announcementCommentLabel || '').trim() || 'ความคิดเห็นของรองผู้อำนวยการ';
                }

                if (directorCommentElement) {
                    directorCommentElement.innerHTML = renderPlainText(payload.announcementCommentText || payload.directorCommentText);
                }

                if (modal) {
                    modal.style.display = 'flex';
                }
            };

            document.addEventListener('click', (event) => {
                const viewButton = event.target.closest('.js-open-order-view-modal');
                if (viewButton) {
                    event.preventDefault();
                    openAnnouncementModal(viewButton.getAttribute('data-announcement-id') || '');
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

        document.addEventListener('DOMContentLoaded', function() {
            const slider = document.querySelector('.table-circular-notice-index');

            if (!slider) return;

            let isDown = false;
            let startX;
            let scrollLeft;

            slider.addEventListener('mousedown', (e) => {
                isDown = true;
                slider.classList.add('is-dragging');
                startX = e.pageX - slider.offsetLeft;
                scrollLeft = slider.scrollLeft;
            });

            slider.addEventListener('mouseleave', () => {
                isDown = false;
                slider.classList.remove('is-dragging');
            });

            slider.addEventListener('mouseup', () => {
                isDown = false;
                slider.classList.remove('is-dragging');
            });

            slider.addEventListener('mousemove', (e) => {
                if (!isDown) return;

                e.preventDefault();

                const x = e.pageX - slider.offsetLeft;
                const walk = (x - startX) * 1.5;

                slider.scrollLeft = scrollLeft - walk;
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            const modalOverlay = document.getElementById('newsModalOverlay');
            const openBtn = document.getElementById('newsButton');
            const closeBtn = document.getElementById('newsCloseBtn');

            const list = modalOverlay?.querySelector('.booking-section ul');
            const rows = list ? Array.from(list.querySelectorAll('li')) : [];

            const pagination = document.getElementById('pagination');
            const countText = document.getElementById('count-text');

            const rowsPerPage = 20;
            let currentPage = 1;
            const totalPages = Math.ceil(rows.length / rowsPerPage);

            const getPaginationRange = () => {
                let startPage = 1;
                let endPage = totalPages;

                if (totalPages > 7) {
                    if (currentPage <= 4) {
                        endPage = 5;
                    } else if (currentPage >= totalPages - 3) {
                        startPage = totalPages - 4;
                    } else {
                        startPage = currentPage - 2;
                        endPage = currentPage + 2;
                    }
                }

                return {
                    startPage,
                    endPage,
                };
            };

            const createPageButton = (page) => {
                const button = document.createElement('button');

                button.type = 'button';
                button.dataset.page = String(page);
                button.textContent = page;

                if (page === currentPage) {
                    button.classList.add('active');
                }

                button.addEventListener('click', () => {
                    currentPage = page;
                    renderPage();
                });

                return button;
            };

            const createEllipsis = () => {
                const span = document.createElement('span');

                span.className = 'enterprise-ellipsis';
                span.textContent = '...';

                return span;
            };

            const renderPagination = () => {
                if (!pagination) return;

                pagination.innerHTML = '';

                if (totalPages <= 1) {
                    return;
                }

                const prevPage = Math.max(1, currentPage - 1);
                const nextPage = Math.min(totalPages, currentPage + 1);

                const prevBtn = document.createElement('button');
                prevBtn.type = 'button';
                prevBtn.dataset.page = String(prevPage);
                prevBtn.setAttribute('aria-label', 'Previous page');
                prevBtn.innerHTML = '<i class="fas fa-chevron-left" aria-hidden="true"></i>';
                prevBtn.disabled = currentPage <= 1;

                prevBtn.addEventListener('click', () => {
                    if (currentPage <= 1) return;

                    currentPage = prevPage;
                    renderPage();
                });

                pagination.appendChild(prevBtn);

                const {
                    startPage,
                    endPage
                } = getPaginationRange();

                if (startPage > 1) {
                    pagination.appendChild(createPageButton(1));

                    if (startPage > 2) {
                        pagination.appendChild(createEllipsis());
                    }
                }

                for (let page = startPage; page <= endPage; page++) {
                    pagination.appendChild(createPageButton(page));
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        pagination.appendChild(createEllipsis());
                    }

                    pagination.appendChild(createPageButton(totalPages));
                }

                const nextBtn = document.createElement('button');
                nextBtn.type = 'button';
                nextBtn.dataset.page = String(nextPage);
                nextBtn.setAttribute('aria-label', 'Next page');
                nextBtn.innerHTML = '<i class="fas fa-chevron-right" aria-hidden="true"></i>';
                nextBtn.disabled = currentPage >= totalPages;

                nextBtn.addEventListener('click', () => {
                    if (currentPage >= totalPages) return;

                    currentPage = nextPage;
                    renderPage();
                });

                pagination.appendChild(nextBtn);
            };

            const renderPage = () => {
                const startIndex = (currentPage - 1) * rowsPerPage;
                const endIndex = startIndex + rowsPerPage;

                rows.forEach((row, index) => {
                    row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
                });

                if (countText) {
                    const startNumber = rows.length === 0 ? 0 : startIndex + 1;
                    const endNumber = Math.min(endIndex, rows.length);

                    countText.innerHTML = `
                <p>แสดง ${startNumber}-${endNumber} จากทั้งหมด ${rows.length} รายการ</p>
            `;
                }

                renderPagination();
            };

            const openModal = (event) => {
                event?.preventDefault();

                if (!modalOverlay) return;

                modalOverlay.classList.remove('hidden');
                modalOverlay.style.display = 'flex';

                currentPage = 1;
                renderPage();
            };

            const closeModal = () => {
                if (!modalOverlay) return;

                modalOverlay.classList.add('hidden');
                modalOverlay.style.display = 'none';
            };

            openBtn?.addEventListener('click', openModal);
            closeBtn?.addEventListener('click', closeModal);

            modalOverlay?.addEventListener('click', (event) => {
                if (event.target === modalOverlay) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (
                    event.key === 'Escape' &&
                    modalOverlay &&
                    !modalOverlay.classList.contains('hidden')
                ) {
                    closeModal();
                }
            });

            renderPage();
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