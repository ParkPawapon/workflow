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
                            // $announcement_id = (int) ($announcement['announcementID'] ?? 0);
                            // $circular_id = (int) ($announcement['circularID'] ?? 0);
                            // $payload_key = $announcement_id > 0 ? (string) $announcement_id : 'circular-' . (string) $circular_id;
                            ?>
                                <li>
                                    <p class="js-open-order-view-modal" role="button" tabindex="0" data-announcement-id="<?= htmlspecialchars($payload_key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($announcement['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                </li>
                            <? //php endforeach; 
                            ?>
                        <? //php endif; 
                        ?> -->
                        <li>
                            <p class="js-open-order-view-modal">ข่าวประชาสัมพันธ์</p>
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
                        <p><span>เรื่อง: </span>กิจกรรมงานวันประวัติศาสตร์ 2570</p>
                    </div>
                    <div class="formal-row">
                        <div class="group row">
                            <label for="">แนบลิ้งก์</label>
                            <a href="https://www.youtube.com/" target="_blank">https://www.youtube.com/</a>
                        </div>
                    </div>
                    <div class="formal-row">
                        <div class="group row">
                            <label for="">ความคิดเห็นของรองผู้อำนวยการ</label>
                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Consectetur, quas distinctio rerum officiis assumenda architecto laudantium accusamus! Eos corrupti vero incidunt quasi porro nesciunt consequuntur voluptate cum! Mollitia dolorem explicabo, incidunt voluptates reprehenderit recusandae expedita vel nulla magni fugit, culpa laboriosam ducimus quos? Esse ex ut, unde autem voluptates pariatur?</p>
                        </div>
                    </div>

                    <hr>

                    <section class="sharing-table">
                        <div class="header">
                            <p>ไฟล์เอกสารแนบจากระบบ</p>
                            <? //php if ($item && $attachments !== [] && $download_all_url !== '') : 
                            ?>
                            <a href="<? //= h($download_all_url) 
                                        ?>">ดาวน์โหลดไฟล์ทั้งหมด</a>
                            <? //php endif; 
                            ?>
                        </div>
                        <div class="table-responsive table-circular-notice-index">
                            <table class="custom-table booking-table">
                                <thead>
                                    <tr>
                                        <th>ชื่อไฟล์</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <? //php if (!$item || $attachments === []) : 
                                    ?>
                                    <!-- <tr>
                                        <td colspan="2" class="enterprise-empty">ไม่พบไฟล์เอกสารแนบจากระบบ</td>
                                    </tr> -->
                                    <? //php else : 
                                    ?>
                                    <? //php foreach ($attachments as $file) : 
                                    ?>
                                    <? //php
                                    //$file_id = (int) ($file['fileID'] ?? 0);
                                    // $view_href = $file_url($file_id, false);
                                    // $download_href = $file_url($file_id, true);
                                    // $file_name = trim((string) ($file['fileName'] ?? ''));
                                    ?>
                                    <!-- <tr>
                                            <td><? //= h($file_name !== '' ? $file_name : '-') 
                                                ?></td>
                                            <td>
                                                <a class="booking-action-btn secondary" href="<? //= h($view_href) 
                                                                                                ?>" target="_blank" rel="noopener">
                                                    <i class="fa-solid fa-eye"></i>
                                                    <span class="tooltip">ดูไฟล์</span>
                                                </a>
                                                <a class="booking-action-btn secondary" href="<? //= h($download_href) 
                                                                                                ?>">
                                                    <i class="fa-solid fa-download"></i>
                                                    <span class="tooltip">ดาวน์โหลด</span>
                                                </a>
                                            </td>
                                        </tr> -->
                                    <? //php endforeach; 
                                    ?>
                                    <? //php endif; 
                                    ?>
                                    <tr>
                                        <td>gen111-campaign-proposal.pdf (ไฟล์หนังสือนำ)</td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<? //= h($view_href) 
                                                                                            ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูไฟล์</span>
                                            </a>
                                            <a class="booking-action-btn secondary" href="<? //= h($download_href) 
                                                                                            ?>">
                                                <i class="fa-solid fa-download"></i>
                                                <span class="tooltip">ดาวน์โหลด</span>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>gen221-campaign-proposal.pdf (ไฟล์เอกสารเพิ่มเติม)</td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<? //= h($view_href) 
                                                                                            ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูไฟล์</span>
                                            </a>
                                            <a class="booking-action-btn secondary" href="<? //= h($download_href) 
                                                                                            ?>">
                                                <i class="fa-solid fa-download"></i>
                                                <span class="tooltip">ดาวน์โหลด</span>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>gen221-campaign-proposal.pdf (ไฟล์เอกสารเพิ่มเติม)</td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<? //= h($view_href) 
                                                                                            ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูไฟล์</span>
                                            </a>
                                            <a class="booking-action-btn secondary" href="<? //= h($download_href) 
                                                                                            ?>">
                                                <i class="fa-solid fa-download"></i>
                                                <span class="tooltip">ดาวน์โหลด</span>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>gen221-campaign-proposal.pdf (ไฟล์เอกสารเพิ่มเติม)</td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<? //= h($view_href) 
                                                                                            ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูไฟล์</span>
                                            </a>
                                            <a class="booking-action-btn secondary" href="<? //= h($download_href) 
                                                                                            ?>">
                                                <i class="fa-solid fa-download"></i>
                                                <span class="tooltip">ดาวน์โหลด</span>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>gen221-campaign-proposal.pdf (ไฟล์เอกสารเพิ่มเติม)</td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<? //= h($view_href) 
                                                                                            ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูไฟล์</span>
                                            </a>
                                            <a class="booking-action-btn secondary" href="<? //= h($download_href) 
                                                                                            ?>">
                                                <i class="fa-solid fa-download"></i>
                                                <span class="tooltip">ดาวน์โหลด</span>
                                            </a>
                                        </td>
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

            const renderLink = (url) => {
                if (!linkContainer) {
                    return;
                }

                const link = String(url || '').trim();
                const label = link !== '' ? escapeHtml(link) : '-';
                const linkMarkup = link !== '' ?
                    `<a href="${escapeHtml(link)}" target="_blank" rel="noopener">${label}</a>` :
                    '<span>-</span>';

                linkContainer.innerHTML = `<p><strong>แนบลิ้งก์</strong></p>${linkMarkup}`;
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