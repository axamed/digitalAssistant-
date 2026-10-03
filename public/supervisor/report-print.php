<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Audit, Auth, Config, Database};
use App\Services\{ReportService, Settings};
use App\Support\RecordTypes;

/**
 * Printable official report.
 *
 * This is the printable form of the same figures the analytics screen shows, on letterhead:
 * a reference number, the reporting period, the figures themselves, and signature blocks for
 * the preparing officer and the approving supervisor. Every print is written to the audit log,
 * because an official report that leaves the building is a disclosure.
 */
$user = Auth::requirePermission('report.generate');
$userId = (int) $user['id'];

// The same window the analytics screen allows, so a report cannot be printed for a period
// that the screen would refuse to show.
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$minYear = (int) date('Y', strtotime('-5 years'));
$maxYear = (int) date('Y') + 1;
if ($year < $minYear || $year > $maxYear) {
    $year = (int) date('Y');
}

$officeName    = Settings::get('office_name', (string) Config::get('app.office', 'Civil Registration Office, Mogadishu Municipal Government'));
$officeAddress = Settings::get('office_address', (string) Config::get('app.region', 'Mogadishu Municipality Headquarters, Hamar Weyne District, Banadir Region, Somalia'));

// The scope is chosen from the same record types the analytics screen offers, and is checked
// against the map so a hand-edited query string cannot ask for a table that does not exist.
$scope = (string) ($_GET['scope'] ?? 'all');
if (!array_key_exists($scope, RecordTypes::ALL)) {
    $scope = 'all';
}

$periodLabel = $year > 0 ? (string) $year : 'All recorded years';

if ($scope === 'all') {
    $title     = 'Consolidated Civil Registration Statistics';
    $eventRows = ReportService::allTypeTotals($year);
    $districtData = ReportService::byDistrict();
    $monthly   = ReportService::monthlyBreakdown($year);
    $feeData   = ReportService::feeCollections();
} else {
    $eventRows  = [ReportService::typeTotals($scope, $year)];
    $title      = RecordTypes::ALL[$scope]['label'] . ' Registration Statistics';
    $districtData = ReportService::typeByDistrict($scope);
    $monthly    = ReportService::typeMonthly($scope, $year);
    $feeData    = ReportService::feeCollections();
}

$totals = ['total' => 0, 'approved' => 0, 'pending' => 0];
foreach ($eventRows as $row) {
    $totals['total']    += (int) ($row['total'] ?? 0);
    $totals['approved'] += (int) ($row['approved'] ?? 0);
    $totals['pending']  += (int) ($row['pending'] ?? 0);
}

$monthTotal = array_sum(array_map(static fn(array $m): int => (int) $m['total'], $monthly));
$maxMonth   = max(1, ...array_map(static fn(array $m): int => (int) $m['total'], $monthly));

$collected = 0.0;
$outstanding = 0.0;
$feesPaid = 0;
$feesUnpaid = 0;
foreach ($feeData as $row) {
    $collected   += (float) $row['total_collected'];
    $outstanding += (float) $row['total_unpaid'];
    $feesPaid     += (int) $row['paid_count'];
    $feesUnpaid   += (int) $row['unpaid_count'];
}

// A reference number a clerk can quote on the phone, made from the period and scope so the
// same report always carries the same number.
$reference = sprintf('CR-%s-%s-%04d', strtoupper($scope === 'all' ? 'ALL' : substr($scope, 0, 3)), date('Y'), $year);

