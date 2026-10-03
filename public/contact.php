<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Config, Csrf, Database};
use App\Services\Settings;
use App\Support\{QrCode, Validator};

$officeName    = Settings::get('office_name', (string) Config::get('app.office', 'Civil Registration Office, Mogadishu Municipal Government'));
$officeAddress = Settings::get('office_address', (string) Config::get('app.region', 'Mogadishu Municipality Headquarters, Hamar Weyne District, Banadir Region, Somalia'));
$officePhone   = Settings::get('office_phone', '+252 61 521 2845');
$officeEmail   = Settings::get('office_email', 'registry@mogadishu.gov.so');
$officeHours   = Settings::get('office_hours', 'Saturday - Thursday: 8:00 AM - 3:30 PM, Friday: Closed');

// WhatsApp contact. Only the country code and subscriber number are accepted, which is the
// form wa.me requires; anything else leaves the section out rather than encoding a link
// that would not open a chat.
$whatsappNumber = preg_replace('/\D+/', '', Settings::get('office_whatsapp', ''));
$whatsappNumber = is_string($whatsappNumber) ? $whatsappNumber : '';
$whatsappReady  = $whatsappNumber !== '' && strlen($whatsappNumber) >= 9 && strlen($whatsappNumber) <= 15;

$whatsappText = 'Hello Civil Registration Office, I would like to inquire about a vital event registration.';
$whatsappLink = 'https://wa.me/' . $whatsappNumber . '?text=' . rawurlencode($whatsappText);

$errors = [];
if (is_post()) {
    Csrf::check();
    $v = Validator::make($_POST)
        ->required('full_name', 'Full name')->maxLength('full_name', 'Full name', 120)
        ->phone('phone', 'Phone number')
        ->required('message', 'Message')->maxLength('message', 'Message', 2000);

    $errors = $v->errors();
    if (!$v->fails()) {
        Database::execute(
            'INSERT INTO contact_messages (full_name, phone, message, status, created_at) VALUES (?, ?, ?, "new", NOW())',
            [
                trim((string) $_POST['full_name']),
                trim((string) ($_POST['phone'] ?? '')) ?: null,
                trim((string) $_POST['message']),
            ]
        );
        flash('success', 'Thank you. Your message has been received by the Municipal Civil Registration Office. An officer will review your inquiry.');
        redirect('contact.php');
    }
}

render('layouts/public_header', ['title' => 'Contact the Civil Registration Office', 'active' => 'contact']);
?>
<section class="cover">
  <div class="cover-media" style="background-image:url('<?= e(asset('img/street.jpg')) ?>');"></div>
  <div class="cover-body">
    <span class="badge">Citizen Assistance &amp; Municipal Inquiries</span>
    <h1>Contact the Civil Registration Office</h1>
    <p class="lead">
      Inquiries concerning vital event registration, certificate verification, official fees, or municipal office hours.
    </p>
  </div>
</section>

<div class="contact-grid">
  <!-- Office Details Card -->
  <div class="card office-info">
    <div class="seal" style="margin-bottom:14px; width:44px; height:44px; font-size:1.3rem;">◈</div>
    <h3><?= e($officeName) ?></h3>
    <p class="muted" style="margin-bottom:20px; font-size:13.5px;">Banadir Regional Administration &middot; Mogadishu, Somalia</p>

    <div class="office-detail">
      <span class="detail-label">Address</span>
      <span class="detail-value"><?= e($officeAddress) ?></span>
    </div>

    <div class="office-detail">
      <span class="detail-label">Telephone</span>
      <span class="detail-value"><code><?= e($officePhone) ?></code></span>
    </div>

    <div class="office-detail">
      <span class="detail-label">Email</span>
      <span class="detail-value"><a href="mailto:<?= e($officeEmail) ?>"><?= e($officeEmail) ?></a></span>
    </div>

    <div class="office-detail">
      <span class="detail-label">Hours</span>
      <span class="detail-value"><?= e($officeHours) ?></span>
    </div>

    <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--line); font-size:12.5px; color:var(--muted); line-height:1.6;">
      <p style="margin:0;">
        <strong>Certificate collection notice:</strong> Official printed certificates must be collected in person at the registry headquarters or respective district office during regular public service hours.
      </p>
    </div>
  </div>

  <!-- WhatsApp QR Code -->
  <?php if ($whatsappReady): ?>
  <div class="card whatsapp-card">
    <h3 style="margin-bottom:6px;">Message us on WhatsApp</h3>
    <p class="muted" style="font-size:13.5px; margin-bottom:18px;">
      Scan the code with your phone camera or WhatsApp to open a chat with the registry. For official
      certificates, please still use the message form so your inquiry is recorded in the system.
    </p>

    <div class="whatsapp-qr">
      <?= QrCode::svg($whatsappLink, 6, 4) ?>
    </div>

    <p class="whatsapp-number">
      <a href="<?= e($whatsappLink) ?>" target="_blank" rel="noopener noreferrer"><?= e($whatsappNumber) ?></a>
    </p>
    <p class="whatsapp-hint">Opens WhatsApp with a short pre-filled greeting.</p>
  </div>
  <?php endif; ?>

  <!-- Message Submission Form -->
  <div class="card">
    <h3 style="margin-bottom:6px;">Send an Official Message</h3>
    <p class="muted" style="font-size:13.5px; margin-bottom:20px;">
      Submit an inquiry directly to the registration registry. Please provide a valid phone number for follow-up.
    </p>

    <form method="post" novalidate>
      <?= Csrf::field() ?>

      <div class="form-group">
        <label for="full_name">Your Full Name <span class="required-star">*</span></label>
        <input id="full_name" name="full_name" type="text" required value="<?= old('full_name') ?>" placeholder="e.g. Hassan Mohamed Warsame">
        <?php if (!empty($errors['full_name'])): ?>
          <span class="error"><?= e($errors['full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="phone">Phone Number</label>
        <input id="phone" name="phone" type="tel" value="<?= old('phone') ?>" placeholder="e.g. +252 61 5434104">
        <span class="field-hint">Somali telecommunication format (+252 61/62/68...) or local number.</span>
        <?php if (!empty($errors['phone'])): ?>
          <span class="error"><?= e($errors['phone']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="message">Message / Inquiry <span class="required-star">*</span></label>
        <textarea id="message" name="message" rows="5" required placeholder="Describe your question regarding registration, correction, certificate fee, or records lookup..."><?= old('message') ?></textarea>
        <?php if (!empty($errors['message'])): ?>
          <span class="error"><?= e($errors['message']) ?></span>
        <?php endif; ?>
      </div>

      <div style="margin-top:20px;">
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;">Send Message</button>
      </div>
    </form>
  </div>
</div>

<?php render('layouts/public_footer'); ?>
