<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\{ExportService, RecordService, ReportService};
use App\Support\RecordTypes;

Auth::requirePermission('report.generate');

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$minYear = (int) date('Y', strtotime('-5 years'));
$maxYear = (int) date('Y') + 1;
if ($year < $minYear || $year > $maxYear) {
    $year = (int) date('Y');
}

// Which event the individual report is about: birth, death, marriage or divorce.
// Anything unrecognised falls back to birth rather than reaching RecordTypes with junk.
$eventType = (string) ($_GET['event'] ?? 'birth');
if (!RecordTypes::exists($eventType)) {
    $eventType = 'birth';
}
$eventLabel = RecordTypes::ALL[$eventType]['label'];

// ── CSV exports ────────────────────────────────────────────────────────────
$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
    switch ($export) {
        case 'district':
            ExportService::downloadCsv(
                "registrations_by_district_{$year}.csv",
                [
                    'district_name' => 'District',
                    'births'        => 'Births',
                    'deaths'        => 'Deaths',
                    'marriages'     => 'Marriages',
                    'divorces'      => 'Divorces',
                    'total'         => 'Total',
                ],
                ReportService::byDistrict()
            );
            // no break — downloadCsv exits

        case 'monthly':
            ExportService::downloadCsv(
                "monthly_registrations_{$year}.csv",
                [
                    'month'     => 'Month',
                    'births'    => 'Births',
                    'deaths'    => 'Deaths',
                    'marriages' => 'Marriages',
                    'divorces'  => 'Divorces',
                    'total'     => 'Total',
                ],
                ReportService::monthlyBreakdown($year)
            );
            // no break

        case 'fees':
            ExportService::downloadCsv(
                "fee_collections_{$year}.csv",
                [
                    'record_type'     => 'Record Type',
                    'certificate_count' => 'Certificates Issued',
                    'paid_count'      => 'Paid',
                    'unpaid_count'    => 'Unpaid',
                    'total_collected' => 'Collected (USD)',
                    'total_unpaid'    => 'Outstanding (USD)',
                ],
                array_map(
                    static fn(array $r): array => $r + ['record_type_label' => ucfirst((string) $r['record_type'])],
                    ReportService::feeCollections()
                )
            );
            // no break

        case 'officers':
            ExportService::downloadCsv(
                "officer_activity_{$year}.csv",
                [
                    'officer_name' => 'Officer',
                    'username'     => 'Username',
                    'births'       => 'Births',
                    'deaths'       => 'Deaths',
                    'marriages'    => 'Marriages',
                    'divorces'     => 'Divorces',
                    'total'        => 'Total Registered',
                    'approved'     => 'Approved',
                ],
                ReportService::officerActivity()
            );
            // no break

        // ── Individual event reports ──
        case 'event_monthly':
            ExportService::downloadCsv(
                strtolower($eventLabel) . "_registrations_by_month_{$year}.csv",
                ['month' => 'Month', 'count' => ucfirst($eventLabel) . ' Registrations'],
                ReportService::typeMonthly($eventType, $year)
            );
            // no break

        case 'event_district':
            ExportService::downloadCsv(
                strtolower($eventLabel) . "_registrations_by_district_{$year}.csv",
                ['district_name' => 'District', 'count' => ucfirst($eventLabel) . ' Registrations'],
                ReportService::typeByDistrict($eventType)
            );
            // no break

        case 'event_status':
            $statusSummary = ReportService::typeStatusSummary($eventType, $year);
            ExportService::downloadCsv(
                strtolower($eventLabel) . "_status_summary_{$year}.csv",
                ['status' => 'Status', 'count' => 'Records'],
                array_map(
                    static function (string $status, int $count): array {
                        return ['status' => ucfirst(str_replace('_', ' ', $status)), 'count' => $count];
                    },
                    array_keys($statusSummary),
                    array_values($statusSummary)
                )
            );
            // no break

        case 'event_summary':
            ExportService::downloadCsv(
                strtolower($eventLabel) . "_report_{$year}.csv",
                [
                    'record_type'   => 'Event',
                    'year'          => 'Year',
                    'total'         => 'Registered',
                    'approved'      => 'Approved',
                    'pending'       => 'Awaiting Approval',
                    'male'          => 'Male',
                    'female'        => 'Female',
                ],
                [ReportService::typeTotals($eventType, $year)]
            );
            // no break

        case 'all_records':
            ExportService::downloadCsv(
                "all_registrations_{$year}.csv",
                [
                    'record_type'        => 'Event',
                    'registration_number' => 'Registration Number',
                    'primary_name'       => 'Primary Name',
                    'partner_name'       => 'Second Party',
                    'gender'             => 'Gender',
                    'status'             => 'Status',
                    'registration_date'  => 'Registration Date',
                    'district_name'      => 'District',
                    'submitted_by_name'  => 'Registered By',
                ],
                ReportService::everyRecord($year)
            );
            // no break

        default:
            flash('danger', 'Unknown export requested.');
            redirect('supervisor/reports.php?year=' . $year);
    }
}

