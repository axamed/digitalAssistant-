<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Settings;

// Live counts from database
$birthCount    = (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE is_archived = 0');
$marriageCount = (int) Database::scalar('SELECT COUNT(*) FROM marriage_records WHERE is_archived = 0');
$deathCount    = (int) Database::scalar('SELECT COUNT(*) FROM death_records WHERE is_archived = 0');
$districtCount = (int) Database::scalar('SELECT COUNT(*) FROM districts');

$features = [
    [
        'title' => 'Birth Registration',
        'desc'  => 'The system simplifies the registration of births and the management of essential information about the child, parents, and birth event. Each record is securely stored while maintaining data accuracy and providing traceability for all changes made to the record.

The system supports birth data entry, information verification, record approval, record search, and birth certificate issuance. All significant activities are recorded in an audit trail to ensure **transparency, accountability, and the integrity of civil registration records**.
.',
        // 'tag'   => 'Integrity & Transparency',
        'image' => 'assets/img/birthregister.jpg',
        'alt'   => 'Mogadishu Municipal Government administrative headquarters',
    ],
    [
        'title' => 'Intelligent Duplicate Detection',
        'desc'  => 'Before a marriage is registered, the system cross-checks existing civil records in real time to verify the identities of both parties, confirm relevant personal details, and detect any existing marriage records. This helps prevent duplicate or conflicting registrations and maintains the accuracy and integrity of marriage records across Banadir Region.',
        'tag'   => 'Accuracy & Reliability',
        'image' => 'assets/img/marriage.jpg',
        'alt'   => 'Mogadishu Municipal Government administrative headquarters',
    ],
    [
        'title' => 'Multi-Tier Review & Digital Signatures',
        'desc'  => 'Registration officers submit draft entries for supervisor review. Supervisors can approve with an on-screen drawn digital signature, reject with mandatory justification, or request corrections with notes.',
        'tag'   => 'Approval Workflow',
        'image' => 'assets/img/divorce.jpg',
        'alt'   => 'Civil registration officer managing municipal citizen records at office desk',
    ],
    [
        'title' => 'Strict Privacy & Role-Based Access Control',
        'desc'  => 'Administrative privileges are segregated from citizen status data. System administrators manage users, fees, and backups without browsing civil records. Officers and supervisors access only authorized workflows.',
        'tag'   => 'Data Protection',
        'image' => 'assets/img/register.jpg',
        'alt'   => 'Mogadishu Municipal Government administrative headquarters',
    ],
    [
        'title' => 'Municipal Dashboards & Analytic Reports',
        'desc'  => 'Real-time metrics tracking registration volumes across all ' . $districtCount . ' Banadir districts, monthly event trends, officer submission activity, and certificate fee collection status with instant CSV and PDF export.',
        'tag'   => 'Governance Insights',
        'image' => 'assets/img/muni.jpg',
    ],
    [
        'title' => 'Official Certificates with QR Verification',
        'desc'  => 'Approved records generate standardized A4 certificates with municipal seal, registrar digital signature, and unique cryptographic QR verification tokens. Anyone can verify certificate validity without exposing private details.',
        'tag'   => 'Authenticity & Trust',
        'image' => 'assets/img/image.pnc:\Users\hp\Desktop\responsive.jpgg',
    ],
    [
        'title' => 'Responsive Across Any Device',
        'desc'  => 'Designed to perform reliably on desktop workstations in municipal offices, tablets in maternity hospitals, and mobile devices in the field throughout the Banadir regional administration.',
        'tag'   => 'Field-Ready Accessibility',
        'image' => 'assets/img/responsive.jpg',
        'alt'   => 'Street scene in Mogadishu showing local commerce and civic infrastructure',
    ],
];

render('layouts/public_header', ['title' => 'Civil Registration Office', 'active' => 'home']);
?>

<!-- Hero Section -->
<section class="cover">
  <div class="cover-media" style="background-image:url('<?= e(asset('img/aqalka.jpg')) ?>');"></div>
  <div class="cover-body">
  <div class="hero">
  <div>
    <div class="hero-badge">
      <span class="badge">Mogadishu Municipal Government &middot; Banadir Region</span>
    </div>
    <h1>Every birth, marriage and death, recorded once and kept safe.</h1>
    <p class="lead">
      The official digital civil registration record-keeping system for the Mogadishu Municipal Government.
      Securely registering vital life events, reviewing records with supervisor digital signatures, and issuing verifiable certificates for citizens across Banadir.
    </p>
    <div class="hero-actions">
      <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Staff Login &rarr;</a>
      <a class="btn" href="<?= e(url('about.php')) ?>">About the System</a>
      <a class="btn" href="<?= e(url('contact.php')) ?>">Contact Office</a>
    </div>
  </div>

  <div class="hero-verify-card">
    <div class="seal" style="margin-bottom:12px;">◈</div>
    <h3>Verify an Official Certificate</h3>
    <p>Enter the verification token printed beneath the QR code on any issued civil certificate to confirm its validity.</p>
    <form method="get" action="<?= e(url('verify.php')) ?>">
      <div class="form-group" style="margin-bottom:12px;">
        <label for="token">Verification Token</label>
        <input id="token" name="token" required maxlength="64" placeholder="e.g. a91f3c7e52b04d6f8e21..." autocomplete="off">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
        Verify Certificate Authenticity
      </button>
    </form>
    <div style="margin-top:12px; font-size:12px; color:var(--muted); text-align:center;">
      Test sample token: <code>a91f3c7e52b04d6f8e2193b4a2c107e5</code>
    </div>
  </div>
  </div>
</section>

<!-- Municipal Statistics Strip -->
<div class="stats-strip">
  <div class="stat-item">
    <strong><?= e((string) $birthCount) ?></strong>
    <span>Births Registered</span>
  </div>
  <div class="stat-item">
    <strong><?= e((string) $marriageCount) ?></strong>
    <span>Marriages Recorded</span>
  </div>
  <div class="stat-item">
    <strong><?= e((string) $deathCount) ?></strong>
    <span>Deaths Documented</span>
  </div>
  <!-- <div class="stat-item">
    <strong><?= e((string) $districtCount) ?></strong>
    <span>Banadir Districts</span>
  </div> -->
</div>

<!-- Wide Photo Banner -->
<div style="margin: 28px 0 44px;">
  <img class="wide-photo" src="<?= e(asset('img/aqalka.jpg')) ?>" alt="Civil Registration Office, Mogadishu Municipal Government Headquarters">
  <p class="faint" style="text-align:right; margin-top:6px; font-size:11px;">Mogadishu Municipal Administration &middot; Banadir Region, Somalia</p>
</div>

<!-- What the System Does: Alternating Feature Rows -->
<section class="features-section">
  <h2 class="section-title">What the System Does</h2>
  <p class="lead" style="margin-bottom:28px;">
    Engineered to replace fragmented paper dossiers with an audited, role-governed civil archive that meets national identity and municipal registry standards.
  </p>

  <?php foreach ($features as $i => $f): ?>
    <div class="feature <?= $i % 2 === 1 ? 'rev' : '' ?>">
      <div>
        <span class="badge" style="margin-bottom:8px;"><?= e($f['tag']) ?></span>
        <h3><?= e($f['title']) ?></h3>
        <p><?= e($f['desc']) ?></p>
      </div>

      <div>
        <?php if (!empty($f['image']) && is_file(BASE_PATH . '/public/' . $f['image'])): ?>
          <img class="feature-img" src="<?= e(asset(str_replace('assets/', '', $f['image']))) ?>" alt="<?= e($f['alt'] ?? $f['title']) ?>">
        <?php else: ?>
          <div class="feature-panel">
            <span><?= e(sprintf('%02d', $i + 1)) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<!-- Official Certificate Fees Table -->
<section style="margin: 48px 0 20px;">
  <div style="text-align:center; max-width:600px; margin:0 auto 28px;">
    <h2 class="section-title" style="display:inline-block;">Official Certificate Fees</h2>
    <p class="muted">
      Statutory fees established by the Mogadishu Municipal Government for official civil status certificates. All payments are issued official receipts before printing.
    </p>
  </div>

  <div class="fee-table-wrap">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Certificate Type</th>
            <th>Governing Body</th>
            <th style="text-align:right;">Official Fee</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $feeTypes = [
              'birth'    => ['Birth Certificate', 'Civil Registration Office'],
              'marriage' => ['Marriage Certificate', 'Civil Registration Office'],
              'death'    => ['Death Certificate', 'Civil Registration Office'],
              'divorce'  => ['Divorce Certificate', 'Banadir Regional Court / Registry'],
          ];
          foreach ($feeTypes as $ftype => [$flabel, $fdept]):
              $feeVal = Settings::fee($ftype);
          ?>
            <tr>
              <td><b><?= e($flabel) ?></b></td>
              <td class="muted"><?= e($fdept) ?></td>
              <td style="text-align:right;">
                <?php if ($feeVal > 0): ?>
                  <strong>$<?= e(number_format($feeVal, 2)) ?> USD</strong>
                <?php else: ?>
                  <span class="muted">Set by Administrator</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="faint" style="margin-top:8px; text-align:center;">Fees can be modified exclusively by authorized system administrators in Settings.</p>
  </div>
</section>


<?php render('layouts/public_footer'); ?>
