<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');

render('layouts/public_header', ['title' => 'About the Civil Registry', 'active' => 'about']);
?>
<section class="cover">
  <div class="cover-media" style="background-image:url('<?= e(asset('img/officer.jpg')) ?>');"></div>
  <div class="cover-body">
    <span class="badge">Municipal Governance &middot; Banadir Region</span>
    <h1>About the Civil Registration System</h1>
    <p class="lead">
      The official digital records infrastructure of the Civil Registration Office, Mogadishu Municipal Government.
      Replacing manual paper ledgers with an integrated, tamper-evident vital event register for the citizens of Banadir.
    </p>
  </div>
</section>

<!-- Purpose and Mandate -->
<div class="card" style="margin-bottom:32px;">
  <div class="grid-2" style="align-items:center;">
    <div>
      <h2>Institutional Mandate & Purpose</h2>
      <p style="line-height:1.75; color:var(--muted);">
        Civil registration is the fundamental continuous, permanent, compulsory and universal recording of the occurrence and characteristics of vital events.
        For the Mogadishu Municipal Government, this system establishes legal identity from birth, protects matrimonial and civil rights, guarantees the legal rights of heirs, and generates reliable municipal demographic data across Banadir Region.
      </p>
      <p style="line-height:1.75; color:var(--muted);">
        Every citizen record created in this system is preserved in an append-only archive. No approved record may ever be expunged or altered without full audit transparency, guaranteeing generational integrity and institutional trust.
      </p>
    </div>
    <div>
      <img src="<?= e(asset('img/brth.jpg')) ?>" alt="Civil registration officer at desk reviewing vital status registers in Mogadishu" style="border-radius:var(--radius-lg); width:100%; box-shadow:var(--shadow);">
      <p class="faint" style="font-size:11.5px; text-align:right; margin-top:6px;">Staff officer workstation &middot; Banadir Regional Administration</p>
    </div>
  </div>
</div>

<!-- Four Vital Records Covered -->
<section style="margin-bottom:36px;">
  <h2 class="section-title">Civil Records Covered</h2>
  <p class="lead" style="margin-bottom:24px;">
    The system standardizes four primary civil registration event categories under uniform municipal administrative procedures.
  </p>

  <div class="grid-2">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
        <h3>Birth Registration</h3>
        <span class="badge">Code: BR</span>
      </div>
      <p style="color:var(--muted); line-height:1.65; font-size:14.5px;">
        Records child's full name, gender, date and time of birth, place of birth, hospital of birth (free-text hospital entry), father and mother identity particulars and national reference numbers, and optional child photograph. Age is dynamically computed in days, months, and years.
      </p>
      <div style="font-size:13px; font-weight:600; color:var(--brass);">Statutory Fee: $20.00 USD</div>
    </div>

    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
        <h3>Death Registration</h3>
        <span class="badge">Code: DR</span>
      </div>
      <p style="color:var(--muted); line-height:1.65; font-size:14.5px;">
        Documents deceased citizen's full name, gender, dates of birth and death, exact place and district of death, informant relationship, and mandatory guarantor full name (legally responsible party). Includes optional medical hospital document reference and cause of death.
      </p>
      <div style="font-size:13px; font-weight:600; color:var(--brass);">Statutory Fee: As set by Administrator</div>
    </div>

    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
        <h3>Marriage Registration</h3>
        <span class="badge">Code: MR</span>
      </div>
      <p style="color:var(--muted); line-height:1.65; font-size:14.5px;">
        Records Husband and Wife full legal names, dates of birth, phone numbers, and residential addresses. Requires agreed Mahr (dowry amount), exact marriage date and venue, and full contact details for two legal witnesses. Respects standard civil terminology without obsolete labels.
      </p>
      <div style="font-size:13px; font-weight:600; color:var(--brass);">Statutory Fee: $25.00 USD</div>
    </div>

    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
        <h3>Divorce Registration</h3>
        <span class="badge">Code: DV</span>
      </div>
      <p style="color:var(--muted); line-height:1.65; font-size:14.5px;">
        Documents legal matrimonial dissolution between Husband and Wife, referencing the original marriage registration number (e.g. MR-2024-0045), date of dissolution, municipal district, and official court reference decree from the Banadir Regional Court.
      </p>
      <div style="font-size:13px; font-weight:600; color:var(--brass);">Statutory Fee: As set by Administrator</div>
    </div>
  </div>