$districtData = ReportService::byDistrict();
$monthly      = ReportService::monthlyBreakdown($year);
$feeData      = ReportService::feeCollections();
$officerData  = ReportService::officerActivity();

$yearTotal = array_sum(array_column($monthly, 'total'));
$maxMonth  = max(1, ...array_column($monthly, 'total'));
$collected = 0.0;
$outstanding = 0.0;
$paidCount = 0;
$unpaidCount = 0;
foreach ($feeData as $row) {
    $collected   += (float) $row['total_collected'];
    $outstanding += (float) $row['total_unpaid'];
    $paidCount   += (int) $row['paid_count'];
    $unpaidCount += (int) $row['unpaid_count'];
}

// An href of 'supervisor/reports.php' would resolve against this page's own directory and
// become /supervisor/supervisor/reports.php, which is a 404.
$exportLink = static fn(string $kind): string => url('supervisor/reports.php?year=' . $year . '&export=' . $kind);
$eventLink  = static fn(string $type): string => url('supervisor/reports.php?year=' . $year . '&event=' . $type);
$eventExportLink = static fn(string $kind): string => url('supervisor/reports.php?year=' . $year . '&event=' . $eventType . '&export=' . $kind);

// Individual report figures for the selected event.
$eventTotals   = ReportService::typeTotals($eventType, $year);
$eventMonthly  = ReportService::typeMonthly($eventType, $year);
$eventDistrict = ReportService::typeByDistrict($eventType);
$eventStatus   = ReportService::typeStatusSummary($eventType, $year);
$eventRows     = RecordService::listRecords(null, '', $eventType, '', 500);
$allTotals     = ReportService::allTypeTotals($year);
$allRows       = ReportService::everyRecord($year, 500);
$eventMaxMonth = max(1, ...array_column($eventMonthly, 'count'));