// The record count is taken from the archive rather than restated in prose, so the headline
// figure on the sheet cannot drift from the figures in the tables below it.
$archiveTotal = (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE is_archived = 0')
              + (int) Database::scalar('SELECT COUNT(*) FROM marriage_records WHERE is_archived = 0')
              + (int) Database::scalar('SELECT COUNT(*) FROM death_records WHERE is_archived = 0')
              + (int) Database::scalar('SELECT COUNT(*) FROM divorce_records WHERE is_archived = 0');

$officers = ReportService::officerActivity();

$printedAt = date('Y-m-d H:i:s');

$backLink = url('supervisor/reports.php?year=' . $year . ($scope === 'all' ? '' : '&event=' . $scope));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Official Report <?= e($reference) ?></title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    /* The sheet keeps its own print palette; the app theme is only for the toolbar. */
    body { background: var(--bg); }
    @media print { body { background: #fff !important; } }
  </style>
</head>
<body>
  <div class="report-bar no-print">
    <div>
      <strong>Official report <?= e($reference) ?></strong>
      <span class="muted" style="margin-left:8px;">Print, or save as PDF from the browser's print dialog.</span>
    </div>
    <div style="display:flex; gap:8px;">
      <button type="button" onclick="window.print()" class="btn btn-primary">Print / Save as PDF</button>
      <a href="<?= e($backLink) ?>" class="btn">Back to Reports</a>
    </div>
  </div>

  <div class="report-sheet">
    <div class="report-letterhead">
      <p class="country">Federal Republic of Somalia</p>
      <p class="council">Mogadishu Municipal Government</p>
      <p class="office"><?= e($officeName) ?> &mdash; Banadir Region</p>
    </div>

    <h1 class="report-title"><?= e($title) ?></h1>
    <div class="report-meta">
      <span>Report reference: <b><?= e($reference) ?></b></span>
      <span>Reporting period: <b><?= e($periodLabel) ?></b></span>
      <span>Scope: <b><?= e($scope === 'all' ? 'All civil events' : RecordTypes::ALL[$scope]['label']) ?></b></span>
    </div>

    <h2 class="report-heading">1. Headline figures</h2>
    <table>
      <thead>
        <tr>
          <th>Measure</th>
          <th class="num">Figure</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Registrations in period</td>
          <td class="num report-figure"><?= e((string) $totals['total']) ?></td>
        </tr>
        <tr>
          <td>Approved and certified</td>
          <td class="num report-figure"><?= e((string) $totals['approved']) ?></td>
        </tr>
        <tr>
          <td>Awaiting approval or returned</td>
          <td class="num report-figure"><?= e((string) $totals['pending']) ?></td>
        </tr>
        <tr>
          <td>Total active civil archive (all years, all events)</td>
          <td class="num report-figure"><?= e((string) $archiveTotal) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr>
          <td>Approval rate</td>
          <td class="num report-figure"><?= $totals['total'] > 0 ? e(number_format($totals['approved'] / $totals['total'] * 100, 1)) : '0.0' ?>%</td>
        </tr>
      </tfoot>
    </table>

    <h2 class="report-heading">2. Registrations by civil event</h2>
    <table>
      <thead>
        <tr>
          <th>Civil event</th>
          <th class="num">Total</th>
          <th class="num">Approved</th>
          <th class="num">Pending</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eventRows as $row): ?>
          <tr>
            <td><?= e((string) ($row['label'] ?? '—')) ?></td>
            <td class="num report-figure"><?= e((string) ($row['total'] ?? 0)) ?></td>
            <td class="num report-figure"><?= e((string) ($row['approved'] ?? 0)) ?></td>
            <td class="num report-figure"><?= e((string) ($row['pending'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td>Total</td>
          <td class="num report-figure"><?= e((string) $totals['total']) ?></td>
          <td class="num report-figure"><?= e((string) $totals['approved']) ?></td>
          <td class="num report-figure"><?= e((string) $totals['pending']) ?></td>
        </tr>
      </tfoot>
    </table>

    <h2 class="report-heading">3. Monthly distribution</h2>
    <table>
      <thead>
        <tr>
          <th>Month</th>
          <th class="num">Registrations</th>
          <th class="num">Share of period</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];
        foreach ($monthly as $m):
            $mTotal  = (int) $m['total'];
            $mNumber = (int) ($m['month'] ?? 0);
            $mLabel  = isset($monthNames[$mNumber - 1]) ? $monthNames[$mNumber - 1] : ('Month ' . $mNumber);
        ?>
          <tr>
            <td><?= e($mLabel) ?></td>
            <td class="num report-figure"><?= e((string) $mTotal) ?></td>
            <td class="num report-figure"><?= $monthTotal > 0 ? e(number_format($mTotal / $monthTotal * 100, 1)) : '0.0' ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td>Total</td>
          <td class="num report-figure"><?= e((string) $monthTotal) ?></td>
          <td class="num report-figure"><?= $monthTotal > 0 ? '100.0' : '0.0' ?>%</td>
        </tr>
      </tfoot>
    </table>

    <h2 class="report-heading">4. Registrations by district</h2>
    <table>
      <thead>
        <tr>
          <th>District</th>
          <?php if ($scope === 'all'): ?>
            <th class="num">Births</th>
            <th class="num">Deaths</th>
            <th class="num">Marriages</th>
          <?php endif; ?>
          <th class="num">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($districtData)): ?>
          <tr><td colspan="<?= $scope === 'all' ? 4 : 2 ?>" style="text-align:center; color:#6b7280;">No registrations recorded for this period.</td></tr>
        <?php else: ?>
          <?php
          $districtTotal = 0;
          foreach ($districtData as $d):
              $dTotal = (int) ($d['total'] ?? 0);
              $districtTotal += $dTotal;
          ?>
            <tr>
              <td><?= e((string) ($d['name'] ?? '—')) ?></td>
              <?php if ($scope === 'all'): ?>
                <td class="num report-figure"><?= e((string) ($d['births'] ?? 0)) ?></td>
                <td class="num report-figure"><?= e((string) ($d['deaths'] ?? 0)) ?></td>
                <td class="num report-figure"><?= e((string) ($d['marriages'] ?? 0)) ?></td>
              <?php endif; ?>
              <td class="num report-figure"><?= e((string) $dTotal) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($districtData)): ?>
        <tfoot>
          <tr>
            <td>Total</td>
            <?php if ($scope === 'all'): ?>
              <td class="num report-figure"><?= e((string) array_sum(array_map(static fn(array $r): int => (int) ($r['births'] ?? 0), $districtData))) ?></td>
              <td class="num report-figure"><?= e((string) array_sum(array_map(static fn(array $r): int => (int) ($r['deaths'] ?? 0), $districtData))) ?></td>
              <td class="num report-figure"><?= e((string) array_sum(array_map(static fn(array $r): int => (int) ($r['marriages'] ?? 0), $districtData))) ?></td>
            <?php endif; ?>
            <td class="num report-figure"><?= e((string) $districtTotal) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>

    <h2 class="report-heading">5. Certificate fee collection</h2>
    <table>
      <thead>
        <tr>
          <th>Measure</th>
          <th class="num">Figure</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Fees collected (USD)</td>
          <td class="num report-figure"><?= e(number_format($collected, 2)) ?></td>
        </tr>
        <tr>
          <td>Fees outstanding on issued certificates (USD)</td>
          <td class="num report-figure"><?= e(number_format($outstanding, 2)) ?></td>
        </tr>
        <tr>
          <td>Certificates paid / unpaid</td>
          <td class="num report-figure"><?= e((string) $feesPaid) ?> / <?= e((string) $feesUnpaid) ?></td>
        </tr>
      </tbody>
    </table>

    <h2 class="report-heading">6. Officer submission activity</h2>
    <table>
      <thead>
        <tr>
          <th>Officer</th>
          <th class="num">Submitted</th>
          <th class="num">Approved</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($officers)): ?>
          <tr><td colspan="3" style="text-align:center; color:#6b7280;">No officer submissions recorded.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($officers, 0, 25) as $o): ?>
            <tr>
              <td><?= e((string) $o['officer_name']) ?></td>
              <td class="num report-figure"><?= e((string) $o['total']) ?></td>
              <td class="num report-figure"><?= e((string) $o['approved']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <p class="report-footnote">
      Issued under the Municipal Civil Registration Regulations of the Banadir Region by the
      <?= e($officeName) ?>, <?= e($officeAddress) ?>. Figures are drawn directly from the
      append-only civil archive at the time of printing and are reproducible from the same system.
      Any alteration of this sheet invalidates it.
    </p>

    <div class="report-signatures">
      <div class="sig-block">
        <small>Prepared by (Registering Officer)</small>
      </div>
      <div class="stamp">Official stamp</div>
      <div class="sig-block">
        <small>Approved by (Supervisor / Registrar)</small>
      </div>
    </div>

    <p class="report-footnote" style="border-top:0; padding-top:12px; margin-top:14px; font-size:11px;">
      Report <?= e($reference) ?> &middot; period <?= e($periodLabel) ?> &middot; generated
      <?= e($printedAt) ?> for <?= e((string) $user['full_name']) ?> (<?= e((string) $user['role_label']) ?>).
      Print recorded in the audit trail.
    </p>
  </div>
</body>
</html>
<?php
// Written last, so the audit entry records a report that actually rendered.
Audit::log('report_print', 'report', 'Printed official report ' . $reference . ' for ' . $periodLabel);