</section>

<!-- Banadir Administrative Districts Coverage -->
<div class="card" style="margin-bottom:36px; background:var(--brass-faint); border-color:var(--brass-soft);">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div style="max-width:650px;">
      <h3 style="color:var(--brass-dk); margin-bottom:6px;">All <?= count($districts) ?> Banadir Administrative Districts Included</h3>
      <p style="font-size:14px; color:var(--ink); line-height:1.65; margin:0;">
        The system provides integrated registration, reporting, and certificate verification across all municipal districts:
        <strong><?= e(implode(', ', array_slice(array_column($districts, 'name'), 0, -1))) ?>,</strong> and <strong><?= e((string) end($districts)['name']) ?></strong>.
      </p>
    </div>
    <div class="seal" style="width:48px; height:48px; font-size:1.4rem;">◈</div>
  </div>
</div>

<!-- Three Roles Section -->
<section style="margin-bottom:40px;">
  <h2 class="section-title">Role-Based Governance (RBAC)</h2>
  <p class="lead" style="margin-bottom:24px;">
    System authority is partitioned across three strictly defined roles enforced at the server and database layers.
  </p>

  <div class="role-cards">
    <div class="role-card">
      <div class="role-icon">🏛️</div>
      <h3>System Administrator</h3>
      <p>
        Responsible for system configuration, user provisioning, role-permission assignments, certificate fee schedules, automated database backups, and append-only audit trail monitoring.
      </p>
      <div style="margin-top:12px; font-size:12.5px; color:var(--muted); border-top:1px solid var(--line); padding-top:8px;">
        <em>Privacy Guarantee: Administrators have no access to browse or modify civil records.</em>
      </div>
    </div>

    <div class="role-card">
      <div class="role-icon">✍️</div>
      <h3>Civil Registration Officer</h3>
      <p>
        Operates front-line citizen registration desks. Initiates birth, death, marriage, and divorce records, uploads supporting identification and child photographs, verifies duplicate warnings, and collects certificate fees.
      </p>
      <div style="margin-top:12px; font-size:12.5px; color:var(--muted); border-top:1px solid var(--line); padding-top:8px;">
        <em>Permissions: Can edit or delete only own unapproved draft records. Cannot approve own work.</em>
      </div>
    </div>

    <div class="role-card">
      <div class="role-icon">⚖️</div>
      <h3>Supervisor / Manager</h3>
      <p>
        Conducts substantive quality reviews of submitted records. Approves records by affixing an on-screen drawn digital signature, rejects invalid records with mandatory written reasons, and generates municipal analytic reports.
      </p>
      <div style="margin-top:12px; font-size:12.5px; color:var(--muted); border-top:1px solid var(--line); padding-top:8px;">
        <em>Authority: Digital signature required for all approved certificates; monitors officer activity.</em>
      </div>
    </div>
  </div>
</section>

<!-- Mogadishu Street Image -->
<div style="margin-top:20px;">
  <img src="<?= e(asset('img/street.jpg')) ?>" alt="Mogadishu street in Banadir Region with active commercial and civic life" style="border-radius:var(--radius-lg); width:100%; max-height:360px; object-fit:cover; box-shadow:var(--shadow-lg);">
  <p class="faint" style="text-align:right; margin-top:6px; font-size:11px;">Mogadishu Urban Center &middot; Serving communities across Banadir Region</p>
</div>

<?php render('layouts/public_footer'); ?>