render('layouts/app_header', ['title' => 'Civil Registration Reports']);
?>
<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Reports</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Civil Registration Reports &amp; Analytics</h2>
    <p class="muted" style="margin:0;">Official administrative summaries for Mogadishu Municipal Government, Banadir Region.</p>
  </div>
  <form method="get" style="display:flex; gap:8px; align-items:center;">
    <?php if ($eventType !== 'birth'): ?>
      <input type="hidden" name="event" value="<?= e($eventType) ?>">
    <?php endif; ?>
    <label for="year" class="muted">Reporting year</label>
    <select id="year" name="year" onchange="this.form.submit()">
      <?php for ($y = $maxYear; $y >= $minYear; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<!-- The printable sheet is the same figures on letterhead, for a report that has to be
     signed and filed rather than read on screen. -->
<div class="card" style="display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:20px; border-left:3px solid var(--brass);">
  <div style="min-width:240px;">
    <h3 style="margin:0 0 3px;">Official Printable Report</h3>
    <span class="muted" style="font-size:12.5px;">
      Letterhead with report reference, reporting period, signature blocks and stamp space &mdash; ready to print or save as PDF.
      <?php if ($eventType !== 'birth'): ?>
        Currently scoped to the <b><?= e($eventLabel) ?></b> report.
      <?php endif; ?>
    </span>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= e(url('supervisor/report-print.php?year=' . $year . '&scope=all')) ?>" class="btn btn-primary">
      Print &mdash; all events
    </a>
    <a href="<?= e(url('supervisor/report-print.php?year=' . $year . '&scope=' . $eventType)) ?>" class="btn">
      Print &mdash; <?= e($eventLabel) ?> only
    </a>
  </div>
</div>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
  <div class="card" style="text-align:center;">
    <div class="muted" style="font-size:13px;">Registrations in <?= (int) $year ?></div>
    <div style="font-size:28px; font-weight:700;"><?= (int) $yearTotal ?></div>
  </div>
  <div class="card" style="text-align:center;">
    <div class="muted" style="font-size:13px;">Fees collected</div>
    <div style="font-size:28px; font-weight:700; color:var(--ok);"><?= e(money($collected)) ?></div>
  </div>
  <div class="card" style="text-align:center;">
    <div class="muted" style="font-size:13px;">Outstanding fees</div>
    <div style="font-size:28px; font-weight:700; color:var(--warn);"><?= e(money($outstanding)) ?></div>
  </div>
  <div class="card" style="text-align:center;">
    <div class="muted" style="font-size:13px;">Certificates paid / unpaid</div>
    <div style="font-size:28px; font-weight:700;"><?= (int) $paidCount ?> / <?= (int) $unpaidCount ?></div>
  </div>
</div>

<!-- Individual report, one event at a time -->
<div class="card" style="margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <div>
      <h3 style="margin:0;"><?= e($eventLabel) ?> Report — <?= (int) $year ?></h3>
      <span class="muted" style="font-size:12px;">This event on its own: totals, monthly volume, districts and workflow status.</span>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
      <a href="<?= e($eventExportLink('event_summary')) ?>" class="btn btn-sm">Export summary</a>
      <a href="<?= e($eventExportLink('event_monthly')) ?>" class="btn btn-sm">Export monthly</a>
      <a href="<?= e($eventExportLink('event_district')) ?>" class="btn btn-sm">Export districts</a>
      <a href="<?= e($eventExportLink('event_status')) ?>" class="btn btn-sm">Export status</a>
    </div>
  </div>

  <div style="display:flex; gap:6px; margin:16px 0; flex-wrap:wrap;">
    <?php foreach (RecordTypes::ALL as $type => $meta): ?>
      <a href="<?= e($eventLink($type)) ?>" class="btn btn-sm <?= $type === $eventType ? 'btn-primary' : '' ?>">
        <?= e($meta['label']) ?> report (<?= (int) ($allTotals[$type]['total'] ?? 0) ?>)
      </a>
    <?php endforeach; ?>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:14px; margin-bottom:18px;">
    <div class="muted" style="font-size:13px;">
      <?= e($eventLabel) ?>s registered in <?= (int) $year ?>
      <div style="font-size:26px; font-weight:700; color:var(--ink);"><?= (int) $eventTotals['total'] ?></div>
    </div>
    <div class="muted" style="font-size:13px;">
      Approved
      <div style="font-size:26px; font-weight:700; color:var(--ok);"><?= (int) $eventTotals['approved'] ?></div>
    </div>
    <div class="muted" style="font-size:13px;">
      Awaiting approval
      <div style="font-size:26px; font-weight:700; color:var(--warn);"><?= (int) $eventTotals['pending'] ?></div>
    </div>
    <?php if ($eventTotals['has_gender']): ?>
      <div class="muted" style="font-size:13px;">
        Male / female
        <div style="font-size:26px; font-weight:700; color:var(--ink);"><?= (int) $eventTotals['male'] ?> / <?= (int) $eventTotals['female'] ?></div>
      </div>
    <?php endif; ?>
  </div>

  <h4 style="margin:0 0 8px;">Monthly <?= e(strtolower($eventLabel)) ?> volume</h4>
  <div style="display:flex; align-items:flex-end; gap:6px; height:120px; padding:6px 0; border-bottom:1px solid var(--line);">
    <?php foreach ($eventMonthly as $m): ?>
      <?php $barHeight = (int) round(((int) $m['count'] / $eventMaxMonth) * 95); ?>
      <div style="flex:1; text-align:center;" title="<?= e($m['month']) ?>: <?= (int) $m['count'] ?> record(s)">
        <div style="font-size:11px; color:var(--muted);"><?= (int) $m['count'] ?></div>
        <div style="height:<?= max(2, $barHeight) ?>px; background:var(--brass); border-radius:3px 3px 0 0;"></div>
        <div style="font-size:11px; color:var(--muted); margin-top:4px;"><?= e(date('M', strtotime($m['ym'] . '-01'))) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:20px; margin-top:20px; align-items:start;">
    <div>
      <h4 style="margin:0 0 8px;">By district</h4>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>District</th><th style="text-align:right;"><?= e($eventLabel) ?>s</th></tr>
          </thead>
          <tbody>
            <?php foreach ($eventDistrict as $row): ?>
              <tr>
                <td><?= e($row['district_name']) ?></td>
                <td style="text-align:right;"><?= (int) $row['count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th>All districts</th>
              <th style="text-align:right;"><?= (int) array_sum(array_column($eventDistrict, 'count')) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div>
      <h4 style="margin:0 0 8px;">By workflow status</h4>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Status</th><th style="text-align:right;">Records</th></tr>
          </thead>
          <tbody>
            <?php foreach (RecordTypes::STATUSES as $status): ?>
              <tr>
                <td><span class="badge status-<?= e($status) ?>"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span></td>
                <td style="text-align:right;"><?= (int) ($eventStatus[$status] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th>Total</th>
              <th style="text-align:right;"><?= (int) ($eventStatus['total'] ?? 0) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <h4 style="margin:20px 0 8px;"><?= e($eventLabel) ?> records in <?= (int) $year ?></h4>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reg Number</th>
          <th>Name</th>
          <th>District</th>
          <th>Date</th>
          <th>Status</th>
          <th style="text-align:right;">Registered By</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($eventRows === []): ?>
          <tr><td colspan="6" class="muted" style="text-align:center; padding:20px;">No <?= e(strtolower($eventLabel)) ?> records were registered in <?= (int) $year ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($eventRows as $row): ?>
          <tr>
            <td><code><?= e($row['registration_number']) ?></code></td>
            <td><strong><?= e($row['primary_name']) ?></strong></td>
            <td><?= e($row['district_name'] ?? '—') ?></td>
            <td><?= e($row['registration_date']) ?></td>
            <td><span class="badge status-<?= e($row['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $row['status']))) ?></span></td>
            <td style="text-align:right;"><?= e($row['submitted_by_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <h3 style="margin:0;">Registrations Across <?= count($districtData) ?> Banadir Districts</h3>
    <a href="<?= e($exportLink('district')) ?>" class="btn btn-sm">Export CSV</a>
  </div>
  <table>
    <thead>
      <tr>
        <th>District</th>
        <th>Births</th>
        <th>Deaths</th>
        <th>Marriages</th>
        <th>Divorces</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($districtData as $row): ?>
      <tr>
        <td><b><?= e($row['district_name']) ?></b></td>
        <td><?= (int) $row['births'] ?></td>
        <td><?= (int) $row['deaths'] ?></td>
        <td><?= (int) $row['marriages'] ?></td>
        <td><?= (int) $row['divorces'] ?></td>
        <td><b><?= (int) $row['total'] ?></b></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>All districts</th>
        <th><?= (int) array_sum(array_column($districtData, 'births')) ?></th>
        <th><?= (int) array_sum(array_column($districtData, 'deaths')) ?></th>
        <th><?= (int) array_sum(array_column($districtData, 'marriages')) ?></th>
        <th><?= (int) array_sum(array_column($districtData, 'divorces')) ?></th>
        <th><?= (int) array_sum(array_column($districtData, 'total')) ?></th>
      </tr>
    </tfoot>
  </table>
</div>

<div class="card" style="margin-top:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <h3 style="margin:0;">Monthly Registration Volume — <?= (int) $year ?></h3>
    <a href="<?= e($exportLink('monthly')) ?>" class="btn btn-sm">Export CSV</a>
  </div>
  <div style="display:flex; align-items:flex-end; gap:6px; height:160px; padding:10px 0; border-bottom:1px solid var(--line);">
    <?php foreach ($monthly as $m): ?>
      <?php $h = (int) round(((int) $m['total'] / $maxMonth) * 130); ?>
      <div style="flex:1; text-align:center;" title="<?= e($m['month']) ?>: <?= (int) $m['total'] ?> record(s)">
        <div style="font-size:11px; color:var(--muted);"><?= (int) $m['total'] ?></div>
        <div style="height:<?= max(2, $h) ?>px; background:var(--brass); border-radius:3px 3px 0 0;"></div>
        <div style="font-size:11px; color:var(--muted); margin-top:4px;"><?= e(date('M', strtotime($m['month']))) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <table style="margin-top:14px;">
    <thead>
      <tr>
        <th>Month</th>
        <th>Births</th>
        <th>Deaths</th>
        <th>Marriages</th>
        <th>Divorces</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($monthly as $m): ?>
      <tr>
        <td><?= e($m['month']) ?></td>
        <td><?= (int) $m['births'] ?></td>
        <td><?= (int) $m['deaths'] ?></td>
        <td><?= (int) $m['marriages'] ?></td>
        <td><?= (int) $m['divorces'] ?></td>
        <td><b><?= (int) $m['total'] ?></b></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:20px; margin-top:20px; align-items:start;">
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
      <h3 style="margin:0;">Fee Collections</h3>
      <a href="<?= e($exportLink('fees')) ?>" class="btn btn-sm">Export CSV</a>
    </div>
    <table>
      <thead>
        <tr>
          <th>Record Type</th>
          <th>Issued</th>
          <th>Paid</th>
          <th>Unpaid</th>
          <th>Collected</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($feeData === []): ?>
          <tr><td colspan="5" class="muted">No certificates have been issued yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($feeData as $row): ?>
        <tr>
          <td><b><?= e(ucfirst((string) $row['record_type'])) ?></b></td>
          <td><?= (int) $row['certificate_count'] ?></td>
          <td><?= (int) $row['paid_count'] ?></td>
          <td><?= (int) $row['unpaid_count'] ?></td>
          <td><?= e(money((float) $row['total_collected'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th>Total</th>
          <th><?= (int) ($paidCount + $unpaidCount) ?></th>
          <th><?= (int) $paidCount ?></th>
          <th><?= (int) $unpaidCount ?></th>
          <th><?= e(money($collected)) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
      <h3 style="margin:0;">Officer Activity</h3>
      <a href="<?= e($exportLink('officers')) ?>" class="btn btn-sm">Export CSV</a>
    </div>
    <table>
      <thead>
        <tr>
          <th>Officer</th>
          <th>Registered</th>
          <th>Approved</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($officerData as $row): ?>
        <tr>
          <td><b><?= e($row['officer_name']) ?></b><br><small class="muted"><?= e($row['username']) ?></small></td>
          <td><?= (int) $row['total'] ?></td>
          <td><?= (int) $row['approved'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- All four events in one register -->
<div class="card" style="margin-top:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <div>
      <h3 style="margin:0;">All Events Register — <?= (int) $year ?></h3>
      <span class="muted" style="font-size:12px;">
        Every civil record of the year in one list<?= count($allRows) >= 500 ? ', most recent 500 shown' : '' ?>.
      </span>
    </div>
    <a href="<?= e(url('supervisor/reports.php?year=' . $year . '&export=all_records')) ?>" class="btn btn-sm">Export CSV</a>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:14px; margin:16px 0;">
    <?php foreach ($allTotals as $type => $totals): ?>
      <div class="muted" style="font-size:13px;">
        <a href="<?= e($eventLink($type)) ?>" style="color:var(--brass); font-weight:600;"><?= e($totals['label']) ?></a>
        <div style="font-size:24px; font-weight:700; color:var(--ink);"><?= (int) $totals['total'] ?></div>
        <small><?= (int) $totals['approved'] ?> approved</small>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reg Number</th>
          <th>Event</th>
          <th>Primary Name</th>
          <th>Second Party</th>
          <th>District</th>
          <th>Date</th>
          <th>Status</th>
          <th style="text-align:right;">Registered By</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($allRows === []): ?>
          <tr><td colspan="8" class="muted" style="text-align:center; padding:20px;">No records were registered in <?= (int) $year ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($allRows as $row): ?>
          <tr>
            <td><code><?= e($row['registration_number']) ?></code></td>
            <td><?= e(ucfirst((string) $row['record_type'])) ?></td>
            <td><strong><?= e($row['primary_name']) ?></strong><?= $row['gender'] !== '' ? ' <small class="muted">(' . e(ucfirst((string) $row['gender'])) . ')</small>' : '' ?></td>
            <td><?= e($row['partner_name'] !== '' ? (string) $row['partner_name'] : '—') ?></td>
            <td><?= e($row['district_name'] ?? '—') ?></td>
            <td><?= e($row['registration_date']) ?></td>
            <td><span class="badge status-<?= e($row['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $row['status']))) ?></span></td>
            <td style="text-align:right;"><?= e($row['submitted_by_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render('layouts/app_footer'); ?>
